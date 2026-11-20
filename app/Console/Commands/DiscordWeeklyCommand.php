<?php

namespace App\Console\Commands;

use App\Enums\RankedQueue;
use App\Services\Discord\DiscordMessages;
use App\Services\Discord\DiscordNotifier;
use App\Services\Stats\ClimberOfTheWeek;
use App\Services\Stats\WeeklyHighlights;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('discord:weekly')]
#[Description('Send the weekly summary (climber, standout games, awards) to the Discord webhook')]
class DiscordWeeklyCommand extends Command
{
    public function handle(DiscordNotifier $notifier, DiscordMessages $messages, WeeklyHighlights $weekly, ClimberOfTheWeek $climbers): int
    {
        if (! $notifier->enabled()) {
            $this->error('DISCORD_WEBHOOK_URL belum diisi atau bukan URL webhook Discord yang valid.');

            return self::FAILURE;
        }

        // The climber is the biggest LP gain of Solo/Duo, or of Flex when nobody gained in Solo/Duo.
        $queue = RankedQueue::Solo;
        $climber = $climbers->climber($queue);

        if ($climber === null) {
            $queue = RankedQueue::Flex;
            $climber = $climbers->climber($queue);
        }

        $embed = $messages->weekly($weekly->build(), $climber, $queue);

        if ($embed === null) {
            $this->info('Tidak ada game minggu ini, jadi tidak ada yang dikirim.');

            return self::SUCCESS;
        }

        if (! $notifier->send([$embed])) {
            $this->error('Pesan gagal terkirim (detail di storage/logs/laravel.log).');

            return self::FAILURE;
        }

        $this->info('Rangkuman mingguan terkirim.');

        return self::SUCCESS;
    }
}
