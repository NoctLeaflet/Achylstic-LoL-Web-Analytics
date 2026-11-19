<?php

namespace Tests\Feature;

use App\Enums\RankedQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class PlayerPageTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::put('ddragon.version', '16.1.1');
        Cache::put('ddragon.champions.16.1.1', []);   // champion images by id; empty = fall back to names
        Http::fake();
    }

    public function test_unknown_players_give_a_404(): void
    {
        $this->get('/players/999')->assertNotFound();
    }

    public function test_it_shows_rank_averages_top_champions_and_recent_matches(): void
    {
        $player = $this->makePlayer('Alpha', ['display_name' => 'Ayam', 'summoner_level' => 321]);
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'II', 54, wins: 12, losses: 8);

        // 2 games on Ahri (1 win), 1 on Zed (loss).
        $this->addGame($player, ['champion_name' => 'Ahri', 'win' => true, 'kills' => 10, 'deaths' => 2, 'assists' => 8], duration: 1800);
        $this->addGame($player, ['champion_name' => 'Ahri', 'win' => false, 'kills' => 2, 'deaths' => 8, 'assists' => 4], duration: 1200);
        $this->addGame($player, ['champion_id' => 238, 'champion_name' => 'Zed', 'win' => false, 'kills' => 3, 'deaths' => 5, 'assists' => 1]);

        $response = $this->get("/players/{$player->id}")->assertOk();

        $response->assertSee('Ayam');
        $response->assertSee('Alpha#SG2');
        $response->assertSee('Level 321');
        $response->assertSee('Gold II');
        $response->assertSee('54 LP');
        $response->assertSee('12M 8K');

        // Averages of the 3 counted games.
        $this->assertSame(3, $response->viewData('stats')['games']);
        $response->assertSee('Statistik rata-rata');
        $response->assertSee('Champion teratas');
        $response->assertSeeInOrder(['Ahri', 'Zed']);
        $response->assertSee('2 game');

        // Recent matches: newest first, with the kills/deaths/assists line.
        $response->assertSee('Match terakhir');
        $response->assertSee('3 / 5 / 1');
        $response->assertSee('10 / 2 / 8');
        $response->assertSee('https://ddragon.leagueoflegends.com/cdn/16.1.1/img/champion/Ahri.png', false);
    }

    public function test_the_lp_chart_data_is_handed_to_chartjs_as_json(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'II', 54, capturedAt: now()->subDay());
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'I', 10);

        $response = $this->get("/players/{$player->id}?queue=solo")->assertOk();

        $response->assertSee('x-data="lpChart(', false);
        $chart = $response->viewData('lpChart');
        $this->assertCount(1, $chart['datasets']);
        $this->assertSame([1454, 1510], array_column($chart['datasets'][0]['points'], 'y'));
        $this->assertNotEmpty($chart['ticks']);
        $response->assertSee('Gold II · 54 LP', false);
    }

    public function test_it_explains_what_to_do_when_there_is_no_data_yet(): void
    {
        $player = $this->makePlayer('Alpha');

        $this->get("/players/{$player->id}?queue=solo")
            ->assertOk()
            ->assertSee('Unranked di Solo/Duo')
            ->assertSee('riot:sync-ranks')
            ->assertSee('riot:sync-matches')
            ->assertDontSee('x-data="lpChart(', false);
    }

    public function test_remakes_are_listed_but_not_counted(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, ['kills' => 4, 'deaths' => 2, 'assists' => 4], duration: 1800);
        $this->addGame($player, ['kills' => 0, 'deaths' => 0, 'assists' => 0], duration: 200);

        $response = $this->get("/players/{$player->id}")->assertOk();

        $this->assertSame(1, $response->viewData('stats')['games']);
        $this->assertCount(2, $response->viewData('recentMatches'));
        $response->assertSee('Remake');
        $response->assertSee('Match remake (di bawah 5 menit) tidak dihitung');
    }

    public function test_the_queue_parameter_filters_everything_on_the_page(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', 10);
        $this->addSnapshot($player, RankedQueue::Flex, 'PLATINUM', 'I', 99);
        $this->addGame($player, ['champion_name' => 'Ahri'], queueId: 420);
        $this->addGame($player, ['champion_name' => 'Zed', 'champion_id' => 238], queueId: 440);

        $solo = $this->get("/players/{$player->id}?queue=solo")->assertOk();
        $solo->assertSee('Gold IV');
        $solo->assertDontSee('Platinum I');
        $this->assertSame(1, $solo->viewData('stats')['games']);
        $this->assertSame(['Ahri'], array_column($solo->viewData('topChampions'), 'champion_name'));

        $flex = $this->get("/players/{$player->id}?queue=flex")->assertOk();
        $flex->assertSee('Platinum I');
        $flex->assertDontSee('Gold IV');
        $this->assertSame(['Zed'], array_column($flex->viewData('topChampions'), 'champion_name'));
        $this->assertCount(1, $flex->viewData('recentMatches'));
    }

    public function test_it_links_back_and_to_the_compare_page(): void
    {
        $player = $this->makePlayer('Alpha');

        $this->get("/players/{$player->id}?queue=flex")
            ->assertOk()
            ->assertSee('?queue=flex', false)
            ->assertSee("/compare?players={$player->id}&amp;queue=flex", false);
    }

    public function test_the_page_only_reads_the_database(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addGame($player);

        $this->get("/players/{$player->id}")->assertOk();

        Http::assertNothingSent();
    }
}
