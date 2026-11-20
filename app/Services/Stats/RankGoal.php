<?php

namespace App\Services\Stats;

use App\Enums\RankedQueue;
use App\Models\Player;
use App\Models\RankSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Progress of a player towards the Solo/Duo rank the admin set as their goal.
 *
 * Everything is measured on the continuous LP scale of RankScore (every division
 * is 100 LP). The estimate uses the LP gained per day over the last PACE_DAYS
 * days and is only given when that pace is positive.
 */
final class RankGoal
{
    /** Window used to measure how fast the player climbs. */
    public const PACE_DAYS = 14;

    /** Window whose start is the 0% of the progress bar. */
    public const BASELINE_DAYS = 30;

    /** "Gold I" style choices for the admin: value "TIER:DIVISION" => label. */
    public static function options(): array
    {
        $options = [];

        foreach (['IRON', 'BRONZE', 'SILVER', 'GOLD', 'PLATINUM', 'EMERALD', 'DIAMOND'] as $tier) {
            foreach (['IV', 'III', 'II', 'I'] as $division) {
                $options["{$tier}:{$division}"] = RankScore::tierLabel($tier, $division);
            }
        }

        foreach (['MASTER', 'GRANDMASTER', 'CHALLENGER'] as $tier) {
            $options["{$tier}:I"] = RankScore::tierLabel($tier, 'I');
        }

        return $options;
    }

    /**
     * @return array{label: string, current: string, reached: bool, remaining: int, percent: int, per_day: float|null, eta_days: int|null}|null
     *                                                                                                                                          null without a goal or without any Solo/Duo rank
     */
    public function progress(Player $player, ?CarbonInterface $now = null): ?array
    {
        if ($player->goal_tier === null || $player->goal_division === null) {
            return null;
        }

        $now ??= now();
        $snapshots = RankSnapshot::query()
            ->where('player_id', $player->id)
            ->where('queue_type', RankedQueue::Solo->value)
            ->orderBy('captured_at')
            ->orderBy('id')
            ->get();

        if ($snapshots->isEmpty()) {
            return null;
        }

        $current = $snapshots->last();
        $currentScore = $this->score($current);
        $goalScore = RankScore::score($player->goal_tier, $player->goal_division, 0);
        $baseline = $this->scoreAt($snapshots, $now->copy()->subDays(self::BASELINE_DAYS));

        $reached = $currentScore >= $goalScore;
        $percent = match (true) {
            $reached => 100,
            $goalScore <= $baseline => 0,
            default => (int) max(0, min(99, round(($currentScore - $baseline) / ($goalScore - $baseline) * 100))),
        };

        $paceStart = $now->copy()->subDays(self::PACE_DAYS);
        $paceBase = $this->scoreAt($snapshots, $paceStart);
        $days = max($paceStart->diffInDays($current->captured_at, true), 1);
        $perDay = ($currentScore - $paceBase) / $days;
        $remaining = max($goalScore - $currentScore, 0);

        return [
            'label' => RankScore::tierLabel($player->goal_tier, $player->goal_division),
            'current' => RankScore::label($current->tier, $current->rank, $current->league_points),
            'reached' => $reached,
            'remaining' => $remaining,
            'percent' => $percent,
            'per_day' => $perDay > 0 ? round($perDay, 1) : null,
            'eta_days' => ! $reached && $perDay > 0 ? (int) ceil($remaining / $perDay) : null,
        ];
    }

    private function score(RankSnapshot $snapshot): int
    {
        return RankScore::score($snapshot->tier, $snapshot->rank, $snapshot->league_points);
    }

    /**
     * The score at a moment: the last snapshot before it, or the very first one
     * when the player was only tracked after it.
     *
     * @param  Collection<int, RankSnapshot>  $snapshots  oldest first
     */
    private function scoreAt($snapshots, CarbonInterface $moment): int
    {
        $before = $snapshots->filter(fn (RankSnapshot $s) => $s->captured_at->lte($moment))->last();

        return $this->score($before ?? $snapshots->first());
    }
}
