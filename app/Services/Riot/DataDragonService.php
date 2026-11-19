<?php

namespace App\Services\Riot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Data Dragon: static asset URLs (champion, item and profile icons).
 *
 * The latest game version comes from /api/versions.json and is cached. This is
 * a public CDN, so it does not use RiotClient, the API key or its rate limits.
 * When the version cannot be determined the URL helpers return null and the
 * views fall back to a placeholder.
 */
class DataDragonService
{
    private const VERSION_KEY = 'ddragon.version';

    private const LAST_KNOWN_KEY = 'ddragon.version.last-known';

    public function version(): ?string
    {
        $cached = Cache::get(self::VERSION_KEY);

        if (is_string($cached)) {
            return $cached;
        }

        $latest = $this->fetchLatestVersion();

        if ($latest !== null) {
            Cache::put(self::VERSION_KEY, $latest, now()->addHours(6));
            Cache::forever(self::LAST_KNOWN_KEY, $latest);

            return $latest;
        }

        // Data Dragon is unreachable: reuse the last known version and retry soon.
        $lastKnown = Cache::get(self::LAST_KNOWN_KEY);

        if (is_string($lastKnown)) {
            Cache::put(self::VERSION_KEY, $lastKnown, now()->addMinutes(5));

            return $lastKnown;
        }

        return null;
    }

    public function championIcon(?string $championName): ?string
    {
        return $championName ? $this->asset('champion', "{$championName}.png") : null;
    }

    public function itemIcon(?int $itemId): ?string
    {
        return $itemId ? $this->asset('item', "{$itemId}.png") : null;
    }

    public function profileIcon(?int $profileIconId): ?string
    {
        return $profileIconId !== null ? $this->asset('profileicon', "{$profileIconId}.png") : null;
    }

    private function asset(string $folder, string $file): ?string
    {
        $version = $this->version();

        if ($version === null) {
            return null;
        }

        return $this->baseUrl()."/cdn/{$version}/img/{$folder}/".rawurlencode($file);
    }

    private function fetchLatestVersion(): ?string
    {
        try {
            $response = Http::timeout(5)->get($this->baseUrl().'/api/versions.json');
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $latest = $response->json()[0] ?? null;

        return is_string($latest) && $latest !== '' ? $latest : null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.ddragon.base_url'), '/');
    }
}
