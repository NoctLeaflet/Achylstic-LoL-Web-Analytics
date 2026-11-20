<?php

namespace App\Services\Sync;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers the state of the "sync now" run so the admin page can show it:
 * running, done or failed. A run that says "running" for longer than
 * STALE_AFTER_MINUTES is treated as dead (e.g. the process was killed).
 */
class SyncStatus
{
    public const STALE_AFTER_MINUTES = 20;

    private const KEY = 'admin.sync-status';

    /**
     * @return array{state: string, at: CarbonInterface, message: string|null}|null
     */
    public function get(): ?array
    {
        $status = Cache::get(self::KEY);

        if (! is_array($status) || ! isset($status['state'], $status['at'])) {
            return null;
        }

        $at = now()->parse($status['at']);

        if ($status['state'] === 'running' && $at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
            return ['state' => 'failed', 'at' => $at, 'message' => 'Proses berhenti sebelum selesai.'];
        }

        return ['state' => $status['state'], 'at' => $at, 'message' => $status['message'] ?? null];
    }

    public function isRunning(): bool
    {
        return ($this->get()['state'] ?? null) === 'running';
    }

    public function running(): void
    {
        $this->put('running');
    }

    public function done(?string $message = null): void
    {
        $this->put('done', $message);
    }

    public function failed(string $message): void
    {
        $this->put('failed', $message);
    }

    private function put(string $state, ?string $message = null): void
    {
        Cache::put(self::KEY, ['state' => $state, 'at' => now()->toIso8601String(), 'message' => $message], now()->addDays(7));
    }
}
