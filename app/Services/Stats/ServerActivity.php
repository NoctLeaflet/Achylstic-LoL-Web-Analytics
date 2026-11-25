<?php

namespace App\Services\Stats;

use App\Models\GameMatch;
use App\Models\MatchParticipant;
use Illuminate\Support\Carbon;

/**
 * When the group plays, in Indonesian time (WIB): games per weekday and hour,
 * and per hour of the day, each with its wins. Every participating friend counts
 * once per match (five friends in one game are five "main").
 */
final class ServerActivity
{
    public const TIMEZONE = 'Asia/Jakarta';

    /** Monday first, matching the heatmap rows. */
    public const DAYS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

    /**
     * @return array{
     *     cells: array<int, array<int, array{games: int, wins: int}>>,
     *     hours: array<int, array{games: int, wins: int}>,
     *     total: int,
     *     busiest: array{day: string, hour: int, games: int}|null,
     *     max_cell: int
     * }
     */
    public function build(): array
    {
        $empty = ['games' => 0, 'wins' => 0];
        $cells = array_fill(0, 7, array_fill(0, 24, $empty));
        $hours = array_fill(0, 24, $empty);
        $total = 0;
        $busiest = null;
        $maxCell = 0;

        $rows = MatchParticipant::query()
            ->join('matches', 'matches.id', '=', 'match_participants.match_id')
            ->join('players', 'players.id', '=', 'match_participants.player_id')
            ->where('players.is_active', true)
            ->where('matches.game_duration', '>=', GameMatch::REMAKE_MAX_DURATION_SECONDS)
            ->toBase()
            ->get(['matches.game_start_at as started_at', 'match_participants.win as win']);

        foreach ($rows as $row) {
            $local = Carbon::parse($row->started_at, 'UTC')->setTimezone(self::TIMEZONE);
            $day = $local->dayOfWeekIso - 1;
            $hour = $local->hour;
            $win = (int) $row->win;

            $cells[$day][$hour]['games']++;
            $cells[$day][$hour]['wins'] += $win;
            $hours[$hour]['games']++;
            $hours[$hour]['wins'] += $win;
            $total++;
        }

        foreach ($cells as $day => $perHour) {
            foreach ($perHour as $hour => $cell) {
                $maxCell = max($maxCell, $cell['games']);

                if ($cell['games'] > ($busiest['games'] ?? 0)) {
                    $busiest = ['day' => self::DAYS[$day], 'hour' => $hour, 'games' => $cell['games']];
                }
            }
        }

        return ['cells' => $cells, 'hours' => $hours, 'total' => $total, 'busiest' => $busiest, 'max_cell' => $maxCell];
    }
}
