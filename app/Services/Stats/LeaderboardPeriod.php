<?php

namespace App\Services\Stats;

/**
 * The time window of the leaderboard (LP gain, trend line and climber): a day,
 * a week, a month or all time. "All time" has no day count (null).
 */
enum LeaderboardPeriod: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case All = 'all';

    public static function fromSlug(?string $slug): self
    {
        return self::tryFrom((string) $slug) ?? self::Week;
    }

    public function days(): ?int
    {
        return match ($this) {
            self::Day => 1,
            self::Week => 7,
            self::Month => 30,
            self::All => null,
        };
    }

    /** Short tab label. */
    public function label(): string
    {
        return match ($this) {
            self::Day => 'Hari',
            self::Week => 'Minggu',
            self::Month => 'Bulan',
            self::All => 'All Time',
        };
    }

    /** "Climber of the week" style title. */
    public function climberTitle(): string
    {
        return match ($this) {
            self::Day => 'Climber of the day',
            self::Week => 'Climber of the week',
            self::Month => 'Climber of the month',
            self::All => 'Climber of all time',
        };
    }

    /** Text after "LP" in the table header and the climber card. */
    public function unit(): string
    {
        return match ($this) {
            self::Day => 'hari ini',
            self::Week => '7 hari',
            self::Month => '30 hari',
            self::All => 'total',
        };
    }
}
