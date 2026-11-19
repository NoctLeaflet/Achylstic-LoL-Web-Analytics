<?php

namespace Tests\Support;

/**
 * Builders for fake Riot API payloads, shaped like the real responses.
 * Tests must never call the real Riot API; use these with Http::fake().
 */
final class RiotFixtures
{
    /**
     * A match-v5 response with 10 participants (team 100 wins, team 200 loses).
     *
     * @param  list<string>  $puuids  puuids for the first participants; the rest are generated
     * @param  array<string, mixed>  $info  overrides for the "info" block
     * @return array<string, mixed>
     */
    public static function match(string $matchId = 'SG2_1001', array $puuids = [], array $info = [], int $queueId = 420): array
    {
        $positions = ['TOP', 'JUNGLE', 'MIDDLE', 'BOTTOM', 'UTILITY'];
        $participants = [];
        $allPuuids = [];

        for ($i = 0; $i < 10; $i++) {
            $puuid = $puuids[$i] ?? "puuid-{$matchId}-{$i}";
            $teamId = $i < 5 ? 100 : 200;
            $allPuuids[] = $puuid;

            $participants[] = [
                'puuid' => $puuid,
                'riotIdGameName' => "Player{$i}",
                'riotIdTagline' => 'SG2',
                'championId' => 100 + $i,
                'championName' => "Champ{$i}",
                'teamId' => $teamId,
                'teamPosition' => $positions[$i % 5],
                'win' => $teamId === 100,
                'kills' => $i + 1,
                'deaths' => 2,
                'assists' => 5,
                'totalMinionsKilled' => 150 + $i,
                'neutralMinionsKilled' => 10,
                'goldEarned' => 10000 + $i * 100,
                'totalDamageDealtToChampions' => 20000 + $i * 500,
                'totalDamageTaken' => 15000,
                'visionScore' => 25,
                'wardsPlaced' => 10,
                'item0' => 3020, 'item1' => 6655, 'item2' => 0, 'item3' => 0, 'item4' => 0, 'item5' => 0, 'item6' => 3340,
                'summoner1Id' => 4,
                'summoner2Id' => 14,
                'perks' => [
                    'statPerks' => ['defense' => 5001, 'flex' => 5008, 'offense' => 5005],
                    'styles' => [['description' => 'primaryStyle', 'style' => 8100, 'selections' => []]],
                ],
            ];
        }

        return [
            'metadata' => ['matchId' => $matchId, 'participants' => $allPuuids],
            'info' => array_merge([
                'gameVersion' => '16.1.612.1234',
                'gameMode' => 'CLASSIC',
                'gameDuration' => 1800,
                'gameStartTimestamp' => 1763510400000,
                'queueId' => $queueId,
                'participants' => $participants,
            ], $info),
        ];
    }

    /**
     * One league-v4 entry.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function leagueEntry(string $queueType = 'RANKED_SOLO_5x5', array $overrides = []): array
    {
        return array_merge([
            'queueType' => $queueType,
            'tier' => 'GOLD',
            'rank' => 'II',
            'leaguePoints' => 54,
            'wins' => 10,
            'losses' => 8,
        ], $overrides);
    }

    /**
     * A summoner-v4 response.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function summoner(array $overrides = []): array
    {
        return array_merge([
            'profileIconId' => 4567,
            'summonerLevel' => 123,
        ], $overrides);
    }
}
