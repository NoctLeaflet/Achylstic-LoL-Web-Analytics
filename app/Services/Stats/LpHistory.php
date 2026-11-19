<?php

namespace App\Services\Stats;

use App\Enums\RankedQueue;
use App\Models\Player;
use App\Models\RankSnapshot;
use Illuminate\Support\Collection;

/**
 * Chart.js data for LP over time, built from the stored rank snapshots.
 *
 * Points use epoch milliseconds on x and the continuous LP score on y (see
 * RankScore), so players in different tiers share one chart. Each point also
 * carries a readable label ("Gold II · 54 LP") for the tooltip.
 */
final class LpHistory
{
    public function __construct(private readonly PlayerPalette $palette) {}

    /**
     * @param  Collection<int, Player>  $players
     * @return array{
     *     datasets: list<array{player_id: int, label: string, color: string, points: list<array{x: int, y: int, label: string}>}>,
     *     ticks: list<array{value: int, label: string}>
     * }
     */
    public function chartData(Collection $players, RankedQueue $queue): array
    {
        $snapshots = RankSnapshot::query()
            ->whereIn('player_id', $players->pluck('id')->all())
            ->where('queue_type', $queue->value)
            ->orderBy('captured_at')
            ->orderBy('id')
            ->get()
            ->groupBy('player_id');

        $scores = [];

        $datasets = $players->values()->map(function (Player $player) use ($snapshots, &$scores) {
            $points = ($snapshots->get($player->id) ?? collect())
                ->map(function (RankSnapshot $snapshot) use (&$scores) {
                    $score = RankScore::score($snapshot->tier, $snapshot->rank, $snapshot->league_points);
                    $scores[] = $score;

                    return [
                        'x' => $snapshot->captured_at->getTimestamp() * 1000,
                        'y' => $score,
                        'label' => RankScore::label($snapshot->tier, $snapshot->rank, $snapshot->league_points),
                    ];
                })
                ->values()
                ->all();

            return [
                'player_id' => $player->id,
                'label' => $player->name,
                'color' => $this->palette->colorFor($player->id),
                'points' => $points,
            ];
        })->all();

        return [
            'datasets' => $datasets,
            'ticks' => $scores === [] ? [] : RankScore::ticks(min($scores), max($scores)),
        ];
    }
}
