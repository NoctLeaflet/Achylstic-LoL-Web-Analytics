<?php

namespace App\Events;

use App\Enums\RankedQueue;
use App\Models\Player;
use App\Models\RankSnapshot;
use App\Services\Stats\RankScore;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A player's tier or division changed between two rank snapshots (LP-only
 * changes do not count). The very first snapshot of a player never raises it.
 */
final class RankChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Player $player,
        public readonly RankedQueue $queue,
        public readonly RankSnapshot $previous,
        public readonly RankSnapshot $current,
    ) {}

    public function promoted(): bool
    {
        return $this->position($this->current) > $this->position($this->previous);
    }

    /** Where a snapshot sits on the ladder, ignoring LP. */
    public static function position(RankSnapshot $snapshot): int
    {
        return RankScore::sortKey($snapshot->tier, $snapshot->rank, 0);
    }
}
