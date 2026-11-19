<?php

namespace App\Listeners;

use App\Events\RankChanged;
use App\Services\Discord\DiscordMessages;
use App\Services\Discord\DiscordNotifier;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Announces a promotion or demotion in Discord.
 */
class NotifyDiscordOfRankChange
{
    public function __construct(
        private readonly DiscordNotifier $notifier,
        private readonly DiscordMessages $messages,
    ) {}

    public function handle(RankChanged $event): void
    {
        if (! $this->notifier->enabled()) {
            return;
        }

        try {
            $this->notifier->send([$this->messages->rankChange($event)]);
        } catch (Throwable $e) {
            // A notification problem must never stop the sync.
            Log::warning('Could not send the Discord rank notification', ['exception' => $e::class]);
        }
    }
}
