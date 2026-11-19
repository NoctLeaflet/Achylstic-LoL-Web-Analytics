<?php

namespace Tests\Feature;

use App\Models\MatchParticipant;
use App\Models\Player;
use App\Services\Stats\ParticipantBuild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class ParticipantBuildTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    private const ITEMS = [
        1055 => ['name' => "Doran's Blade", 'skip' => false],
        1001 => ['name' => 'Boots', 'skip' => false],
        3006 => ['name' => "Berserker's Greaves", 'skip' => false],
        2003 => ['name' => 'Health Potion', 'skip' => true],
        2055 => ['name' => 'Control Ward', 'skip' => true],
        3340 => ['name' => 'Stealth Ward', 'skip' => true],
    ];

    private function trees(): array
    {
        $rune = fn (int $id, string $name, string $folder) => ['id' => $id, 'name' => $name, 'icon' => "perk-images/Styles/{$folder}/{$name}.png"];

        return [
            8000 => ['id' => 8000, 'name' => 'Precision', 'icon' => 'perk-images/Styles/7201_Precision.png', 'slots' => [
                [$rune(8005, 'PressTheAttack', 'Precision'), $rune(8008, 'LethalTempo', 'Precision'), $rune(8010, 'Conqueror', 'Precision')],
                [$rune(9101, 'Overheal', 'Precision'), $rune(9111, 'Triumph', 'Precision'), $rune(8009, 'PresenceOfMind', 'Precision')],
                [$rune(9104, 'LegendAlacrity', 'Precision'), $rune(9105, 'LegendTenacity', 'Precision'), $rune(9103, 'LegendBloodline', 'Precision')],
                [$rune(8014, 'CoupDeGrace', 'Precision'), $rune(8017, 'CutDown', 'Precision'), $rune(8299, 'LastStand', 'Precision')],
            ]],
            8200 => ['id' => 8200, 'name' => 'Sorcery', 'icon' => 'perk-images/Styles/7202_Sorcery.png', 'slots' => [
                [$rune(8214, 'SummonAery', 'Sorcery'), $rune(8229, 'ArcaneComet', 'Sorcery'), $rune(8230, 'PhaseRush', 'Sorcery')],
                [$rune(8224, 'NullifyingOrb', 'Sorcery'), $rune(8226, 'ManaflowBand', 'Sorcery'), $rune(8275, 'NimbusCloak', 'Sorcery')],
                [$rune(8210, 'Transcendence', 'Sorcery'), $rune(8234, 'Celerity', 'Sorcery'), $rune(8233, 'AbsoluteFocus', 'Sorcery')],
                [$rune(8237, 'Scorch', 'Sorcery'), $rune(8232, 'Waterwalking', 'Sorcery'), $rune(8236, 'GatheringStorm', 'Sorcery')],
            ]],
        ];
    }

    private function seedDataDragon(bool $available = true): void
    {
        Http::fake();
        Cache::put('ddragon.version', '16.1.1');

        if ($available) {
            Cache::put('ddragon.items.16.1.1', self::ITEMS);
            Cache::put('ddragon.runes.16.1.1', $this->trees());
        }
    }

    private function participant(array $stats = []): MatchParticipant
    {
        $player = Player::where('game_name', 'Alpha')->first() ?? $this->makePlayer('Alpha');

        return $this->addGame($player, $stats)->participants()->sole();
    }

    private function build(MatchParticipant $participant): array
    {
        return app(ParticipantBuild::class)->build($participant);
    }

    // ---- items --------------------------------------------------------------

    public function test_items_are_grouped_by_minute_without_potions_wards_and_trinkets(): void
    {
        $this->seedDataDragon();
        $participant = $this->participant(['item_purchases' => [
            [8, 1055], [9, 2003], [10, 3340],       // start: Doran's Blade, potion (skipped), trinket (skipped)
            [55, 2003],
            [250, 1001], [255, 2055],               // 5m: boots, control ward (skipped)
            [262, 3006],                            // 5m too (262 s is 4:22 -> the 5th minute)
            [1500, 3006],
        ]]);

        $items = $this->build($participant)['items'];

        $this->assertSame([1, 5, 25], array_column($items, 'minute'));
        $this->assertSame([['id' => 1055, 'name' => "Doran's Blade"]], $items[0]['items']);
        $this->assertSame([1001, 3006], array_column($items[1]['items'], 'id'));
        $this->assertSame("Berserker's Greaves", $items[1]['items'][1]['name']);
    }

    public function test_the_first_minute_label_is_never_zero(): void
    {
        $this->seedDataDragon();
        $participant = $this->participant(['item_purchases' => [[0, 1055], [60, 1001], [61, 3006]]]);

        $this->assertSame([1, 2], array_column($this->build($participant)['items'], 'minute'));
    }

    public function test_it_falls_back_to_a_fixed_skip_list_when_data_dragon_is_down(): void
    {
        $this->seedDataDragon(available: false);
        Http::fake(['*' => Http::response('', 503)]);
        $participant = $this->participant(['item_purchases' => [[8, 1055], [9, 2003], [10, 3340], [11, 2055]]]);

        $items = $this->build($participant)['items'];

        $this->assertSame([['id' => 1055, 'name' => null]], $items[0]['items']);
    }

    public function test_a_match_without_a_timeline_has_no_items_or_skills(): void
    {
        $this->seedDataDragon();
        $participant = $this->participant();

        $build = $this->build($participant);

        $this->assertFalse($build['has_timeline']);
        $this->assertSame([], $build['items']);
        $this->assertNull($build['skills']);
    }

    // ---- skills -------------------------------------------------------------

    public function test_the_skill_grid_lists_the_levels_per_skill(): void
    {
        $this->seedDataDragon();
        // Levels 1..6: Q W E Q Q R
        $participant = $this->participant(['item_purchases' => [], 'skill_order' => [1, 2, 3, 1, 1, 4]]);

        $skills = $this->build($participant)['skills'];

        $this->assertSame(['Q' => [1, 4, 5], 'W' => [2], 'E' => [3], 'R' => [6]], $skills['grid']);
        $this->assertSame(18, $skills['levels']);
    }

    public function test_the_priority_follows_which_skill_is_maxed_first(): void
    {
        $this->seedDataDragon();
        // Q is maxed at level 9, W at 13, E is never maxed.
        $order = [1, 2, 3, 1, 1, 4, 1, 3, 1, 3, 4, 2, 2, 3, 2, 4, 2, 3];
        $participant = $this->participant(['item_purchases' => [], 'skill_order' => $order]);

        $skills = $this->build($participant)['skills'];

        $this->assertSame(['Q', 'W', 'E'], $skills['priority']);
    }

    public function test_skills_that_were_never_maxed_are_ordered_by_points_taken(): void
    {
        $this->seedDataDragon();
        // A short game: Q 3 points, E 2 points, W 1 point.
        $participant = $this->participant(['item_purchases' => [], 'skill_order' => [1, 3, 1, 2, 1, 3]]);

        $this->assertSame(['Q', 'E', 'W'], $this->build($participant)['skills']['priority']);
    }

    public function test_a_game_longer_than_18_levels_widens_the_grid(): void
    {
        $this->seedDataDragon();
        $participant = $this->participant(['item_purchases' => [], 'skill_order' => array_fill(0, 20, 1)]);

        $this->assertSame(20, $this->build($participant)['skills']['levels']);
    }

    // ---- runes --------------------------------------------------------------

    private function perks(): array
    {
        return [
            'styles' => [
                ['style' => 8000, 'description' => 'primaryStyle', 'selections' => [['perk' => 8010], ['perk' => 9111], ['perk' => 9104], ['perk' => 8014]]],
                ['style' => 8200, 'description' => 'subStyle', 'selections' => [['perk' => 8226], ['perk' => 8233]]],
            ],
            'statPerks' => ['offense' => 5005, 'flex' => 5008, 'defense' => 5011],
        ];
    }

    public function test_runes_mark_the_picked_runes_in_both_trees_and_list_the_shards(): void
    {
        $this->seedDataDragon();
        $participant = $this->participant(['perks' => $this->perks()]);

        $runes = $this->build($participant)['runes'];

        $this->assertSame('Precision', $runes['primary']['name']);
        $this->assertCount(4, $runes['primary']['rows']);   // keystone row + 3 rows
        $selected = fn (array $tree) => collect($tree['rows'])->flatten(1)->where('selected', true)->pluck('id')->all();
        $this->assertSame([8010, 9111, 9104, 8014], $selected($runes['primary']));

        $this->assertSame('Sorcery', $runes['secondary']['name']);
        $this->assertCount(3, $runes['secondary']['rows']);  // no keystone row in the secondary tree
        $this->assertSame([8226, 8233], $selected($runes['secondary']));

        $this->assertSame(
            'https://ddragon.leagueoflegends.com/cdn/img/perk-images/Styles/Precision/Conqueror.png',
            $runes['primary']['rows'][0][2]['icon'],
        );
        $this->assertSame(['Attack Speed', 'Adaptive Force', 'Health'], array_column($runes['shards'], 'name'));
        $this->assertSame(
            'https://ddragon.leagueoflegends.com/cdn/img/perk-images/StatMods/StatModsAttackSpeedIcon.png',
            $runes['shards'][0]['icon'],
        );
    }

    public function test_unknown_shards_still_show_a_name(): void
    {
        $this->seedDataDragon();
        $perks = $this->perks();
        $perks['statPerks']['flex'] = 5999;

        $shards = $this->build($this->participant(['perks' => $perks]))['runes']['shards'];

        $this->assertSame(['name' => 'Rune shard 5999', 'icon' => null], $shards[1]);
    }

    public function test_runes_degrade_when_data_dragon_is_down(): void
    {
        $this->seedDataDragon(available: false);
        Http::fake(['*' => Http::response('', 503)]);

        $runes = $this->build($this->participant(['perks' => $this->perks()]))['runes'];

        $this->assertNull($runes['primary']);
        $this->assertNull($runes['secondary']);
        $this->assertCount(3, $runes['shards']);   // shard names do not need Data Dragon
    }

    public function test_a_participant_without_perks_has_no_runes(): void
    {
        $this->seedDataDragon();

        $this->assertNull($this->build($this->participant(['perks' => null]))['runes']);
        $this->assertNull($this->build($this->participant(['perks' => ['styles' => []]]))['runes']);
    }
}
