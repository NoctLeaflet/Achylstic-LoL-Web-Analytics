<?php

namespace App\Services\Backup;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Dumps the MySQL database with mysqldump into a gzip file, and keeps only the
 * newest few dumps.
 *
 * The LP history only exists in this database (Riot does not keep it), so a
 * backup is the one thing that cannot be rebuilt. The password is handed to
 * mysqldump through the environment, never on the command line, and nothing
 * that could contain it is logged.
 *
 * The dump is read into memory before it is compressed, which is fine for this
 * app (a few MB); a database of gigabytes would need streaming.
 */
class DatabaseBackup
{
    private const PREFIX = 'achylstic-';

    private const SUFFIX = '.sql.gz';

    /**
     * @return array{path: string, size: int}
     *
     * @throws RuntimeException when the dump fails; the message is safe to show
     */
    public function run(): array
    {
        $connection = config('database.connections.'.config('database.default'));

        if (! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Backup hanya didukung untuk MySQL/MariaDB.');
        }

        $directory = (string) config('backup.directory');

        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException("Folder backup tidak bisa dibuat: {$directory}");
        }

        $result = Process::timeout(300)
            ->env(['MYSQL_PWD' => (string) ($connection['password'] ?? '')])
            ->run([
                (string) config('backup.mysqldump'),
                '--host='.($connection['host'] ?? '127.0.0.1'),
                '--port='.($connection['port'] ?? 3306),
                '--user='.($connection['username'] ?? 'root'),
                '--single-transaction',
                '--routines',
                '--no-tablespaces',
                '--default-character-set=utf8mb4',
                (string) $connection['database'],
            ]);

        if ($result->failed() || trim($result->output()) === '') {
            // mysqldump's own error text does not contain the password (it was never on the command line).
            $reason = trim($result->errorOutput()) ?: 'mysqldump tidak menghasilkan data. Cek MYSQLDUMP_PATH.';

            throw new RuntimeException('Backup gagal: '.$reason);
        }

        $path = $directory.DIRECTORY_SEPARATOR.self::PREFIX.now()->format('Ymd-His').self::SUFFIX;

        if (file_put_contents($path, gzencode($result->output(), 9)) === false) {
            throw new RuntimeException("Backup tidak bisa ditulis ke {$path}");
        }

        $this->prune($directory);

        return ['path' => $path, 'size' => (int) filesize($path)];
    }

    /**
     * The newest backup, or null when there is none.
     *
     * @return array{path: string, size: int, at: CarbonInterface}|null
     */
    public function latest(): ?array
    {
        $files = $this->files((string) config('backup.directory'));

        if ($files === []) {
            return null;
        }

        $path = $files[0];

        return ['path' => $path, 'size' => (int) filesize($path), 'at' => now()->setTimestamp((int) filemtime($path))];
    }

    /** Delete everything but the newest "keep" dumps. */
    private function prune(string $directory): void
    {
        foreach (array_slice($this->files($directory), max(1, (int) config('backup.keep'))) as $old) {
            @unlink($old);
        }
    }

    /**
     * Backup files, newest first (the name carries the timestamp).
     *
     * @return list<string>
     */
    private function files(string $directory): array
    {
        $files = glob($directory.DIRECTORY_SEPARATOR.self::PREFIX.'*'.self::SUFFIX) ?: [];
        rsort($files);

        return $files;
    }
}
