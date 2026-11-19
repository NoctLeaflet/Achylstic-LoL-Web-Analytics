<?php

namespace App\Services\Stats;

use App\Models\Player;
use Illuminate\Support\Collection;

/**
 * Chart.js data for the compare page, built from PerformanceStats output.
 *
 * The metrics have very different scales (KDA ~3, damage/min ~600), so the radar
 * normalises each metric to 0-100 relative to the best player in the comparison;
 * the real values travel along in "raw" for the tooltips. The bar charts show
 * the real values, one small chart per metric.
 */
final class ComparisonBuilder
{
    /** Stat key => label, in radar order. */
    public const METRICS = [
        'kda' => 'KDA',
        'cs_per_min' => 'CS/min',
        'damage_per_min' => 'Damage/min',
        'vision_per_min' => 'Vision/min',
        'gold_per_min' => 'Gold/min',
        'winrate' => 'Winrate %',
    ];

    public function __construct(private readonly PlayerPalette $palette) {}

    /**
     * @param  Collection<int, Player>  $players
     * @param  array<int, array<string, int|float>>  $statsByPlayer  PerformanceStats::forPlayers() output
     * @return array{
     *     labels: list<string>,
     *     datasets: list<array{player_id: int, label: string, color: string, values: list<float>, raw: list<float>}>
     * }
     */
    public function radar(Collection $players, array $statsByPlayer): array
    {
        $best = [];

        foreach (array_keys(self::METRICS) as $metric) {
            $best[$metric] = $players->map(fn (Player $player) => (float) ($statsByPlayer[$player->id][$metric] ?? 0))->max() ?? 0.0;
        }

        $datasets = $players->values()->map(function (Player $player) use ($statsByPlayer, $best) {
            $raw = [];
            $values = [];

            foreach (array_keys(self::METRICS) as $metric) {
                $value = (float) ($statsByPlayer[$player->id][$metric] ?? 0);
                $raw[] = $value;
                $values[] = $best[$metric] > 0 ? round($value / $best[$metric] * 100, 1) : 0.0;
            }

            return [
                'player_id' => $player->id,
                'label' => $player->name,
                'color' => $this->palette->colorFor($player->id),
                'values' => $values,
                'raw' => $raw,
            ];
        })->all();

        return ['labels' => array_values(self::METRICS), 'datasets' => $datasets];
    }

    /**
     * @param  Collection<int, Player>  $players
     * @param  array<int, array<string, int|float>>  $statsByPlayer
     * @return list<array{key: string, label: string, labels: list<string>, values: list<float>, colors: list<string>}>
     */
    public function bars(Collection $players, array $statsByPlayer): array
    {
        $players = $players->values();
        $bars = [];

        foreach (self::METRICS as $metric => $label) {
            $bars[] = [
                'key' => $metric,
                'label' => $label,
                'labels' => $players->map(fn (Player $player) => $player->name)->all(),
                'values' => $players->map(fn (Player $player) => (float) ($statsByPlayer[$player->id][$metric] ?? 0))->all(),
                'colors' => $players->map(fn (Player $player) => $this->palette->colorFor($player->id))->all(),
            ];
        }

        return $bars;
    }
}
