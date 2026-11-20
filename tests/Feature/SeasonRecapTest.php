<?php

namespace Tests\Feature;

use App\Enums\RankedQueue;
use App\Services\Stats\SeasonRecap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class SeasonRecapTest extends TestCase
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

    public function test_the_season_starts_on_the_configured_day_or_on_new_year(): void
    {
        $this->freezeTime(fn () => null);
        config(['services.recap.season_start' => '2026-01-08']);
        $this->assertSame('2026-01-08', SeasonRecap::seasonStart()->toDateString());

        config(['services.recap.season_start' => null]);
        $this->assertSame(now()->year.'-01-01', SeasonRecap::seasonStart()->toDateString());

        config(['services.recap.season_start' => 'not a date']);
        $this->assertSame(now()->year.'-01-01', SeasonRecap::seasonStart()->toDateString());
    }

    public function test_it_summarises_the_games_of_the_period(): void
    {
        $player = $this->makePlayer('Alpha');
        $since = now()->subDays(30);

        foreach ([true, true, true, false] as $i => $win) {
            $this->addGame($player, ['win' => $win, 'kills' => 6, 'deaths' => 2, 'assists' => 4, 'champion_name' => 'Jinx', 'champion_id' => 222, 'team_position' => 'BOTTOM'], duration: 1800, startedAt: now()->subDays(10 - $i));
        }
        $this->addGame($player, ['champion_name' => 'Ahri', 'champion_id' => 103], startedAt: now()->subDays(5));
        $this->addGame($player, ['champion_name' => 'Old'], startedAt: now()->subDays(60));         // before the period
        $this->addGame($player, ['champion_name' => 'Remake'], duration: 200, startedAt: now()->subDays(2));

        $recap = app(SeasonRecap::class)->forPlayer($player, $since);

        $this->assertSame(5, $recap['games']);
        $this->assertSame(4, $recap['wins']);        // 3 Jinx wins + the default winning Ahri game
        $this->assertSame(80, $recap['winrate']);
        $this->assertEquals(2.5, $recap['hours']);   // 5 games of 30 minutes
        $this->assertSame(['Jinx', 'Ahri'], array_column($recap['champions'], 'name'));
        $this->assertSame(4, $recap['champions'][0]['games']);
        $this->assertSame(75, $recap['champions'][0]['winrate']);
        $this->assertSame('Bot', $recap['role']['label']);
        $this->assertSame(3, $recap['longest_streak']);
        $this->assertNotNull($recap['best_game']['match_id']);
    }

    public function test_the_rank_change_uses_the_snapshot_before_the_period(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, [], startedAt: now()->subDays(5));
        $this->addSnapshot($player, RankedQueue::Solo, 'SILVER', 'I', 50, capturedAt: now()->subDays(60));
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', 20, capturedAt: now()->subDays(2));

        $rank = app(SeasonRecap::class)->forPlayer($player, now()->subDays(30))['rank'];

        $this->assertSame('Silver I · 50 LP', $rank['from']);
        $this->assertSame('Gold IV · 20 LP', $rank['to']);
        $this->assertSame(70, $rank['change']);     // Silver I 50 LP = 1150, Gold IV 20 LP = 1220
    }

    public function test_only_achievements_earned_in_the_period_are_listed(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, ['kills' => 22], startedAt: now()->subDays(100));   // earned long ago
        $this->addGame($player, ['assists' => 26], startedAt: now()->subDays(3));   // earned recently

        $names = array_column(app(SeasonRecap::class)->forPlayer($player, now()->subDays(30))['achievements'], 'name');

        $this->assertSame(['Rekan Setia'], $names);
    }

    public function test_it_is_null_without_games_in_the_period(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, [], startedAt: now()->subDays(60));

        $this->assertNull(app(SeasonRecap::class)->forPlayer($player, now()->subDays(30)));
    }

    public function test_the_page_shows_the_card_and_the_period_tabs(): void
    {
        $player = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);
        $this->addGame($player, ['champion_name' => 'Jinx', 'champion_id' => 222], startedAt: now()->subDays(3));
        config(['services.recap.season_start' => now()->subDays(20)->toDateString()]);

        $this->get(route('players.recap', $player))
            ->assertOk()
            ->assertSee('Rekap Season ini')
            ->assertSee('Ayam')
            ->assertSee('Champion favorit')
            ->assertSee('Game terbaik')
            ->assertSee('Bagikan tautan')
            ->assertSee('90 hari');

        $this->get(route('players.recap', ['player' => $player, 'period' => '30d']))->assertSee('Rekap 30 hari');
        $this->get(route('players.recap', ['player' => $player, 'period' => 'nonsense']))->assertSee('Rekap Season ini');
    }

    public function test_the_page_explains_an_empty_period_and_the_player_page_links_to_it(): void
    {
        $player = $this->makePlayer('Alpha');

        $this->get(route('players.recap', $player))->assertOk()->assertSee('belum punya game di periode ini');
        $this->get(route('players.show', $player))->assertSee('href="'.route('players.recap', $player).'"', false);
    }
}
