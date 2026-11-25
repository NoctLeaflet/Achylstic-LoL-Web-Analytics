<?php

namespace App\Services\Stats;

use App\Models\GameMatch;
use App\Models\MatchParticipant;

/**
 * Wins and losses of all active players together, per lane. Games without a
 * lane (ARAM) and remakes are ignored.
 */
final class RoleWinrates
{
    /**
     * @return list<array{position: string, label: string, wins: int, losses: int, games: int, winrate: float|null}>
     */
    public function build(): array
    {
        $counts = MatchParticipant::query()
            ->join('matches', 'matches.id', '=', 'match_participants.match_id')
            ->join('players', 'players.id', '=', 'match_participants.player_id')
            ->where('players.is_active', true)
            ->where('matches.game_duration', '>=', GameMatch::REMAKE_MAX_DURATION_SECONDS)
            ->whereIn('match_participants.team_position', array_keys(RoleCoverage::ROLES))
            ->groupBy('match_participants.team_position')
            ->toBase()
            ->selectRaw('match_participants.team_position as position, COUNT(*) as games, SUM(match_participants.win) as wins')
            ->get()
            ->keyBy('position');

        $rows = [];

        foreach (RoleCoverage::ROLES as $position => $label) {
            $games = (int) ($counts->get($position)->games ?? 0);
            $wins = (int) ($counts->get($position)->wins ?? 0);

            $rows[] = [
                'position' => $position,
                'label' => $label,
                'wins' => $wins,
                'losses' => $games - $wins,
                'games' => $games,
                'winrate' => $games > 0 ? round($wins / $games * 100, 1) : null,
            ];
        }

        return $rows;
    }
}
