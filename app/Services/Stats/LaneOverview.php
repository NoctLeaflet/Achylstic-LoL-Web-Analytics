<?php

namespace App\Services\Stats;

/**
 * The "Lanes" pop-up of a match: for every lane the two players with their scores,
 * how far apart they are, and the categories each of them won.
 *
 * It reuses LaneMatchups, which already compared six numbers per lane; here the
 * six are shown as short tags (KDA, KP, DMG, Gold, CS, Vision) and the score
 * difference becomes a bar that leans towards the better player.
 */
final class LaneOverview
{
    /** Same order as LaneMatchups::METRICS. */
    private const TAGS = ['KDA', 'KP', 'DMG', 'Gold', 'CS', 'Vision'];

    /** A score difference of this many points fills the whole half of the bar. */
    public const FULL_BAR_AT = 60;

    /**
     * @param  list<array<string, mixed>>  $matchups  result of LaneMatchups::build()
     * @return list<array{position: string, label: string, blue: array<string, mixed>, red: array<string, mixed>, diff: int, leader: string|null, width: int, blue_wins: list<string>, red_wins: list<string>}>
     */
    public function build(array $matchups): array
    {
        return array_map(function (array $lane) {
            $diff = (int) $lane['blue']['score'] - (int) $lane['red']['score'];
            $wins = ['blue' => [], 'red' => []];

            foreach ($lane['rows'] as $index => $row) {
                if ($row['winner'] !== null) {
                    $wins[$row['winner']][] = self::TAGS[$index] ?? $row['label'];
                }
            }

            return [
                'position' => $lane['position'],
                'label' => $lane['label'],
                'blue' => $lane['blue'],
                'red' => $lane['red'],
                'diff' => $diff,
                'leader' => $diff === 0 ? null : ($diff > 0 ? 'blue' : 'red'),
                'width' => (int) min(100, round(abs($diff) / self::FULL_BAR_AT * 100)),
                'blue_wins' => $wins['blue'],
                'red_wins' => $wins['red'],
            ];
        }, $matchups);
    }
}
