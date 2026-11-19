<?php

namespace App\Services\Stats;

use App\Models\GameMatch;
use App\Support\Format;

/**
 * Lane against lane: for every role that exists once on each team (top vs top,
 * support vs support, ...) the numbers for the radar pop-up of a match.
 *
 * Six per-match numbers are compared, all "higher is better". The radar scales
 * every axis to the better of the two players (100); the real values are kept for
 * the tooltips and the table. Modes without lanes (ARAM) and remakes give no
 * matchups, and a role that is filled twice on a team is skipped.
 */
final class LaneMatchups
{
    /** Role as stored (teamPosition) => label. In display order. */
    public const LANES = [
        'TOP' => 'Top',
        'JUNGLE' => 'Jungle',
        'MIDDLE' => 'Mid',
        'BOTTOM' => 'Bot',
        'UTILITY' => 'Support',
    ];

    /** Metric key => [axis label, decimals]. */
    private const METRICS = [
        'kda' => ['KDA', 2],
        'kill_participation' => ['Partisipasi kill %', 0],
        'damage_per_min' => ['Damage/mnt', 0],
        'gold_per_min' => ['Gold/mnt', 0],
        'cs_per_min' => ['CS/mnt', 1],
        'vision_per_min' => ['Vision/mnt', 2],
    ];

    private const BLUE_TEAM = 100;

    private const RED_TEAM = 200;

    private const BLUE_COLOR = '#38bdf8';

    private const RED_COLOR = '#fb7185';

    /**
     * @param  list<array<string, mixed>>  $teams  teams as built by MatchScoreboard (team_id, kills, participants)
     * @return list<array{
     *     position: string,
     *     label: string,
     *     blue: array<string, mixed>,
     *     red: array<string, mixed>,
     *     radar: array{labels: list<string>, datasets: list<array<string, mixed>>},
     *     rows: list<array{label: string, blue: string, red: string, winner: string|null}>,
     *     leads: array{blue: int, red: int}
     * }>
     */
    public function build(array $teams, int $durationSeconds): array
    {
        if ($durationSeconds < GameMatch::REMAKE_MAX_DURATION_SECONDS) {
            return [];
        }

        $blue = $this->team($teams, self::BLUE_TEAM);
        $red = $this->team($teams, self::RED_TEAM);

        if ($blue === null || $red === null) {
            return [];
        }

        $minutes = $durationSeconds / 60;
        $matchups = [];

        foreach (self::LANES as $position => $label) {
            $blueSide = array_values(array_filter($blue['participants'], fn (array $p) => $p['position'] === $position));
            $redSide = array_values(array_filter($red['participants'], fn (array $p) => $p['position'] === $position));

            if (count($blueSide) !== 1 || count($redSide) !== 1) {
                continue;
            }

            $matchups[] = $this->matchup($position, $label, $blueSide[0], $redSide[0], (int) $blue['kills'], (int) $red['kills'], $minutes);
        }

        return $matchups;
    }

    /**
     * @param  array<string, mixed>  $blue
     * @param  array<string, mixed>  $red
     * @return array<string, mixed>
     */
    private function matchup(string $position, string $label, array $blue, array $red, int $blueTeamKills, int $redTeamKills, float $minutes): array
    {
        $blueValues = $this->metrics($blue, $blueTeamKills, $minutes);
        $redValues = $this->metrics($red, $redTeamKills, $minutes);

        $axes = [];
        $blueScaled = [];
        $redScaled = [];
        $rows = [];
        $leads = ['blue' => 0, 'red' => 0];

        foreach (self::METRICS as $key => [$axis, $decimals]) {
            $best = max($blueValues[$key], $redValues[$key]);

            $axes[] = $axis;
            $blueScaled[] = $best > 0 ? round($blueValues[$key] / $best * 100, 1) : 0.0;
            $redScaled[] = $best > 0 ? round($redValues[$key] / $best * 100, 1) : 0.0;

            $winner = $blueValues[$key] > $redValues[$key] ? 'blue' : ($redValues[$key] > $blueValues[$key] ? 'red' : null);

            if ($winner !== null) {
                $leads[$winner]++;
            }

            $rows[] = [
                'label' => $axis,
                'blue' => Format::number($blueValues[$key], $decimals),
                'red' => Format::number($redValues[$key], $decimals),
                'winner' => $winner,
            ];
        }

        return [
            'position' => $position,
            'label' => $label,
            'blue' => $this->side($blue),
            'red' => $this->side($red),
            'radar' => [
                'labels' => $axes,
                'datasets' => [
                    $this->dataset($blue, self::BLUE_COLOR, $blueScaled, array_values($blueValues)),
                    $this->dataset($red, self::RED_COLOR, $redScaled, array_values($redValues)),
                ],
            ],
            'rows' => $rows,
            'leads' => $leads,
        ];
    }

    /**
     * @param  array<string, mixed>  $p  a participant as built by MatchScoreboard
     * @return array<string, float>
     */
    private function metrics(array $p, int $teamKills, float $minutes): array
    {
        return [
            'kda' => (float) $p['kda'],
            'kill_participation' => round(($p['kills'] + $p['assists']) / max($teamKills, 1) * 100, 0),
            'damage_per_min' => round($p['damage'] / $minutes, 0),
            'gold_per_min' => round($p['gold'] / $minutes, 0),
            'cs_per_min' => round($p['cs'] / $minutes, 1),
            'vision_per_min' => round($p['vision'] / $minutes, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function side(array $p): array
    {
        return [
            'id' => $p['id'],
            'name' => $p['name'],
            'champion_name' => $p['champion_name'],
            'champion_id' => $p['champion_id'],
            'score' => $p['score'],
            'badge' => $p['badge'],
            'win' => $p['win'],
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  list<float>  $scaled
     * @param  list<float>  $raw
     * @return array<string, mixed>
     */
    private function dataset(array $p, string $color, array $scaled, array $raw): array
    {
        return [
            // Player name only: the champion is already shown in the header of the pop-up.
            'label' => $p['name'],
            'color' => $color,
            'values' => $scaled,
            'raw' => $raw,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $teams
     * @return array<string, mixed>|null
     */
    private function team(array $teams, int $teamId): ?array
    {
        foreach ($teams as $team) {
            if ($team['team_id'] === $teamId) {
                return $team;
            }
        }

        return null;
    }
}
