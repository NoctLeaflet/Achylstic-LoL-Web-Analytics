<?php

namespace App\Services\Riot;

use App\Services\Discord\DiscordMessages;
use App\Services\Discord\DiscordNotifier;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Remembers whether Riot rejected the API key, so the admin page can warn and
 * Discord gets ONE message per episode (not one every 15 minutes while the key
 * stays expired). Development keys expire every 24 hours, so this will happen.
 *
 * RiotClient reports every rejection and every success; the first success of a
 * process clears a stored rejection.
 */
class KeyHealth
{
    private const KEY = 'riot.key-rejected';

    private bool $successSeen = false;

    /**
     * @return array{status: int, since: CarbonInterface}|null null while the key works
     */
    public function rejection(): ?array
    {
        $state = Cache::get(self::KEY);

        return is_array($state) && isset($state['status'], $state['since'])
            ? ['status' => (int) $state['status'], 'since' => now()->parse($state['since'])]
            : null;
    }

    public function rejected(int $status): void
    {
        $this->successSeen = false;

        if (Cache::has(self::KEY)) {
            return;   // already known and announced
        }

        Cache::forever(self::KEY, ['status' => $status, 'since' => now()->toIso8601String()]);

        try {
            $notifier = app(DiscordNotifier::class);

            if ($notifier->enabled()) {
                $notifier->send([app(DiscordMessages::class)->keyRejected($status)]);
            }
        } catch (Throwable) {
            // A Discord problem must never hide the real error.
        }
    }

    public function succeeded(): void
    {
        // Once per process is enough; this runs for every request to Riot.
        if ($this->successSeen) {
            return;
        }

        $this->successSeen = true;
        Cache::forget(self::KEY);
    }
}
