<?php

namespace App\Console\Commands;

use App\Services\Backup\DatabaseBackup;
use App\Services\Discord\DiscordMessages;
use App\Services\Discord\DiscordNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

#[Signature('db:backup')]
#[Description('Dump the database to a compressed file in storage/app/backups and delete the oldest dumps')]
class DatabaseBackupCommand extends Command
{
    public function handle(DatabaseBackup $backup, DiscordNotifier $notifier, DiscordMessages $messages): int
    {
        try {
            $result = $backup->run();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            Log::error('Database backup failed', ['reason' => $e->getMessage()]);

            // A backup that silently stops working is worse than none: say so in Discord.
            if ($notifier->enabled()) {
                $notifier->send([$messages->backupFailed($e->getMessage())]);
            }

            return self::FAILURE;
        }

        $this->info(sprintf('Backup tersimpan: %s (%s KB)', $result['path'], number_format($result['size'] / 1024, 0, ',', '.')));

        return self::SUCCESS;
    }
}
