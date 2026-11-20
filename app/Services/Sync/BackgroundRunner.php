<?php

namespace App\Services\Sync;

use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Starts an Artisan command in a separate process and returns at once, so a web
 * request never waits for a Riot sync (which can take minutes because of the
 * rate limit). Output is appended to storage/logs/riot-sync.log.
 *
 * Works on Windows (start /B) and on Linux/macOS (a trailing &).
 */
class BackgroundRunner
{
    public function run(string $artisanCommand): bool
    {
        $php = (new PhpExecutableFinder)->find(false);

        if ($php === false) {
            return false;
        }

        $artisan = base_path('artisan');
        $log = storage_path('logs/riot-sync.log');

        if (PHP_OS_FAMILY === 'Windows') {
            $command = sprintf('start /B "" "%s" "%s" %s >> "%s" 2>&1', $php, $artisan, $artisanCommand, $log);
            $handle = popen($command, 'r');
        } else {
            $command = sprintf('%s %s %s >> %s 2>&1 &', escapeshellarg($php), escapeshellarg($artisan), $artisanCommand, escapeshellarg($log));
            $handle = popen($command, 'r');
        }

        if ($handle === false) {
            return false;
        }

        pclose($handle);

        return true;
    }
}
