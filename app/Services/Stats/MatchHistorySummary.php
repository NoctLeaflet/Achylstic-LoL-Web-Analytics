<?php

namespace App\Services\Stats;

use App\Models\MatchParticipant;
use Illuminate\Support\Collection;

/**
 * The side panels of the match list: most played champions and roles over a set
 * of participant rows (already filtered by player and mode, remakes excluded).
 *
 * Rows need their player relation loaded; rows without a role (ARAM) only count
 * for champions.
 */
final class MatchHistorySummary
{
    public const TOP_CHAMPIONS = 5;

    /**
     * @param  Collection<int, MatchParticipant>  $rows
     * @return array{champions: list<array<string, mixed>>, roles: list<array<string, mixed>>}
     */
    public function build(Collection $rows): array
    {
        return ['champions' => $this->champions($rows), 'roles' => $this->roles($rows)];
    }

    /**
     * @param  Collection<int, MatchParticipant>  $rows
     * @return list<array<string, mixed>>
     */
    private function champions(Collection $rows): array
    {
        return $rows->groupBy('champion_id')
            ->map(function (Collection $games) {
                $deaths = max((int) $games->sum('deaths'), 1);

                return [
                    'champion_id' => $games->first()->champion_id,
                    'champion_name' => $games->first()->champion_name,
                    'games' => $games->count(),
                    'kda' => round(($games->sum('kills') + $games->sum('assists')) / $deaths, 1),
                    'winrate' => (int) round($games->where('win', true)->count() / $games->count() * 100),
                    // The tracked players who played it most.
                    'players' => $games->countBy(fn ($g) => $g->player->name)->sortDesc()->keys()->take(3)->all(),
                ];
            })
            ->sort(fn ($a, $b) => [$b['games'], $a['champion_name']] <=> [$a['games'], $b['champion_name']])
            ->take(self::TOP_CHAMPIONS)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, MatchParticipant>  $rows
     * @return list<array<string, mixed>>
     */
    private function roles(Collection $rows): array
    {
        $withRole = $rows->whereIn('team_position', array_keys(RoleCoverage::ROLES));
        $total = max($withRole->count(), 1);

        return collect(RoleCoverage::ROLES)
            ->map(function (string $label, string $position) use ($withRole, $total) {
                $games = $withRole->where('team_position', $position);

                return [
                    'position' => $position,
                    'label' => $label,
                    'games' => $games->count(),
                    'share' => (int) round($games->count() / $total * 100),
                    'wins' => $games->where('win', true)->count(),
                    'losses' => $games->where('win', false)->count(),
                    'winrate' => $games->isEmpty() ? null : (int) round($games->where('win', true)->count() / $games->count() * 100),
                ];
            })
            ->sortByDesc('games')
            ->values()
            ->all();
    }
}
