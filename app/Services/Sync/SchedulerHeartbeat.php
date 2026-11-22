<?php

namespace App\Services\Sync;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Tells whether Laravel's scheduler is running. It stamps the time every minute (routes/console.php);
 * when the stamp is old, nothing syncs and Discord hears nothing, so the admin page warns.
 */
final class SchedulerHeartbeat
{
    public const KEY = 'scheduler.heartbeat';

    /** Older than this and the scheduler counts as stopped. */
    public const STALE_AFTER_MINUTES = 5;

    public function lastBeat(): ?CarbonInterface
    {
        $stamp = Cache::get(self::KEY);

        return is_string($stamp) ? now()->parse($stamp) : null;
    }

    public function alive(): bool
    {
        $beat = $this->lastBeat();

        return $beat !== null && $beat->gte(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }
}
