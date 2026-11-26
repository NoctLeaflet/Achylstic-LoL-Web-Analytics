<?php

namespace App\Listeners;

use App\Events\MatchRecorded;
use App\Models\GameMatch;
use App\Models\MatchParticipant;
use App\Services\Discord\DiscordMessages;
use App\Services\Discord\DiscordNotifier;
use App\Services\Stats\WinStreak;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Announces a freshly recorded match in Discord, together with any win streak
 * milestone it completes. One message per match: the match is only recorded
 * once, so nothing is ever announced twice.
 */
class NotifyDiscordOfMatch
{
    public function __construct(
        private readonly DiscordNotifier $notifier,
        private readonly DiscordMessages $messages,
        private readonly WinStreak $streaks,
    ) {}

    public function handle(MatchRecorded $event): void
    {
        $match = $event->match;

        if (! $this->notifier->enabled() || $match->isRemake() || $this->isOld($match)) {
            return;
        }

        try {
            $embeds = $this->messages->matchCards($match);

            if ($embeds === []) {
                return;
            }

            $winners = $match->participants()->whereNotNull('player_id')->where('win', true)->with('player')->get();

            /** @var MatchParticipant $participant */
            foreach ($winners as $participant) {
                $streak = $this->streaks->endingAt($participant->player_id, $match);

                if ($this->streaks->isMilestone($streak)) {
                    $embeds[] = $this->messages->winStreak($participant->player, $streak);
                }
            }

            // A message holds at most 10 cards; a game with many friends and streaks needs a second one.
            foreach (array_chunk($embeds, DiscordNotifier::MAX_EMBEDS) as $chunk) {
                $this->notifier->send($chunk);
            }
        } catch (Throwable $e) {
            // A notification problem must never stop the sync.
            Log::warning('Could not send the Discord match notification', ['exception' => $e::class]);
        }
    }

    /** Matches from before the cut-off are history (e.g. a newly added player), not news. */
    private function isOld(GameMatch $match): bool
    {
        $endedAt = $match->game_start_at->copy()->addSeconds($match->game_duration);

        return $endedAt->lt(now()->subMinutes((int) config('services.discord.max_match_age_minutes')));
    }
}
