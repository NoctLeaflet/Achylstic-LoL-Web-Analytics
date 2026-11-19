<?php

namespace App\Services\Riot;

use App\Services\Riot\Exceptions\RiotApiException;
use App\Services\Riot\Exceptions\RiotAuthException;
use App\Services\Riot\Exceptions\RiotRateLimitException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

/**
 * The single gateway to the Riot API. Every call goes through here so that:
 *  - the dev-key budget (20 req/s, 100 req/2 min) is shared by all commands,
 *  - 429 responses wait for Retry-After and retry, 5xx responses retry lightly,
 *  - expired/invalid keys surface as a clear RiotAuthException,
 *  - nothing we log or throw ever contains the API key.
 */
class RiotClient
{
    private const MAX_RATE_LIMIT_RETRIES = 3;

    private const MAX_SERVER_ERROR_RETRIES = 2;

    private const TIMEOUT_SECONDS = 15;

    private const LIMITER_SECOND = 'riot-api:per-second';

    private const LIMITER_TWO_MINUTES = 'riot-api:per-two-minutes';

    /**
     * GET from the account routing region (account-v1).
     *
     * @param  array<string, scalar>  $query
     * @return array<mixed>|null null when Riot answers 404
     */
    public function account(string $path, array $query = []): ?array
    {
        return $this->get((string) config('services.riot.account_region'), $path, $query);
    }

    /**
     * GET from a platform routing host (summoner, league, mastery).
     * Pass $platform for a player on a different server than RIOT_PLATFORM.
     *
     * @param  array<string, scalar>  $query
     * @return array<mixed>|null null when Riot answers 404
     */
    public function platform(string $path, array $query = [], ?string $platform = null): ?array
    {
        return $this->get($platform ?: (string) config('services.riot.platform'), $path, $query);
    }

    /**
     * GET from the regional routing host (match-v5).
     *
     * @param  array<string, scalar>  $query
     * @return array<mixed>|null null when Riot answers 404
     */
    public function region(string $path, array $query = []): ?array
    {
        return $this->get((string) config('services.riot.region'), $path, $query);
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<mixed>|null
     */
    private function get(string $host, string $path, array $query): ?array
    {
        $key = config('services.riot.key');

        if (blank($key)) {
            throw RiotAuthException::missingKey();
        }

        if (! preg_match('/^[a-z0-9]+$/i', $host)) {
            throw new InvalidArgumentException("Invalid Riot routing value [{$host}].");
        }

        $url = "https://{$host}.api.riotgames.com{$path}";
        $rateLimitRetries = 0;
        $serverRetries = 0;

        while (true) {
            $this->waitForCapacity();

            try {
                $response = Http::withHeaders(['X-Riot-Token' => $key])
                    ->acceptJson()
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->get($url, $query);
            } catch (ConnectionException $e) {
                if ($serverRetries < self::MAX_SERVER_ERROR_RETRIES) {
                    $serverRetries++;
                    Log::warning('Riot API connection failed, retrying', ['host' => $host, 'attempt' => $serverRetries]);
                    Sleep::for($serverRetries)->seconds();

                    continue;
                }

                Log::error('Riot API connection failed', ['host' => $host]);

                throw new RiotApiException("Gagal terhubung ke Riot API ({$host}).", null, $e);
            }

            $status = $response->status();

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            if ($status === 404) {
                return null;
            }

            if ($status === 401 || $status === 403) {
                Log::error('Riot API key rejected', ['status' => $status, 'host' => $host]);

                throw RiotAuthException::rejected($status);
            }

            if ($status === 429) {
                if ($rateLimitRetries >= self::MAX_RATE_LIMIT_RETRIES) {
                    Log::error('Riot API rate limit still exceeded after retries', ['host' => $host, 'path' => $path]);

                    throw new RiotRateLimitException;
                }

                $rateLimitRetries++;
                $wait = max(1, (int) $response->header('Retry-After'));
                Log::warning('Riot API rate limited, waiting', ['host' => $host, 'retry_after' => $wait]);
                Sleep::for($wait)->seconds();

                continue;
            }

            if ($status >= 500 && $serverRetries < self::MAX_SERVER_ERROR_RETRIES) {
                $serverRetries++;
                Log::warning('Riot API server error, retrying', ['status' => $status, 'host' => $host, 'attempt' => $serverRetries]);
                Sleep::for($serverRetries)->seconds();

                continue;
            }

            Log::error('Riot API request failed', ['status' => $status, 'host' => $host, 'path' => $path]);

            throw new RiotApiException("Riot API error HTTP {$status} ({$path}).", $status);
        }
    }

    /**
     * Block until both rate limit windows have room, then reserve one request.
     * The counters live in the cache, so they are shared between commands that
     * run at the same time (e.g. the ranks and matches syncs).
     */
    private function waitForCapacity(): void
    {
        $windows = [
            [self::LIMITER_SECOND, (int) config('services.riot.rate_limits.per_second'), 1],
            [self::LIMITER_TWO_MINUTES, (int) config('services.riot.rate_limits.per_two_minutes'), 120],
        ];

        while (true) {
            $wait = 0;

            foreach ($windows as [$limiterKey, $maxAttempts]) {
                if (RateLimiter::tooManyAttempts($limiterKey, $maxAttempts)) {
                    $wait = max($wait, RateLimiter::availableIn($limiterKey));
                }
            }

            if ($wait === 0) {
                break;
            }

            Sleep::for($wait)->seconds();
        }

        foreach ($windows as [$limiterKey, , $decaySeconds]) {
            RateLimiter::hit($limiterKey, $decaySeconds);
        }
    }
}
