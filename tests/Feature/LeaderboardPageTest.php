<?php

namespace Tests\Feature;

use App\Enums\RankedQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class LeaderboardPageTest extends TestCase
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

    public function test_it_explains_how_to_add_players_when_there_are_none(): void
    {
        $this->get('/leaderboard')
            ->assertOk()
            ->assertSee('Belum ada pemain')
            ->assertSee('php artisan players:add', false);
    }

    public function test_it_lists_players_ordered_by_rank_with_their_numbers(): void
    {
        $gold = $this->makePlayer('Goldie');
        $emerald = $this->makePlayer('Emmy', ['display_name' => 'Emerald Emmy']);
        $this->addSnapshot($gold, RankedQueue::Solo, 'GOLD', 'II', 54, wins: 12, losses: 8);
        $this->addSnapshot($emerald, RankedQueue::Solo, 'EMERALD', 'IV', 7, wins: 30, losses: 20);

        $response = $this->get('/leaderboard')->assertOk();

        $response->assertSeeInOrder(['Emerald Emmy', 'Goldie']);
        $response->assertSeeInOrder(['Emerald IV', '7 LP']);
        $response->assertSeeInOrder(['Gold II', '54 LP']);
        $response->assertSee('60.0%');      // 30 wins of 50 games
        $response->assertSee('30M 20K');    // wins and losses (Menang / Kalah)
        $this->assertSame(['Emerald Emmy', 'Goldie'], array_map(fn ($row) => $row['player']->name, $response->viewData('rows')));
    }

    public function test_unranked_players_are_listed_last(): void
    {
        $ranked = $this->makePlayer('Ranked');
        $this->makePlayer('Newbie');
        $this->addSnapshot($ranked, RankedQueue::Solo, 'SILVER', 'III', 10);

        $this->get('/leaderboard')
            ->assertOk()
            ->assertSeeInOrder(['Ranked', 'Newbie', 'Unranked']);
    }

    public function test_the_queue_toggle_switches_between_solo_and_flex(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', 10);
        $this->addSnapshot($player, RankedQueue::Flex, 'PLATINUM', 'I', 99);

        $solo = $this->get('/leaderboard')->assertOk();
        $solo->assertSee('Peringkat Ranked Solo/Duo');
        $solo->assertSee('Gold IV');
        $solo->assertDontSee('Platinum I');
        $solo->assertSee('?queue=flex', false);

        $flex = $this->get('/leaderboard?queue=flex')->assertOk();
        $flex->assertSee('Peringkat Ranked Flex');
        $flex->assertSee('Platinum I');
        $flex->assertDontSee('Gold IV');
    }

    public function test_an_unknown_queue_falls_back_to_solo(): void
    {
        $this->get('/leaderboard?queue=aram')
            ->assertOk()
            ->assertSee('Peringkat Ranked Solo/Duo');
    }

    public function test_inactive_players_are_hidden(): void
    {
        $this->makePlayer('Visible');
        $this->makePlayer('Hidden', ['is_active' => false]);

        $this->get('/leaderboard')->assertOk()->assertSee('Visible')->assertDontSee('Hidden');
    }

    public function test_player_names_are_escaped(): void
    {
        $this->makePlayer('Evil', ['display_name' => '<script>alert(1)</script>']);

        $this->get('/leaderboard')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_it_links_to_player_pages_and_uses_data_dragon_icons(): void
    {
        $player = $this->makePlayer('Alpha');

        $this->get('/leaderboard?queue=flex')
            ->assertOk()
            ->assertSee("/players/{$player->id}?queue=flex", false)
            ->assertSee('https://ddragon.leagueoflegends.com/cdn/16.1.1/img/profileicon/4567.png', false);
    }

    public function test_the_page_only_reads_the_database(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', 10);

        $this->get('/leaderboard')->assertOk();

        Http::assertNothingSent();
    }
}
