<?php

namespace App\Listeners;

use App\Events\MatchRecorded;
use App\Events\RankChanged;
use App\Models\Player;
use App\Services\Achievements\AchievementTracker;
use App\Services\Discord\DiscordMessages;
use App\Services\Discord\DiscordNotifier;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * After a new match or a rank change, checks the achievements of the players
 * involved and tells Discord about the ones that are new.
 *
 * Achievements are always stored (the player page reads them); Discord is only
 * told about recent ones, so the history of a newly added player stays quiet.
 */
class AnnounceAchievements
{
    public function __construct(
        private readonly AchievementTracker $tracker,
        private readonly DiscordNotifier $notifier,
        private readonly DiscordMessages $messages,
    ) {}

    public function handleMatchRecorded(MatchRecorded $event): void
    {
        $players = Player::query()
            ->whereIn('id', $event->match->participants()->whereNotNull('player_id')->pluck('player_id'))
            ->get();

        foreach ($players as $player) {
            $this->check($player);
        }
    }

    public function handleRankChanged(RankChanged $event): void
    {
        $this->check($event->player);
    }

    private function check(Player $player): void
    {
        try {
            $news = $this->tracker->sync($player);

            if ($news === [] || ! $this->notifier->enabled()) {
                return;
            }

            if ($this->notifier->send([$this->messages->achievements($player, $news)])) {
                $this->tracker->markAnnounced($news);
            }
        } catch (Throwable $e) {
            // Achievements must never stop a sync.
            Log::warning('Could not check the achievements', ['exception' => $e::class]);
        }
    }
}
