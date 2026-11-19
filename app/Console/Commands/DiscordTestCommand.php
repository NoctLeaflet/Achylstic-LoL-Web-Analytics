<?php

namespace App\Console\Commands;

use App\Services\Discord\DiscordMessages;
use App\Services\Discord\DiscordNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('discord:test')]
#[Description('Send a test message to the configured Discord webhook')]
class DiscordTestCommand extends Command
{
    public function handle(DiscordNotifier $notifier, DiscordMessages $messages): int
    {
        if (! $notifier->enabled()) {
            $this->error('DISCORD_WEBHOOK_URL belum diisi atau bukan URL webhook Discord yang valid.');
            $this->line('Buat webhook di Discord: Server Settings > Integrations > Webhooks > New Webhook > Copy Webhook URL,');
            $this->line('lalu isi DISCORD_WEBHOOK_URL di file .env.');

            return self::FAILURE;
        }

        if (! $notifier->send([$messages->test()])) {
            $this->error('Pesan gagal terkirim. Cek apakah webhook masih ada di Discord (detail di storage/logs/laravel.log).');

            return self::FAILURE;
        }

        $this->info('Pesan tes terkirim. Cek channel Discord-nya.');

        return self::SUCCESS;
    }
}
