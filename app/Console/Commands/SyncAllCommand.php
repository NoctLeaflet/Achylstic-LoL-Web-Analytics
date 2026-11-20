<?php

namespace App\Console\Commands;

use App\Services\Sync\SyncStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('riot:sync-all')]
#[Description('Run the rank, match and timeline syncs one after another (used by the "sync now" button of the admin page)')]
class SyncAllCommand extends Command
{
    public function handle(SyncStatus $status): int
    {
        $status->running();

        try {
            foreach (['riot:sync-ranks', 'riot:sync-matches', 'riot:sync-timelines'] as $command) {
                $this->info("== {$command}");

                if ($this->call($command) !== self::SUCCESS) {
                    // Most often an expired API key; the command already printed why.
                    $status->failed("{$command} gagal. Cek API key Riot dan storage/logs/riot-sync.log.");

                    return self::FAILURE;
                }
            }
        } catch (Throwable $e) {
            $status->failed('Terjadi error: '.$e::class);

            return self::FAILURE;
        }

        // Champion mastery is a nice extra: when it fails, the sync that really matters (ranks, matches,
        // timelines) still counts as done, and the admin page says so.
        $this->info('== riot:sync-mastery');
        $mastery = $this->call('riot:sync-mastery') === self::SUCCESS;

        $status->done($mastery ? null : 'Champion mastery gagal diambil (rank, match, dan timeline berhasil).');

        return self::SUCCESS;
    }
}
