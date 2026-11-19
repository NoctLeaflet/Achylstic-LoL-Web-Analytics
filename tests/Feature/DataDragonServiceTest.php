<?php

namespace Tests\Feature;

use App\Services\Riot\DataDragonService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DataDragonServiceTest extends TestCase
{
    private const VERSIONS_URL = 'https://ddragon.leagueoflegends.com/api/versions.json';

    public function test_it_fetches_the_latest_version_once_and_caches_it(): void
    {
        Http::fake([self::VERSIONS_URL => Http::response(['16.1.1', '16.0.1', '15.24.1'])]);

        $service = app(DataDragonService::class);

        $this->assertSame('16.1.1', $service->version());
        $this->assertSame('16.1.1', $service->version());

        Http::assertSentCount(1);
    }

    public function test_it_builds_asset_urls_from_the_cached_version(): void
    {
        Http::fake([self::VERSIONS_URL => Http::response(['16.1.1'])]);

        $service = app(DataDragonService::class);

        $this->assertSame('https://ddragon.leagueoflegends.com/cdn/16.1.1/img/champion/Ahri.png', $service->championIcon('Ahri'));
        $this->assertSame('https://ddragon.leagueoflegends.com/cdn/16.1.1/img/item/3020.png', $service->itemIcon(3020));
        $this->assertSame('https://ddragon.leagueoflegends.com/cdn/16.1.1/img/profileicon/588.png', $service->profileIcon(588));
    }

    public function test_empty_inputs_have_no_url(): void
    {
        Http::fake([self::VERSIONS_URL => Http::response(['16.1.1'])]);

        $service = app(DataDragonService::class);

        $this->assertNull($service->championIcon(null));
        $this->assertNull($service->championIcon(''));
        $this->assertNull($service->itemIcon(0));
        $this->assertNull($service->itemIcon(null));
        $this->assertNull($service->profileIcon(null));
    }

    public function test_it_reuses_the_last_known_version_when_data_dragon_is_unreachable(): void
    {
        $reachable = true;
        Http::fake(function () use (&$reachable) {
            return $reachable ? Http::response(['16.1.1']) : Http::response('', 503);
        });

        $service = app(DataDragonService::class);
        $this->assertSame('16.1.1', $service->version());

        // The 6 hour cache expires while Data Dragon is down.
        Cache::forget('ddragon.version');
        $reachable = false;

        $this->assertSame('16.1.1', $service->version());
        $this->assertSame('https://ddragon.leagueoflegends.com/cdn/16.1.1/img/champion/Ahri.png', $service->championIcon('Ahri'));
    }

    public function test_it_returns_null_urls_when_nothing_is_known_and_the_cdn_is_down(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 6: could not resolve host')]);

        $service = app(DataDragonService::class);

        $this->assertNull($service->version());
        $this->assertNull($service->championIcon('Ahri'));
        $this->assertNull($service->profileIcon(588));
    }
}
