<?php

namespace App\Services\Stats;

use App\Enums\MatchQueue;
use App\Models\MatchParticipant;
use Illuminate\Support\Collection;

/**
 * Turns participant rows of tracked players into the flat "game" arrays the
 * game-row component shows: result, K/D/A, KDA, CS/min, kill participation and
 * the 0-100 score with its MVP/ACE badge (MatchScore).
 *
 * The rows need their gameMatch and player relations loaded. Scores and team
 * kills are looked up with two queries for all matches together.
 */
final class GameRows
{
    public function __construct(private readonly MatchScore $scores) {}

    /**
     * @param  Collection<int, MatchParticipant>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function build(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $matchIds = $rows->pluck('match_id')->unique()->values()->all();
        $scores = $this->scores->forMatches($matchIds);
        $teamKills = MatchParticipant::query()
            ->whereIn('match_id', $matchIds)
            ->selectRaw('match_id, team_id, SUM(kills) as kills')
            ->groupBy('match_id', 'team_id')
            ->get()
            ->mapWithKeys(fn ($r) => ["{$r->match_id}:{$r->team_id}" => (int) $r->kills]);

        return $rows->map(function (MatchParticipant $p) use ($scores, $teamKills) {
            $match = $p->gameMatch;
            $minutes = max($match->game_duration, 1) / 60;
            $takedowns = $p->kills + $p->assists;

            return [
                'id' => $p->id,
                'player' => $p->player,
                'match_id' => $match->match_id,
                'queue_id' => $match->queue_id,
                'queue' => MatchQueue::fromQueueId($match->queue_id)?->label() ?? "Queue {$match->queue_id}",
                'started_at' => $match->game_start_at,
                'duration' => $match->game_duration,
                'champion_id' => $p->champion_id,
                'champion_name' => $p->champion_name,
                'win' => $p->win,
                'kills' => $p->kills,
                'deaths' => $p->deaths,
                'assists' => $p->assists,
                'kda' => round($takedowns / max($p->deaths, 1), 2),
                'cs_per_min' => round(($p->total_minions_killed + $p->neutral_minions_killed) / $minutes, 1),
                'kp' => (int) round($takedowns / max($teamKills["{$p->match_id}:{$p->team_id}"] ?? 1, 1) * 100),
                'vision' => $p->vision_score,
                'score' => $scores[$p->id]['score'] ?? 0,
                'badge' => $scores[$p->id]['badge'] ?? null,
            ];
        })->values();
    }
}
