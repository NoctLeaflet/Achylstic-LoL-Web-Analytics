<?php

namespace App\Services\Riot;

use App\Models\Player;
use Illuminate\Support\Carbon;

/**
 * spectator-v5: is a player in a game right now?
 */
class SpectatorService
{
    public function __construct(private readonly RiotClient $riot) {}

    /**
     * The game the player is in, or null when they are not playing (Riot answers 404).
     *
     * @return array{game_id: int, queue_id: int|null, champion_id: int|null, started_at: Carbon}|null
     */
    public function activeGame(Player $player): ?array
    {
        $game = $this->riot->platform(
            '/lol/spectator/v5/active-games/by-summoner/'.rawurlencode($player->puuid),
            [],
            $player->platform,
        );

        if ($game === null || ! isset($game['gameId'])) {
            return null;
        }

        $me = collect($game['participants'] ?? [])->firstWhere('puuid', $player->puuid);
        $startMs = (int) ($game['gameStartTime'] ?? 0);

        return [
            'game_id' => (int) $game['gameId'],
            'queue_id' => isset($game['gameQueueConfigId']) ? (int) $game['gameQueueConfigId'] : null,
            'champion_id' => isset($me['championId']) ? (int) $me['championId'] : null,
            // gameStartTime is 0 while the game is still loading.
            'started_at' => $startMs > 0 ? Carbon::createFromTimestampMs($startMs) : now(),
        ];
    }
}
