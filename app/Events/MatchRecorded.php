<?php

namespace App\Events;

use App\Models\GameMatch;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A match was stored for the first time. Dispatched once per match, because a
 * match id that is already in the database is never fetched or stored again.
 */
final class MatchRecorded
{
    use Dispatchable;

    public function __construct(public readonly GameMatch $match) {}
}
