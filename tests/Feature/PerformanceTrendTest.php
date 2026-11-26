<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Services\Stats\PerformanceTrend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class PerformanceTrendTest extends TestCase
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

    /** 40 older games with 100 CS (3.3/min) and then 10 recent ones with $recentCs. */
    private function playerWithHistory(int $recentCs = 200, int $older = 40): Player
    {
        $player = $this->makePlayer('Alpha');

        foreach (range(1, $older) as $i) {
            $this->addGame($player, ['total_minions_killed' => 100, 'neutral_minions_killed' => 0], startedAt: now()->subDays(30)->addMinutes($i));
        }
        foreach (range(1, 10) as $i) {
            $this->addGame($player, ['total_minions_killed' => $recentCs, 'neutral_minions_killed' => 0], startedAt: now()->subHours(10)->addMinutes($i));
        }

        return $player;
    }

    public function test_it_compares_the_last_ten_games_with_the_ones_before(): void
    {
        $player = $this->playerWithHistory(recentCs: 200);

        $trend = app(PerformanceTrend::class)->forPlayer($player->id);

        $this->assertSame(10, $trend['recent_games']);
        $this->assertSame(40, $trend['before_games']);
        $cs = collect($trend['metrics'])->firstWhere('key', 'cs_per_min');
        $this->assertSame(6.7, $cs['recent']);   // 200 CS in 30 minutes
        $this->assertSame(3.3, $cs['before']);
        $this->assertSame(100, $cs['change']);
        $this->assertSame('cs_per_min', app(PerformanceTrend::class)->notable($trend)['key']);
    }

    public function test_a_small_change_is_not_notable(): void
    {
        $player = $this->playerWithHistory(recentCs: 105);

        $trend = app(PerformanceTrend::class)->forPlayer($player->id);

        $this->assertNull(app(PerformanceTrend::class)->notable($trend));
    }

    public function test_there_is_no_trend_without_enough_games_on_both_sides(): void
    {
        $few = $this->playerWithHistory(older: 3);

        $this->assertNull(app(PerformanceTrend::class)->forPlayer($few->id));
        $this->assertNull(app(PerformanceTrend::class)->forPlayer(9999));
    }

    public function test_remakes_do_not_count(): void
    {
        $player = $this->playerWithHistory();
        $before = app(PerformanceTrend::class)->forPlayer($player->id);

        $this->addGame($player, ['total_minions_killed' => 0], duration: 200);

        $this->assertSame($before, app(PerformanceTrend::class)->forPlayer($player->id));
    }

    public function test_the_player_page_shows_the_trend(): void
    {
        $player = $this->playerWithHistory();

        $this->get(route('players.show', $player))
            ->assertOk()
            ->assertSee('Tren performa')
            ->assertSee('10 game terakhir dibanding 40 game sebelumnya')
            ->assertSee('▲ +100%');
    }

    public function test_the_player_page_has_no_trend_section_for_a_new_player(): void
    {
        $player = $this->makePlayer('Newbie');
        $this->addGame($player);

        $this->get(route('players.show', $player))->assertOk()->assertDontSee('Tren performa');
    }
}
