<?php

namespace Tests\Feature;

use App\Enums\MatchQueue;
use App\Services\Stats\GroupBuilds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class GroupBuildsTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::fake();
        Cache::put('ddragon.version', '16.1.1');
        Cache::put('ddragon.items.16.1.1', [
            3020 => ['name' => "Sorcerer's Shoes", 'skip' => false],
            6655 => ['name' => "Luden's Echo", 'skip' => false],
            3340 => ['name' => 'Stealth Ward', 'skip' => true],
        ]);
        Cache::put('ddragon.runes.16.1.1', [
            8100 => ['id' => 8100, 'name' => 'Domination', 'icon' => 'perk-images/Styles/7200_Domination.png',
                'slots' => [[['id' => 8112, 'name' => 'Electrocute', 'icon' => 'perk-images/Styles/Domination/Electrocute/Electrocute.png'], ['id' => 8128, 'name' => 'Dark Harvest', 'icon' => 'x.png']], [['id' => 8126, 'name' => 'Cheap Shot', 'icon' => 'y.png']]]],
            8200 => ['id' => 8200, 'name' => 'Sorcery', 'icon' => 'perk-images/Styles/7202_Sorcery.png',
                'slots' => [[['id' => 8214, 'name' => 'Summon Aery', 'icon' => 'z.png']], [['id' => 8226, 'name' => 'Manaflow Band', 'icon' => 'w.png']]]],
        ]);
    }

    private function perks(int $keystone = 8112, int $first = 8126): array
    {
        return [
            'statPerks' => ['offense' => 5008, 'flex' => 5008, 'defense' => 5001],
            'styles' => [
                ['style' => 8100, 'selections' => [['perk' => $keystone], ['perk' => $first]]],
                ['style' => 8200, 'selections' => [['perk' => 8226]]],
            ],
        ];
    }

    private function play($player, int $games, int $wins, array $stats = []): void
    {
        foreach (range(1, $games) as $i) {
            $this->addGame($player, ['champion_id' => 103, 'champion_name' => 'Ahri', 'win' => $i <= $wins] + $stats);
        }
    }

    public function test_it_lists_the_champions_members_played_most_played_first(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $this->play($alpha, 3, 2);
        $this->addGame($alpha, ['champion_id' => 222, 'champion_name' => 'Jinx']);

        $champions = app(GroupBuilds::class)->champions();

        $this->assertSame(['Ahri', 'Jinx'], array_column($champions, 'name'));
        $this->assertSame([3, 67], [$champions[0]['games'], $champions[0]['winrate']]);
    }

    public function test_rune_pages_are_grouped_by_the_full_page_with_games_and_winrate(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $this->play($alpha, 3, 3, ['perks' => $this->perks()]);                   // Electrocute page: 3 wins
        $this->play($alpha, 1, 0, ['perks' => $this->perks(keystone: 8128)]);     // Dark Harvest page: 1 loss

        $pages = app(GroupBuilds::class)->forChampion(103)['rune_pages'];

        $this->assertCount(2, $pages);
        $this->assertSame([3, 100], [$pages[0]['games'], $pages[0]['winrate']]);
        $this->assertSame('Domination', $pages[0]['primary']['name']);
        $this->assertSame(['Electrocute', 'Cheap Shot'], array_column($pages[0]['primary']['runes'], 'name'));
        $this->assertSame('Sorcery', $pages[0]['secondary']['name']);
        $this->assertSame(['Adaptive Force', 'Adaptive Force', 'Health Scaling'], array_column($pages[0]['shards'], 'name'));
        $this->assertSame('Dark Harvest', $pages[1]['primary']['runes'][0]['name']);
    }

    public function test_items_count_games_and_hide_wards_and_unknown_items(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $this->play($alpha, 2, 2, ['items' => [3020, 6655, 0, 0, 0, 0, 3340]]);
        $this->play($alpha, 1, 0, ['items' => [3020, 9999, 0, 0, 0, 0, 3340]]);

        $items = collect(app(GroupBuilds::class)->forChampion(103)['items'])->keyBy('name');

        $this->assertSame(['Sorcerer\'s Shoes', 'Luden\'s Echo'], $items->keys()->all());
        $this->assertSame([3, 67], [$items["Sorcerer's Shoes"]['games'], $items["Sorcerer's Shoes"]['winrate']]);
        $this->assertSame([2, 100], [$items["Luden's Echo"]['games'], $items["Luden's Echo"]['winrate']]);
    }

    public function test_the_skill_order_is_the_most_common_priority(): void
    {
        $alpha = $this->makePlayer('Alpha');
        // Q maxed first: levels 1,4,5,7,9 are Q; W/E fill the rest.
        $order = [1, 2, 3, 1, 1, 4, 1, 2, 1, 4, 2, 4, 2, 2, 3, 4, 3, 3];
        $this->play($alpha, 2, 1, ['skill_order' => $order]);

        $skills = app(GroupBuilds::class)->forChampion(103)['skills'];

        $this->assertSame(2, $skills['games']);
        $this->assertStringStartsWith('Q', $skills['priority']);
    }

    public function test_only_active_members_remakes_and_the_chosen_filters_count(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $beta = $this->makePlayer('Beta');
        $ghost = $this->makePlayer('Ghost', ['is_active' => false]);
        $this->play($alpha, 2, 1);
        $this->play($beta, 1, 1);
        $this->play($ghost, 5, 5);
        $this->addGame($alpha, ['champion_id' => 103, 'champion_name' => 'Ahri'], duration: 200);
        $this->addGame($alpha, ['champion_id' => 103, 'champion_name' => 'Ahri'], queueId: 400);

        $this->assertSame(4, app(GroupBuilds::class)->forChampion(103)['games']);
        $this->assertSame(2, app(GroupBuilds::class)->forChampion(103, player: $alpha)['games'] - 1);   // Alpha: 2 solo + 1 normal - 1
        $this->assertSame(1, app(GroupBuilds::class)->forChampion(103, MatchQueue::Normal)['games']);
        $this->assertNull(app(GroupBuilds::class)->forChampion(999));
    }

    public function test_the_pages(): void
    {
        $alpha = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);
        $this->play($alpha, 2, 2, ['perks' => $this->perks(), 'items' => [3020, 6655, 0, 0, 0, 0, 3340]]);

        $this->get('/builds')->assertOk()->assertSee('Build member')->assertSee('Ahri')->assertSee('href="'.route('builds.show', 103).'"', false);

        $this->get('/builds/103')
            ->assertOk()
            ->assertSee('Rune paling sering')
            ->assertSee('Electrocute')
            ->assertSee("Sorcerer's Shoes")
            ->assertSee('Baru 2 game')
            ->assertSee('Ayam');

        $this->get('/builds/103?player='.$alpha->id.'&mode=solo')->assertOk()->assertSee('Ayam · Solo/Duo');
        $this->get('/builds/999')->assertRedirect(route('builds'));
        $this->get('/builds/abc')->assertNotFound();
    }

    public function test_a_member_who_never_played_the_champion_gets_a_friendly_page_not_a_404(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $beta = $this->makePlayer('Beta', ['display_name' => 'Bebek']);
        $this->play($alpha, 2, 1);

        $this->get('/builds/103?player='.$beta->id)
            ->assertOk()
            ->assertSee('Bebek belum pernah memainkan Ahri')
            ->assertSee('Lihat semua member')
            ->assertSee('Ahri')                       // the header and the filters stay
            ->assertDontSee('Rune paling sering');

        $this->get('/builds/103?mode=aram')->assertOk()->assertSee('belum pernah memainkan Ahri di ARAM');
    }

    public function test_an_unknown_champion_goes_back_to_the_list_with_a_notice(): void
    {
        $this->followingRedirects()->get('/builds/999')->assertOk()->assertSee('Champion itu belum pernah dimainkan member.');
    }

    public function test_the_index_explains_an_empty_selection_and_the_nav_links_to_it(): void
    {
        $this->get('/builds')->assertOk()->assertSee('Belum ada game untuk pilihan ini')->assertSee('href="'.route('builds').'"', false);
    }

    public function test_the_skill_table_follows_the_most_common_pattern_per_level(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $common = [1, 2, 3, 1, 1, 4, 1, 2, 1, 4, 2, 4, 2, 2, 3, 4, 3, 3];
        $odd = [3, 2, 1, 3, 3, 4, 3, 2, 3, 4, 2, 4, 2, 2, 1, 4, 1, 1];
        $this->play($alpha, 2, 1, ['skill_order' => $common]);
        $this->play($alpha, 1, 1, ['skill_order' => $odd]);

        $table = app(GroupBuilds::class)->forChampion(103)['skill_table'];

        $this->assertSame(3, $table['games']);
        $this->assertSame(18, $table['levels']);
        // The two identical games win over the odd one: Q is taken at level 1.
        $this->assertSame([1, 4, 5, 7, 9], $table['grid']['Q']);
        $this->assertSame([6, 10, 12, 16], $table['grid']['R']);
    }

    public function test_the_skill_table_is_empty_without_timeline_data(): void
    {
        $this->play($this->makePlayer('Alpha'), 2, 1);

        $this->assertNull(app(GroupBuilds::class)->forChampion(103)['skill_table']);
        $this->get('/builds/103')->assertOk()->assertSee('Belum ada data urutan skill');
    }

    public function test_summoner_spell_pairs_are_counted_whatever_their_order(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $this->play($alpha, 1, 1, ['summoner_spells' => [4, 14]]);
        $this->play($alpha, 1, 0, ['summoner_spells' => [14, 4]]);
        $this->play($alpha, 1, 1, ['summoner_spells' => [4, 12]]);

        $spells = app(GroupBuilds::class)->forChampion(103)['spells'];

        $this->assertCount(2, $spells);
        $this->assertSame([2, 50], [$spells[0]['games'], $spells[0]['winrate']]);
        $this->get('/builds/103')->assertOk()->assertSee('Spell yang sering dipakai');
    }

    public function test_top_players_need_more_than_five_games_and_are_sorted_by_winrate_then_games(): void
    {
        [$a, $b, $c, $d] = array_map(fn ($n) => $this->makePlayer("T{$n}"), range(1, 4));
        $this->play($a, 8, 4);   // 50%, 8 games
        $this->play($b, 6, 6);   // 100%, 6 games
        $this->play($c, 10, 10); // 100%, 10 games
        $this->play($d, 5, 5);   // exactly 5 games: not enough

        $players = app(GroupBuilds::class)->forChampion(103)['players'];

        $this->assertSame(['T3', 'T2', 'T1'], array_map(fn ($p) => $p['player']->name, $players));
        $this->get('/builds/103')->assertOk()->assertSee('Top Players')->assertDontSee('Belum ada member dengan lebih dari');
    }

    public function test_top_players_explains_when_nobody_has_enough_games(): void
    {
        $this->play($this->makePlayer('Alpha'), 5, 5);

        $this->assertSame([], app(GroupBuilds::class)->forChampion(103)['players']);
        $this->get('/builds/103')->assertOk()->assertSee('Belum ada member dengan lebih dari 5 game');
    }
}
