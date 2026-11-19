<?php

namespace App\Services\Stats;

/**
 * Turns a (tier, division, LP) triple into numbers.
 *
 *  - sortKey(): orders players tier -> division -> LP for the leaderboard.
 *  - score():   one continuous LP scale for the charts. Every division is 100 LP,
 *               so Iron IV 0 LP = 0 and Gold II 54 LP = 1454. Master, Grandmaster
 *               and Challenger share the ladder above Diamond I (2800 + LP).
 */
final class RankScore
{
    /** @var list<string> */
    private const TIERS = [
        'IRON', 'BRONZE', 'SILVER', 'GOLD', 'PLATINUM', 'EMERALD', 'DIAMOND',
        'MASTER', 'GRANDMASTER', 'CHALLENGER',
    ];

    /** Division name => position inside its tier (IV is the lowest). */
    private const DIVISIONS = ['IV' => 0, 'III' => 1, 'II' => 2, 'I' => 3];

    /** Index of MASTER in TIERS: from here on there are no divisions. */
    private const FIRST_APEX_TIER = 7;

    private const POINTS_PER_DIVISION = 100;

    private const POINTS_PER_TIER = 400;

    private const APEX_BASE = 2800;

    public static function isApex(string $tier): bool
    {
        $index = self::tierIndex($tier);

        return $index !== null && $index >= self::FIRST_APEX_TIER;
    }

    /** Sort key for the leaderboard: tier first, then division, then LP. */
    public static function sortKey(string $tier, string $division, int $lp): int
    {
        $tierIndex = self::tierIndex($tier) ?? -1;
        $divisionIndex = self::isApex($tier) ? 0 : (self::DIVISIONS[strtoupper($division)] ?? 0);

        return ($tierIndex + 1) * 1_000_000 + $divisionIndex * 100_000 + $lp;
    }

    /** Position on the continuous LP scale used by the charts. */
    public static function score(string $tier, string $division, int $lp): int
    {
        $tierIndex = self::tierIndex($tier);

        if ($tierIndex === null) {
            return 0;
        }

        if ($tierIndex >= self::FIRST_APEX_TIER) {
            return self::APEX_BASE + $lp;
        }

        $divisionIndex = self::DIVISIONS[strtoupper($division)] ?? 0;

        return $tierIndex * self::POINTS_PER_TIER + $divisionIndex * self::POINTS_PER_DIVISION + $lp;
    }

    /** "Gold II" or "Master" (no division for Master and above). */
    public static function tierLabel(string $tier, string $division): string
    {
        $name = ucfirst(strtolower($tier));

        return self::isApex($tier) ? $name : trim("{$name} ".strtoupper($division));
    }

    /** "Gold II · 54 LP" */
    public static function label(string $tier, string $division, int $lp): string
    {
        return self::tierLabel($tier, $division)." · {$lp} LP";
    }

    /**
     * Y-axis ticks for an LP chart covering $min..$max on the score scale,
     * one per division (or one per tier when the range is wide).
     *
     * @return list<array{value: int, label: string}>
     */
    public static function ticks(int $min, int $max): array
    {
        $step = ($max - $min) > 3 * self::POINTS_PER_TIER ? self::POINTS_PER_TIER : self::POINTS_PER_DIVISION;

        $start = max(0, intdiv($min, $step) * $step - $step);
        $end = intdiv($max, $step) * $step + $step;

        $ticks = [];

        for ($value = $start; $value <= $end; $value += $step) {
            $ticks[] = ['value' => $value, 'label' => self::scoreLabel($value, $step === self::POINTS_PER_TIER)];
        }

        return $ticks;
    }

    private static function scoreLabel(int $score, bool $tierOnly): string
    {
        if ($score >= self::APEX_BASE) {
            $lp = $score - self::APEX_BASE;

            return $lp === 0 ? 'Master' : "Master {$lp} LP";
        }

        $tier = ucfirst(strtolower(self::TIERS[intdiv($score, self::POINTS_PER_TIER)]));

        if ($tierOnly) {
            return $tier;
        }

        $division = array_search(intdiv($score % self::POINTS_PER_TIER, self::POINTS_PER_DIVISION), self::DIVISIONS, true);

        return "{$tier} {$division}";
    }

    private static function tierIndex(string $tier): ?int
    {
        $index = array_search(strtoupper($tier), self::TIERS, true);

        return $index === false ? null : $index;
    }
}
