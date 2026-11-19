<?php

namespace App\Services\Stats;

use App\Enums\RankedQueue;
use App\Models\GameMatch;
use App\Models\MatchParticipant;

/**
 * Everything the match detail page shows: the two teams, each participant with
 * the usual derived numbers, and team totals.
 *
 * Participants are listed in lane order (top, jungle, mid, bottom, support).
 * "damage_share" is the share of the team's total damage to champions, which
 * the page draws as a small bar.
 */
final class MatchScoreboard
{
    private const TEAM_NAMES = [100 => 'Tim Biru', 200 => 'Tim Merah'];

    private const POSITION_ORDER = ['TOP' => 0, 'JUNGLE' => 1, 'MIDDLE' => 2, 'BOTTOM' => 3, 'UTILITY' => 4];

    /**
     * @return array{
     *     match: array<string, mixed>,
     *     teams: list<array{team_id: int, name: string, win: bool, kills: int, deaths: int, assists: int, gold: int, damage: int, participants: list<array<string, mixed>>}>
     * }
     */
    public function build(GameMatch $match): array
    {
        $participants = $match->participants()->with('player')->get();
        $minutes = max($match->game_duration, 1) / 60;

        $teams = $participants
            ->groupBy('team_id')
            ->sortKeys()
            ->map(function ($members, $teamId) use ($minutes) {
                $members = $members->sortBy(fn (MatchParticipant $p) => [self::POSITION_ORDER[$p->team_position] ?? 9, $p->id])->values();
                $teamDamage = max((int) $members->sum('total_damage_dealt_to_champions'), 1);

                return [
                    'team_id' => (int) $teamId,
                    'name' => self::TEAM_NAMES[$teamId] ?? "Tim {$teamId}",
                    'win' => (bool) $members->first()->win,
                    'kills' => (int) $members->sum('kills'),
                    'deaths' => (int) $members->sum('deaths'),
                    'assists' => (int) $members->sum('assists'),
                    'gold' => (int) $members->sum('gold_earned'),
                    'damage' => (int) $members->sum('total_damage_dealt_to_champions'),
                    'participants' => $members->map(fn (MatchParticipant $p) => $this->participant($p, $minutes, $teamDamage))->all(),
                ];
            })
            ->values()
            ->all();

        return [
            'match' => [
                'match_id' => $match->match_id,
                'queue' => RankedQueue::fromQueueId($match->queue_id)?->label() ?? "Queue {$match->queue_id}",
                'mode' => $match->game_mode,
                'version' => $match->game_version,
                'duration' => $match->game_duration,
                'started_at' => $match->game_start_at,
                'is_remake' => $match->isRemake(),
            ],
            'teams' => $teams,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function participant(MatchParticipant $p, float $minutes, int $teamDamage): array
    {
        $cs = $p->total_minions_killed + $p->neutral_minions_killed;

        return [
            'player' => $p->player,
            'name' => $p->player?->name ?? $this->riotName($p),
            'riot_id' => $this->riotName($p),
            'champion_id' => $p->champion_id,
            'champion_name' => $p->champion_name,
            'position' => $p->team_position,
            'win' => $p->win,
            'kills' => $p->kills,
            'deaths' => $p->deaths,
            'assists' => $p->assists,
            'kda' => round(($p->kills + $p->assists) / max($p->deaths, 1), 2),
            'cs' => $cs,
            'cs_per_min' => round($cs / $minutes, 1),
            'gold' => $p->gold_earned,
            'damage' => $p->total_damage_dealt_to_champions,
            'damage_share' => round($p->total_damage_dealt_to_champions / $teamDamage * 100, 1),
            'damage_taken' => $p->damage_taken,
            'vision' => $p->vision_score,
            'items' => $p->items ?? [],
        ];
    }

    private function riotName(MatchParticipant $p): string
    {
        if ($p->riot_id_name === null || $p->riot_id_name === '') {
            return 'Unknown';
        }

        return $p->riot_id_tag ? "{$p->riot_id_name}#{$p->riot_id_tag}" : $p->riot_id_name;
    }
}
