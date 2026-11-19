<?php

namespace App\Services\Riot;

use App\Enums\RankedQueue;
use App\Events\MatchRecorded;
use App\Models\GameMatch;
use App\Models\Player;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * match-v5: match id lists and match details.
 *
 * A finished match never changes, so a match id that already exists in the
 * database is never fetched again.
 */
class MatchService
{
    public function __construct(private readonly RiotClient $riot) {}

    /**
     * Most recent match ids of a player, newest first.
     *
     * @return list<string>
     */
    public function fetchMatchIds(string $puuid, int $count = 20, ?int $queueId = null, int $start = 0): array
    {
        $query = ['start' => $start, 'count' => $count];

        if ($queueId !== null) {
            $query = ['queue' => $queueId] + $query;
        }

        $ids = $this->riot->region('/lol/match/v5/matches/by-puuid/'.rawurlencode($puuid).'/ids', $query);

        return array_values(array_filter($ids ?? [], 'is_string'));
    }

    /**
     * @return array<string, mixed>|null raw match-v5 payload, null when Riot no longer has the match
     */
    public function fetchMatch(string $matchId): ?array
    {
        return $this->riot->region('/lol/match/v5/matches/'.rawurlencode($matchId));
    }

    /**
     * Fetch and store the new ranked matches (solo and flex) of one player.
     * $count applies to each queue separately.
     *
     * @return int number of matches stored
     */
    public function syncPlayer(Player $player, int $count = 20): int
    {
        $stored = 0;
        $playerIdsByPuuid = Player::pluck('id', 'puuid')->all();

        foreach (RankedQueue::cases() as $queue) {
            $ids = $this->fetchMatchIds($player->puuid, $count, $queue->queueId());

            if ($ids === []) {
                continue;
            }

            $known = GameMatch::whereIn('match_id', $ids)->pluck('match_id')->all();

            // Riot lists the newest match first. Store them oldest first so that
            // notifications and win streaks follow the order the games were played.
            foreach (array_reverse(array_values(array_diff($ids, $known))) as $matchId) {
                $payload = $this->fetchMatch($matchId);

                if ($payload !== null && $this->store($payload, $playerIdsByPuuid) !== null) {
                    $stored++;
                }
            }
        }

        return $stored;
    }

    /**
     * Persist a match and all of its participants. Participants that belong to
     * one of our players get their player_id filled in.
     *
     * @param  array<string, mixed>  $payload  raw match-v5 response
     * @param  array<string, int>  $playerIdsByPuuid
     * @return GameMatch|null null when the payload is malformed or the match was stored concurrently
     */
    public function store(array $payload, array $playerIdsByPuuid = []): ?GameMatch
    {
        $matchId = $payload['metadata']['matchId'] ?? null;
        $info = $payload['info'] ?? null;

        if (! is_string($matchId) || ! is_array($info) || empty($info['participants'])) {
            Log::warning('Skipping malformed Riot match payload', ['match_id' => is_string($matchId) ? $matchId : null]);

            return null;
        }

        try {
            $match = DB::transaction(function () use ($matchId, $info, $playerIdsByPuuid) {
                $match = GameMatch::create([
                    'match_id' => $matchId,
                    'queue_id' => (int) ($info['queueId'] ?? 0),
                    'game_version' => $info['gameVersion'] ?? null,
                    'game_mode' => $info['gameMode'] ?? null,
                    'game_duration' => (int) ($info['gameDuration'] ?? 0),
                    'game_start_at' => Carbon::createFromTimestampMs((int) ($info['gameStartTimestamp'] ?? 0)),
                ]);

                $match->participants()->createMany(
                    array_map(fn (array $participant) => $this->participantRow($participant, $playerIdsByPuuid), $info['participants'])
                );

                return $match;
            });
        } catch (UniqueConstraintViolationException) {
            // Another process stored this match first; the data is identical.
            return null;
        }

        // After the commit, so listeners see the match with all its participants.
        MatchRecorded::dispatch($match);

        return $match;
    }

    /**
     * @param  array<string, mixed>  $p  one entry of info.participants
     * @param  array<string, int>  $playerIdsByPuuid
     * @return array<string, mixed>
     */
    private function participantRow(array $p, array $playerIdsByPuuid): array
    {
        $puuid = (string) ($p['puuid'] ?? '');

        return [
            'player_id' => $playerIdsByPuuid[$puuid] ?? null,
            'puuid' => $puuid,
            'riot_id_name' => $p['riotIdGameName'] ?? $p['summonerName'] ?? null,
            'riot_id_tag' => $p['riotIdTagline'] ?? null,
            'champion_id' => (int) ($p['championId'] ?? 0),
            'champion_name' => (string) ($p['championName'] ?? ''),
            'team_id' => (int) ($p['teamId'] ?? 0),
            'team_position' => ($p['teamPosition'] ?? '') ?: null,
            'win' => (bool) ($p['win'] ?? false),
            'kills' => (int) ($p['kills'] ?? 0),
            'deaths' => (int) ($p['deaths'] ?? 0),
            'assists' => (int) ($p['assists'] ?? 0),
            'total_minions_killed' => (int) ($p['totalMinionsKilled'] ?? 0),
            'neutral_minions_killed' => (int) ($p['neutralMinionsKilled'] ?? 0),
            'gold_earned' => (int) ($p['goldEarned'] ?? 0),
            'total_damage_dealt_to_champions' => (int) ($p['totalDamageDealtToChampions'] ?? 0),
            'damage_taken' => (int) ($p['totalDamageTaken'] ?? 0),
            'vision_score' => (int) ($p['visionScore'] ?? 0),
            'wards_placed' => (int) ($p['wardsPlaced'] ?? 0),
            'items' => array_map(fn (int $slot) => (int) ($p["item{$slot}"] ?? 0), range(0, 6)),
            'summoner_spells' => [(int) ($p['summoner1Id'] ?? 0), (int) ($p['summoner2Id'] ?? 0)],
            'perks' => $p['perks'] ?? null,
        ];
    }
}
