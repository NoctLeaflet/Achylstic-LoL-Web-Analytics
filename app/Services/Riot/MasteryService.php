<?php

namespace App\Services\Riot;

use App\Models\ChampionMastery;
use App\Models\Player;
use App\Services\Riot\Exceptions\RiotApiException;
use Illuminate\Support\Carbon;

/**
 * champion-mastery-v4: the champions a player has the most mastery points on.
 */
class MasteryService
{
    /** How many champions are kept per player. */
    public const TOP = 10;

    public function __construct(private readonly RiotClient $riot) {}

    /**
     * Fetch the player's top champions and replace what is stored.
     *
     * @return int champions stored
     *
     * @throws RiotApiException
     */
    public function sync(Player $player): int
    {
        $rows = $this->riot->platform(
            '/lol/champion-mastery/v4/champion-masteries/by-puuid/'.rawurlencode($player->puuid).'/top',
            ['count' => self::TOP],
            $player->platform,
        );

        if ($rows === null) {
            return 0;
        }

        $kept = [];

        foreach ($rows as $row) {
            if (! isset($row['championId'])) {
                continue;
            }

            $lastPlayMs = (int) ($row['lastPlayTime'] ?? 0);
            $kept[] = (int) $row['championId'];

            ChampionMastery::updateOrCreate(
                ['player_id' => $player->id, 'champion_id' => (int) $row['championId']],
                [
                    'level' => (int) ($row['championLevel'] ?? 0),
                    'points' => (int) ($row['championPoints'] ?? 0),
                    'last_played_at' => $lastPlayMs > 0 ? Carbon::createFromTimestampMs($lastPlayMs) : null,
                ],
            );
        }

        // Champions that dropped out of the top are forgotten.
        ChampionMastery::where('player_id', $player->id)->whereNotIn('champion_id', $kept)->delete();

        return count($kept);
    }
}
