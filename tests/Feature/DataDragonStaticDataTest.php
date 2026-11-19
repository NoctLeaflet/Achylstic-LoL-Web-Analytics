<?php

namespace Tests\Feature;

use App\Services\Riot\DataDragonService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DataDragonStaticDataTest extends TestCase
{
    private const BASE = 'https://ddragon.leagueoflegends.com';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::put('ddragon.version', '16.1.1');
    }

    private function service(): DataDragonService
    {
        return app(DataDragonService::class);
    }

    private function itemJson(): array
    {
        return ['data' => [
            '1055' => ['name' => "Doran's Blade", 'tags' => ['Health', 'Damage', 'Lane']],
            '2003' => ['name' => 'Health Potion', 'consumed' => true, 'tags' => ['HealthRegen', 'Consumable']],
            '2055' => ['name' => 'Control Ward', 'consumed' => true, 'tags' => ['Consumable', 'Vision']],
            '3340' => ['name' => 'Stealth Ward', 'tags' => ['Active', 'Trinket', 'Vision']],
            '3068' => ['name' => 'Sunfire Aegis', 'tags' => ['Armor', 'Health']],
        ]];
    }

    public function test_items_mark_consumables_and_trinkets_as_noise_and_are_cached(): void
    {
        Http::fake([self::BASE.'/cdn/16.1.1/data/en_US/item.json' => Http::response($this->itemJson())]);

        $items = $this->service()->items();

        $this->assertSame(['name' => "Doran's Blade", 'skip' => false], $items[1055]);
        $this->assertSame(['name' => 'Sunfire Aegis', 'skip' => false], $items[3068]);
        $this->assertTrue($items[2003]['skip']);
        $this->assertTrue($items[2055]['skip']);
        $this->assertTrue($items[3340]['skip']);

        // The second call comes from the cache.
        $this->service()->items();
        Http::assertSentCount(1);
    }

    public function test_rune_trees_keep_the_rows_and_icons(): void
    {
        Http::fake([self::BASE.'/cdn/16.1.1/data/en_US/runesReforged.json' => Http::response([[
            'id' => 8000, 'key' => 'Precision', 'name' => 'Precision', 'icon' => 'perk-images/Styles/7201_Precision.png',
            'slots' => [
                ['runes' => [['id' => 8005, 'key' => 'PressTheAttack', 'name' => 'Press the Attack', 'icon' => 'perk-images/Styles/Precision/PressTheAttack/PressTheAttack.png', 'longDesc' => 'x']]],
                ['runes' => [['id' => 9101, 'key' => 'Overheal', 'name' => 'Overheal', 'icon' => 'perk-images/Styles/Precision/Overheal.png']]],
            ],
        ]])]);

        $trees = $this->service()->runeTrees();

        $this->assertSame('Precision', $trees[8000]['name']);
        $this->assertCount(2, $trees[8000]['slots']);
        $this->assertSame(['id' => 8005, 'name' => 'Press the Attack', 'icon' => 'perk-images/Styles/Precision/PressTheAttack/PressTheAttack.png'], $trees[8000]['slots'][0][0]);
    }

    private function championJson(): array
    {
        return ['data' => [
            'Fiddlesticks' => ['id' => 'Fiddlesticks', 'key' => '9', 'name' => 'Fiddlesticks'],
            'Ahri' => ['id' => 'Ahri', 'key' => '103', 'name' => 'Ahri'],
            'MonkeyKing' => ['id' => 'MonkeyKing', 'key' => '62', 'name' => 'Wukong'],
        ]];
    }

    public function test_champion_icons_are_found_by_id_even_when_match_v5_spells_the_name_differently(): void
    {
        Http::fake([self::BASE.'/cdn/16.1.1/data/en_US/champion.json' => Http::response($this->championJson())]);

        $service = $this->service();

        // match-v5 says "FiddleSticks" (capital S), the file on the CDN is Fiddlesticks.png.
        $this->assertSame(self::BASE.'/cdn/16.1.1/img/champion/Fiddlesticks.png', $service->championIcon('FiddleSticks', 9));
        $this->assertSame(self::BASE.'/cdn/16.1.1/img/champion/MonkeyKing.png', $service->championIcon('MonkeyKing', 62));
        $this->assertSame([9 => 'Fiddlesticks', 103 => 'Ahri', 62 => 'MonkeyKing'], $service->championFiles());

        Http::assertSentCount(1);   // cached after the first lookup
    }

    public function test_champion_icons_fall_back_to_the_name_without_an_id_or_an_unknown_id(): void
    {
        Http::fake([self::BASE.'/cdn/16.1.1/data/en_US/champion.json' => Http::response($this->championJson())]);

        $service = $this->service();

        $this->assertSame(self::BASE.'/cdn/16.1.1/img/champion/Ahri.png', $service->championIcon('Ahri'));
        $this->assertSame(self::BASE.'/cdn/16.1.1/img/champion/Brandnew.png', $service->championIcon('Brandnew', 9999));
        $this->assertNull($service->championIcon(null));
        $this->assertNull($service->championIcon(''));
    }

    public function test_champion_icons_still_work_by_name_when_data_dragon_cannot_give_the_list(): void
    {
        Http::fake(['*/champion.json' => Http::response('', 503)]);

        $this->assertSame(self::BASE.'/cdn/16.1.1/img/champion/Ahri.png', $this->service()->championIcon('Ahri', 103));
    }

    public function test_perk_images_are_not_versioned(): void
    {
        $this->assertSame(
            self::BASE.'/cdn/img/perk-images/Styles/7201_Precision.png',
            $this->service()->perkImage('perk-images/Styles/7201_Precision.png'),
        );
    }

    public function test_an_unreachable_data_dragon_gives_empty_data_and_is_retried_soon(): void
    {
        $up = false;
        // By reference: an arrow function would copy $up and never see it change.
        Http::fake(function () use (&$up) {
            return $up ? Http::response($this->itemJson()) : Http::response('', 503);
        });

        $this->assertSame([], $this->service()->items());
        $this->assertSame([], $this->service()->items());   // remembered for a few minutes: no second request
        Http::assertSentCount(1);

        // Data Dragon is back and the short "it failed" memory has run out.
        $up = true;
        $this->travel(6)->minutes();

        $this->assertArrayHasKey(1055, $this->service()->items());
    }

    public function test_without_a_known_version_nothing_is_requested(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response('', 503)]);

        $this->assertSame([], $this->service()->items());
        $this->assertSame([], $this->service()->runeTrees());

        // Only the versions.json lookup was attempted.
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/versions.json'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/data/en_US/'));
    }

    public function test_each_game_version_has_its_own_cache(): void
    {
        Http::fake(['*/item.json' => Http::response($this->itemJson())]);

        $this->service()->items();
        Cache::put('ddragon.version', '16.2.1');
        $this->service()->items();

        Http::assertSentCount(2);
    }
}
