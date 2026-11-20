<?php

namespace App\Services\Stats;

use App\Enums\MatchQueue;
use App\Models\GameMatch;
use App\Models\MatchParticipant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * How a player's last RECENT games compare with the games before them (at most
 * BASELINE of those): KDA, CS/min, damage/min, vision/min and winrate.
 *
 * The rates are ratios of totals, like PerformanceStats, and remakes are ignored.
 * Without MIN_GAMES games on each side there is no trend (null), because a
 * handful of games says nothing.
 */
final class PerformanceTrend
{
    public const RECENT = 10;

    public const BASELINE = 40;

    public const MIN_GAMES = 5;

    /** A change smaller than this (percent) is not worth announcing. */
    public const NOTABLE_PERCENT = 15;

    private const METRICS = [
        'kda' => ['KDA', 2],
        'cs_per_min' => ['CS/min', 1],
        'damage_per_min' => ['Damage/min', 0],
        'vision_per_min' => ['Vision/min', 2],
    ];

    /**
     * @return array{recent_games: int, before_games: int, metrics: list<array<string, mixed>>, winrate: array<string, mixed>}|null
     */
    public function forPlayer(int $playerId, ?MatchQueue $queue = null): ?array
    {
        $games = MatchParticipant::query()
            ->join('matches', 'matches.id', '=', 'match_participants.match_id')
            ->where('match_participants.player_id', $playerId)
            ->where('matches.game_duration', '>=', GameMatch::REMAKE_MAX_DURATION_SECONDS)
            ->when($queue !== null, fn (Builder $query) => $query->where('matches.queue_id', $queue->queueId()))
            ->orderByDesc('matches.game_start_at')
            ->orderByDesc('matches.id')
            ->limit(self::RECENT + self::BASELINE)
            ->selectRaw('match_participants.win, match_participants.kills, match_participants.deaths, match_participants.assists,
                match_participants.total_minions_killed + match_participants.neutral_minions_killed as cs,
                match_participants.total_damage_dealt_to_champions as damage, match_participants.vision_score as vision,
                matches.game_duration as seconds')
            ->toBase()
            ->get();

        $recent = $games->take(self::RECENT);
        $before = $games->slice(self::RECENT);

        if ($recent->count() < self::MIN_GAMES || $before->count() < self::MIN_GAMES) {
            return null;
        }

        $now = $this->rates($recent);
        $then = $this->rates($before);
        $metrics = [];

        foreach (self::METRICS as $key => [$label, $decimals]) {
            $metrics[] = [
                'key' => $key,
                'label' => $label,
                'decimals' => $decimals,
                'recent' => round($now[$key], $decimals),
                'before' => round($then[$key], $decimals),
                // Percent change; null when there is nothing to compare with.
                'change' => $then[$key] > 0 ? (int) round(($now[$key] - $then[$key]) / $then[$key] * 100) : null,
            ];
        }

        return [
            'recent_games' => $recent->count(),
            'before_games' => $before->count(),
            'metrics' => $metrics,
            'winrate' => [
                'recent' => (int) round($now['winrate']),
                'before' => (int) round($then['winrate']),
                // In percentage points.
                'change' => (int) round($now['winrate'] - $then['winrate']),
            ],
        ];
    }

    /**
     * The rate that changed the most, when it changed by at least NOTABLE_PERCENT.
     *
     * @param  array<string, mixed>  $trend  result of forPlayer()
     * @return array<string, mixed>|null
     */
    public function notable(array $trend): ?array
    {
        $best = collect($trend['metrics'])
            ->filter(fn (array $metric) => $metric['change'] !== null && abs($metric['change']) >= self::NOTABLE_PERCENT)
            ->sortByDesc(fn (array $metric) => abs($metric['change']))
            ->first();

        return $best;
    }

    /**
     * @param  Collection<int, object>  $games
     * @return array<string, float>
     */
    private function rates(Collection $games): array
    {
        $minutes = max($games->sum('seconds') / 60, 1);

        return [
            'kda' => ($games->sum('kills') + $games->sum('assists')) / max($games->sum('deaths'), 1),
            'cs_per_min' => $games->sum('cs') / $minutes,
            'damage_per_min' => $games->sum('damage') / $minutes,
            'vision_per_min' => $games->sum('vision') / $minutes,
            'winrate' => $games->where('win', true)->count() / $games->count() * 100,
        ];
    }
}
