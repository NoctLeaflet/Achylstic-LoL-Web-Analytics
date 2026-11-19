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

    /**
     * Champion portrait. The numeric champion id is preferred because Data Dragon
     * file names differ from match-v5 champion names for a few champions
     * ("FiddleSticks" vs "Fiddlesticks.png"); the name is the fallback.
     */
    public function championIcon(?string $championName, ?int $championId = null): ?string
    {
        $file = $championId ? ($this->championFiles()[$championId] ?? null) : null;
        $file ??= $championName;

        return $file ? $this->asset('champion', "{$file}.png") : null;
    }

    /**
     * Data Dragon image name per champion id, e.g. 9 => "Fiddlesticks".
     * Empty when Data Dragon cannot be reached (icons then fall back to names).
     *
     * @return array<int, string>
     */
    public function championFiles(): array
    {
        return $this->staticData('champions', function (string $version) {
            $champions = Http::timeout(10)->get($this->baseUrl()."/cdn/{$version}/data/en_US/champion.json")->json('data') ?? [];
            $files = [];

            foreach ($champions as $champion) {
                $files[(int) $champion['key']] = (string) $champion['id'];
            }

            return $files;
        });
    }

    public function itemIcon(?int $itemId): ?string
    {
        return $itemId ? $this->asset('item', "{$itemId}.png") : null;
    }

    public function profileIcon(?int $profileIconId): ?string
    {
        return $profileIconId !== null ? $this->asset('profileicon', "{$profileIconId}.png") : null;
    }

    /**
     * Item names and whether an item is noise in a build (potions, wards,
     * trinkets), by item id. Empty when Data Dragon cannot be reached.
     *
     * @return array<int, array{name: string, skip: bool}>
     */
    public function items(): array
    {
        return $this->staticData('items', function (string $version) {
            $items = Http::timeout(10)->get($this->baseUrl()."/cdn/{$version}/data/en_US/item.json")->json('data') ?? [];
            $result = [];

            foreach ($items as $id => $item) {
                $tags = $item['tags'] ?? [];

                $result[(int) $id] = [
                    'name' => (string) ($item['name'] ?? ''),
                    'skip' => ($item['consumed'] ?? false) || in_array('Consumable', $tags, true) || in_array('Trinket', $tags, true),
                ];
            }

            return $result;
        });
    }

    /**
     * The rune trees (Precision, Domination, ...) with their rows of runes,
     * by tree id. Icons are paths for perkImage(). Empty when Data Dragon cannot be reached.
     *
     * @return array<int, array{id: int, name: string, icon: string, slots: list<list<array{id: int, name: string, icon: string}>>}>
     */
    public function runeTrees(): array
    {
        return $this->staticData('runes', function (string $version) {
            $trees = Http::timeout(10)->get($this->baseUrl()."/cdn/{$version}/data/en_US/runesReforged.json")->json() ?? [];
            $result = [];

            foreach ($trees as $tree) {
                $result[(int) $tree['id']] = [
                    'id' => (int) $tree['id'],
                    'name' => (string) $tree['name'],
                    'icon' => (string) $tree['icon'],
                    'slots' => array_map(
                        fn (array $slot) => array_map(
                            fn (array $rune) => ['id' => (int) $rune['id'], 'name' => (string) $rune['name'], 'icon' => (string) $rune['icon']],
                            $slot['runes'] ?? [],
                        ),
                        $tree['slots'] ?? [],
                    ),
                ];
            }

            return $result;
        });
    }

    /** URL of a rune or rune shard image, from a path like "perk-images/Styles/7201_Precision.png". These are not versioned. */
    public function perkImage(string $path): string
    {
        return $this->baseUrl().'/cdn/img/'.implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * Static data of the current game version, cached per version. A failed
     * download is remembered for a few minutes only, so it is retried soon.
     *
     * @param  callable(string): array<mixed>  $fetch
     * @return array<mixed>
     */
    private function staticData(string $name, callable $fetch): array
    {
        $version = $this->version();

        if ($version === null) {
            return [];
        }

        $key = "ddragon.{$name}.{$version}";
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $data = $fetch($version);
        } catch (Throwable) {
            $data = [];
        }

        Cache::put($key, $data, $data === [] ? now()->addMinutes(5) : now()->addDay());

        return $data;
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
