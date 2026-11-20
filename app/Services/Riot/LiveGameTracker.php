<?php

namespace App\Services\Riot;

use App\Models\LiveGame;
use App\Models\Player;
use App\Services\Discord\DiscordMessages;
use App\Services\Discord\DiscordNotifier;
use App\Services\Riot\Exceptions\RiotApiException;

/**
 * Keeps the live_games table in step with Riot's spectator API and tells Discord
 * when friends start a game.
 *
 * The pages only read live_games (never Riot). A game that was already running
 * for more than NEWS_MAX_AGE_MINUTES when first seen is not news (for example
 * right after the sync was switched on) and is stored as announced.
 */
class LiveGameTracker
{
    public const NEWS_MAX_AGE_MINUTES = 10;

    public function __construct(
        private readonly SpectatorService $spectator,
        private readonly DiscordNotifier $notifier,
        private readonly DiscordMessages $messages,
    ) {}

    /**
     * Ask Riot whether the player is in a game and update the table.
     *
     * @throws RiotApiException
     */
    public function refresh(Player $player): ?LiveGame
    {
        $game = $this->spectator->activeGame($player);

        if ($game === null) {
            LiveGame::where('player_id', $player->id)->delete();

            return null;
        }

        $existing = LiveGame::where('player_id', $player->id)->first();

        if ($existing !== null && $existing->game_id === $game['game_id']) {
            $existing->update(['last_seen_at' => now()]);

            return $existing;
        }

        return LiveGame::updateOrCreate(['player_id' => $player->id], [
            'game_id' => $game['game_id'],
            'queue_id' => $game['queue_id'],
            'champion_id' => $game['champion_id'],
            'started_at' => $game['started_at'],
            'last_seen_at' => now(),
            'announced_at' => $game['started_at']->lt(now()->subMinutes(self::NEWS_MAX_AGE_MINUTES)) ? now() : null,
        ]);
    }

    /**
     * Send one Discord message per game that friends just started.
     *
     * @return int messages sent
     */
    public function announce(): int
    {
        $sent = 0;
        $pending = LiveGame::current()->whereNull('announced_at')->with('player')->get()->groupBy('game_id');

        foreach ($pending as $rows) {
            if (! $this->notifier->enabled()) {
                // Nobody to tell; do not keep them pending for a webhook that is added later.
                LiveGame::whereIn('id', $rows->modelKeys())->update(['announced_at' => now()]);

                continue;
            }

            if ($this->notifier->send([$this->messages->liveGame($rows)])) {
                LiveGame::whereIn('id', $rows->modelKeys())->update(['announced_at' => now()]);
                $sent++;
            }
        }

        return $sent;
    }
}
