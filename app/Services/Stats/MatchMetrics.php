<?php

namespace App\Services\Stats;

use App\Models\MatchParticipant;
use Illuminate\Support\Collection;

/**
 * Data for the "Metrics" pop-up of the match page: gold, damage, xp and cs of
 * all players after every minute of the game, for a line chart.
 *
 * The numbers come from the match timeline (MatchParticipant::$metrics), so a
 * match whose timeline was not fetched has none and this returns null. Every
 * player gets a fixed colour inside the match: blue-ish for the blue team and
 * warm for the red team, so teams are told apart at a glance.
 */
final class MatchMetrics
{
    public const METRICS = ['gold' => 'Gold', 'damage' => 'Damage', 'xp' => 'Exp', 'cs' => 'CS'];

    private const BLUE_COLORS = ['#38bdf8', '#22d3ee', '#818cf8', '#2dd4bf', '#60a5fa'];

    private const RED_COLORS = ['#fb7185', '#fb923c', '#f472b6', '#fbbf24', '#f87171'];

    /**
     * @param  list<array<string, mixed>>  $teams  teams of MatchScoreboard (participants in lane order)
     * @param  Collection<int, MatchParticipant>  $participants
     * @return array{minutes: list<int>, metrics: array<string, string>, players: list<array<string, mixed>>}|null
     */
    public function build(array $teams, Collection $participants): ?array
    {
        $byId = $participants->keyBy('id');
        $players = [];
        $length = 0;

        foreach ($teams as $team) {
            $colors = $team['team_id'] === 100 ? self::BLUE_COLORS : self::RED_COLORS;

            foreach ($team['participants'] as $index => $entry) {
                $series = $byId[$entry['id']]->metrics ?? null;

                if (! is_array($series) || ! isset($series['gold'])) {
                    continue;
                }

                $length = max($length, count($series['gold']));
                $players[] = [
                    'id' => $entry['id'],
                    'name' => $entry['name'],
                    'champion_id' => $entry['champion_id'],
                    'champion_name' => $entry['champion_name'],
                    'team' => $team['team_id'] === 100 ? 'blue' : 'red',
                    'color' => $colors[$index % count($colors)],
                    'series' => array_map(fn (string $key) => $series[$key] ?? [], array_keys(self::METRICS)),
                ];
            }
        }

        if ($players === [] || $length < 2) {
            return null;
        }

        // series was filled positionally; name it by metric key
        $keys = array_keys(self::METRICS);
        $players = array_map(function (array $player) use ($keys) {
            $player['series'] = array_combine($keys, $player['series']);

            return $player;
        }, $players);

        return [
            'minutes' => range(0, $length - 1),
            'metrics' => self::METRICS,
            'players' => $players,
        ];
    }
}
