<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class DuoPageTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::put('ddragon.version', '16.1.1');
        Http::fake();
    }

    public function test_pairs_are_ranked_by_winrate_together_and_need_enough_games(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');
        $c = $this->makePlayer('Gamma');

        foreach ([true, true, true, false] as $win) {
            $this->addTeamGame([$a, $b], [], $win);      // Alpha & Beta: 3 of 4
        }
        foreach ([true, true, true] as $win) {
            $this->addTeamGame([$a, $c], [], $win);      // Alpha & Gamma: 3 of 3
        }
        $this->addTeamGame([$b, $c], [], true);          // Beta & Gamma: only one game

        $response = $this->get('/duos')->assertOk();
        $ranked = $response->viewData('ranked');

        $this->assertSame(['Alpha', 'Gamma'], [$ranked[0]['first']->name, $ranked[0]['second']->name]);
        $this->assertSame(['Alpha', 'Beta'], [$ranked[1]['first']->name, $ranked[1]['second']->name]);
        $this->assertCount(2, $ranked);
        $this->assertSame(1, $response->viewData('unranked'));
        $response->assertSee('1 duo lain belum punya 3 game bersama');
    }

    public function test_it_points_out_the_most_played_duo_and_the_biggest_rivalry(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');
        $c = $this->makePlayer('Gamma');

        foreach (range(1, 4) as $i) {
            $this->addTeamGame([$a, $b], [], true);
        }
        foreach (range(1, 2) as $i) {
            $this->addTeamGame([$a], [$c], true);
        }

        $response = $this->get('/duos')->assertOk();

        $this->assertSame('Beta', $response->viewData('mostPlayed')['second']->name);
        $this->assertSame(2, $response->viewData('rivalry')['versus']['games']);
        $response->assertSee('Paling sering bareng')->assertSee('Rival terbesar');
    }

    public function test_the_mode_filter_limits_the_games(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');

        foreach (range(1, 3) as $i) {
            $this->addTeamGame([$a, $b], [], true, queueId: 400);
        }

        $this->assertCount(1, $this->get('/duos?mode=normal')->viewData('ranked'));
        $this->assertCount(0, $this->get('/duos?mode=solo')->viewData('ranked'));
        $this->assertCount(1, $this->get('/duos?mode=nonsense')->viewData('ranked'));   // unknown = all modes
    }

    public function test_an_empty_page_renders_and_hidden_players_are_left_out(): void
    {
        $this->get('/duos')->assertOk()->assertSee('Belum ada duo');

        $a = $this->makePlayer('Alpha');
        $ghost = $this->makePlayer('Ghost', ['is_active' => false]);
        foreach (range(1, 3) as $i) {
            $this->addTeamGame([$a, $ghost], [], true);
        }

        $this->get('/duos')->assertOk()->assertDontSee('Ghost')->assertSee('Belum ada duo');
    }

    public function test_the_nav_links_to_the_page(): void
    {
        $this->get('/duos')->assertSee('href="'.route('duos').'"', false);
    }
}
