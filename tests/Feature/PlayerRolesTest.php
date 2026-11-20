<?php

namespace Tests\Feature;

use App\Services\Stats\PerformanceStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class PlayerRolesTest extends TestCase
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

    public function test_it_summarises_games_winrate_and_kda_per_lane(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, ['team_position' => 'JUNGLE', 'win' => true, 'kills' => 4, 'deaths' => 2, 'assists' => 8]);
        $this->addGame($player, ['team_position' => 'JUNGLE', 'win' => false, 'kills' => 2, 'deaths' => 2, 'assists' => 4]);
        $this->addGame($player, ['team_position' => 'JUNGLE', 'win' => true, 'kills' => 3, 'deaths' => 2, 'assists' => 6]);
        $this->addGame($player, ['team_position' => 'MIDDLE', 'win' => false]);
        $this->addGame($player, ['team_position' => '']);                      // ARAM-like: no lane
        $this->addGame($player, ['team_position' => 'TOP'], duration: 200);    // remake

        $roles = app(PerformanceStats::class)->roles($player->id);

        $this->assertCount(2, $roles);
        $this->assertSame('Jungle', $roles[0]['label']);
        $this->assertSame(3, $roles[0]['games']);
        $this->assertSame(75, $roles[0]['share']);
        $this->assertSame(67, $roles[0]['winrate']);
        $this->assertEquals(4.5, $roles[0]['kda']);   // (9 + 18) / 6
        $this->assertSame('Mid', $roles[1]['label']);
        $this->assertSame(0, $roles[1]['winrate']);
    }

    public function test_the_player_page_shows_the_lanes_and_links_to_all_matches(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, ['team_position' => 'BOTTOM']);

        $this->get(route('players.show', $player))
            ->assertOk()
            ->assertSee('Lane')
            ->assertSee('Bot')
            ->assertSee('href="'.route('matches.index', ['player' => $player->id]).'"', false);
    }

    public function test_the_all_matches_link_keeps_the_selected_mode(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, [], queueId: 400);

        $this->get(route('players.show', ['player' => $player, 'queue' => 'normal']))
            ->assertSee('href="'.e(route('matches.index', ['player' => $player->id, 'mode' => 'normal'])).'"', false);
    }
}
