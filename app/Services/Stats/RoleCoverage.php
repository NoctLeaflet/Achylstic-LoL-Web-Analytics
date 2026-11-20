<?php

namespace App\Services\Stats;

use App\Models\MatchParticipant;
use App\Models\Player;

/**
 * Who plays which role, over every stored match (remakes excluded).
 *
 * A player is a "main" of a role when at least MAIN_SHARE of their games were
 * played there, and a "fill" when it is at least FILL_SHARE; both need MIN_GAMES
 * games in that role. Roles without any main are the gaps of the group. Games
 * without a role (ARAM) are ignored.
 */
final class RoleCoverage
{
    public const ROLES = ['TOP' => 'Top', 'JUNGLE' => 'Jungle', 'MIDDLE' => 'Mid', 'BOTTOM' => 'Bot', 'UTILITY' => 'Support'];

    public const MAIN_SHARE = 0.30;

    public const FILL_SHARE = 0.10;

    public const MIN_GAMES = 2;

    /**
     * @return list<array{position: string, label: string, mains: list<array{player: Player, games: int}>, fills: list<array{player: Player, games: int}>}>
     */
    public function build(): array
    {
        $counts = MatchParticipant::query()
            ->whereNotNull('player_id')
            ->whereIn('team_position', array_keys(self::ROLES))
            ->whereHas('player', fn ($q) => $q->where('is_active', true))
            ->whereHas('gameMatch', fn ($q) => $q->withoutRemakes())
            ->selectRaw('player_id, team_position, COUNT(*) as games')
            ->groupBy('player_id', 'team_position')
            ->get();

        $players = Player::active()->get()->keyBy('id');
        $totals = $counts->groupBy('player_id')->map(fn ($rows) => (int) $rows->sum('games'));

        $roles = [];

        foreach (self::ROLES as $position => $label) {
            $mains = [];
            $fills = [];

            foreach ($counts->where('team_position', $position) as $row) {
                $games = (int) $row->games;
                $share = $games / max($totals[$row->player_id], 1);
                $entry = ['player' => $players[$row->player_id], 'games' => $games];

                if ($games < self::MIN_GAMES) {
                    continue;
                }

                if ($share >= self::MAIN_SHARE) {
                    $mains[] = $entry;
                } elseif ($share >= self::FILL_SHARE) {
                    $fills[] = $entry;
                }
            }

            $byGames = fn (array $a, array $b) => [$b['games'], $a['player']->name] <=> [$a['games'], $b['player']->name];
            usort($mains, $byGames);
            usort($fills, $byGames);

            $roles[] = ['position' => $position, 'label' => $label, 'mains' => $mains, 'fills' => $fills];
        }

        return $roles;
    }
}
