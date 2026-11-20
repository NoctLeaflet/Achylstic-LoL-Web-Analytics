<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Services\Backup\DatabaseBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://discord.com/api/webhooks/123456789012345678/abc-DEF_ghi123';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'achylstic-backup-test-'.uniqid();
        config([
            'backup.directory' => $this->directory,
            'backup.keep' => 3,
            // The test database is SQLite; pretend it is MySQL so the command is allowed to run (mysqldump is faked).
            'database.connections.fake_mysql' => [
                'driver' => 'mysql', 'host' => 'db.example', 'port' => 3307, 'database' => 'achylstic',
                'username' => 'dumper', 'password' => 'secret-pass',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    /** Run $callback with the default connection pointing at the fake MySQL one. */
    private function asMysql(callable $callback): mixed
    {
        $original = config('database.default');
        config(['database.default' => 'fake_mysql']);

        try {
            return $callback();
        } finally {
            config(['database.default' => $original]);
        }
    }

    public function test_it_stores_a_compressed_dump_and_passes_the_password_through_the_environment(): void
    {
        Process::fake(['*' => Process::result('-- SQL DUMP --')]);

        $result = $this->asMysql(fn () => app(DatabaseBackup::class)->run());

        $this->assertFileExists($result['path']);
        $this->assertStringEndsWith('.sql.gz', $result['path']);
        $this->assertSame('-- SQL DUMP --', trim(gzdecode(file_get_contents($result['path']))));

        Process::assertRan(function (PendingProcess $process) {
            $command = implode(' ', (array) $process->command);

            $this->assertStringContainsString('--host=db.example', $command);
            $this->assertStringContainsString('--port=3307', $command);
            $this->assertStringContainsString('--user=dumper', $command);
            $this->assertStringContainsString('achylstic', $command);
            $this->assertStringNotContainsString('secret-pass', $command);   // never on the command line
            $this->assertSame('secret-pass', $process->environment['MYSQL_PWD']);

            return true;
        });
    }

    public function test_only_the_newest_dumps_are_kept(): void
    {
        Process::fake(['*' => Process::result('-- SQL --')]);
        mkdir($this->directory, 0777, true);
        foreach (['20260101-000000', '20260102-000000', '20260103-000000', '20260104-000000'] as $stamp) {
            file_put_contents("{$this->directory}/achylstic-{$stamp}.sql.gz", 'old');
        }

        $this->asMysql(fn () => app(DatabaseBackup::class)->run());

        $files = array_map('basename', glob($this->directory.'/*'));
        $this->assertCount(3, $files);
        $this->assertNotContains('achylstic-20260101-000000.sql.gz', $files);
        $this->assertNotContains('achylstic-20260102-000000.sql.gz', $files);
    }

    public function test_the_command_reports_the_file(): void
    {
        Process::fake(['*' => Process::result('-- SQL --')]);
        $original = config('database.default');
        config(['database.default' => 'fake_mysql']);

        $this->artisan('db:backup')->expectsOutputToContain('Backup tersimpan')->assertSuccessful();

        config(['database.default' => $original]);
    }

    public function test_a_failing_dump_leaves_no_file_and_fails_the_command(): void
    {
        Process::fake(['*' => Process::result(output: '', errorOutput: 'mysqldump: Got error: Access denied', exitCode: 2)]);
        $original = config('database.default');
        config(['database.default' => 'fake_mysql']);

        $this->artisan('db:backup')->expectsOutputToContain('Access denied')->assertFailed();

        config(['database.default' => $original]);
        $this->assertSame([], glob($this->directory.'/*') ?: []);
    }

    public function test_a_failed_backup_is_reported_to_discord_without_the_password(): void
    {
        config(['services.discord.webhook_url' => self::WEBHOOK]);
        Http::fake([self::WEBHOOK => Http::response('', 204)]);
        Process::fake(['*' => Process::result(output: '', errorOutput: 'mysqldump: not found', exitCode: 127)]);
        $original = config('database.default');
        config(['database.default' => 'fake_mysql']);

        $this->artisan('db:backup')->assertFailed();

        config(['database.default' => $original]);
        Http::assertSent(function (Request $request) {
            $this->assertStringContainsString('Backup database gagal', json_encode($request->data()));
            $this->assertStringNotContainsString('secret-pass', json_encode($request->data()));

            return true;
        });
    }

    public function test_sqlite_is_refused_with_a_clear_message(): void
    {
        Process::fake();

        $this->artisan('db:backup')->expectsOutputToContain('hanya didukung untuk MySQL')->assertFailed();

        Process::assertNothingRan();
    }

    public function test_the_admin_page_shows_the_latest_backup(): void
    {
        $this->withoutVite();
        $this->actingAs(Admin::create(['username' => 'boss', 'password' => 'throwaway-pass-123']), 'admin');

        $this->get('/admin')->assertOk()->assertSee('Backup database terakhir')->assertSee('belum ada');

        mkdir($this->directory, 0777, true);
        file_put_contents($this->directory.'/achylstic-20260101-000000.sql.gz', str_repeat('x', 2048));

        $this->get('/admin')->assertSee('2 KB');
    }
}
