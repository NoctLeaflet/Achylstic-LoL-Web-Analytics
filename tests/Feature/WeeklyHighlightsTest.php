<?php

namespace Tests\Feature;

use App\Enums\MatchQueue;
use App\Services\Stats\RoleCoverage;
use App\Services\Stats\WeeklyHighlights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class WeeklyHighlightsTest extends TestCase
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

    private function best(): array
    {
        return collect(app(WeeklyHighlights::class)->build()['best'])->keyBy('key')->all();
    }

    public function test_it_picks_the_standout_games_of_the_week(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $beta = $this->makePlayer('Beta');

        $this->addGame($alpha, ['kills' => 20, 'deaths' => 1, 'assists' => 5, 'champion_name' => 'Jinx']);
        $this->addGame($beta, ['kills' => 2, 'deaths' => 3, 'assists' => 18]);
        $this->addGame($beta, ['win' => false, 'kills' => 1, 'deaths' => 12, 'assists' => 2]);
        $this->addGame($alpha, ['win' => false, 'kills' => 9, 'deaths' => 2, 'assists' => 3]);

        $best = $this->best();

        $this->assertSame('Alpha', $best['carry']['game']['player']->name);
        $this->assertEquals(25, $best['carry']['game']['kda']);
        $this->assertSame('Beta', $best['comeback']['game']['player']->name);
        $this->assertSame('18 assist dalam satu kemenangan', $best['comeback']['blurb']);
        $this->assertSame('25 takedown dalam satu game', $best['chaos']['blurb']);
        $this->assertSame('Beta', $best['tilt']['game']['player']->name);
        $this->assertSame(1, $best['tilt']['game']['kills']);
    }

    public function test_it_ignores_old_games_remakes_and_hidden_players(): void
    {
        $shown = $this->makePlayer('Shown');
        $hidden = $this->makePlayer('Hidden', ['is_active' => false]);

        $this->addGame($shown, [], startedAt: now()->subDays(8));
        $this->addGame($shown, [], duration: 200);
        $this->addGame($hidden);

        $this->assertSame(0, app(WeeklyHighlights::class)->build()['games']);
        $this->assertSame([], app(WeeklyHighlights::class)->build()['best']);
    }

    public function test_awards_need_enough_games_and_find_the_right_players(): void
    {
        $loyal = $this->makePlayer('Loyal');
        $wanderer = $this->makePlayer('Wanderer');
        $eyes = $this->makePlayer('Eyes');
        $rookie = $this->makePlayer('Rookie');

        foreach (['Jinx', 'Jinx', 'Jinx'] as $champion) {
            $this->addGame($loyal, ['champion_name' => $champion, 'vision_score' => 10]);
        }
        foreach (['Ahri', 'Zed', 'Lux'] as $champion) {
            $this->addGame($wanderer, ['champion_name' => $champion, 'vision_score' => 20]);
        }
        foreach (['Ahri', 'Ahri', 'Zed'] as $champion) {
            $this->addGame($eyes, ['champion_name' => $champion, 'vision_score' => 90]);
        }
        $this->addGame($rookie, ['vision_score' => 500]);

        $awards = collect(app(WeeklyHighlights::class)->build()['awards'])->keyBy('key');

        $this->assertSame('Loyal', $awards['pony']['player']->name);
        $this->assertSame('100% Jinx', $awards['pony']['value']);
        $this->assertSame($wanderer->id, $awards['jack']['player']->id);
        $this->assertSame('3 champs', $awards['jack']['value']);
        $this->assertSame('Eyes', $awards['vision']['player']->name);
        $this->assertSame('90.0 avg', $awards['vision']['value']);
        $this->assertNotContains('Rookie', $awards->pluck('player.game_name')->all());
    }

    public function test_role_coverage_separates_mains_from_fills_and_shows_gaps(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $beta = $this->makePlayer('Beta');

        foreach (range(1, 6) as $i) {
            $this->addGame($alpha, ['team_position' => 'JUNGLE']);
        }
        foreach (range(1, 2) as $i) {
            $this->addGame($alpha, ['team_position' => 'MIDDLE']);   // 25%: fill
        }
        $this->addGame($alpha, ['team_position' => 'TOP']);          // one game: ignored
        $this->addGame($beta, ['team_position' => 'MIDDLE']);
        $this->addGame($beta, ['team_position' => 'MIDDLE']);

        $roles = collect(app(RoleCoverage::class)->build())->keyBy('position');

        $this->assertSame(['Alpha'], array_map(fn ($e) => $e['player']->name, $roles['JUNGLE']['mains']));
        $this->assertSame(['Beta'], array_map(fn ($e) => $e['player']->name, $roles['MIDDLE']['mains']));
        $this->assertSame(['Alpha'], array_map(fn ($e) => $e['player']->name, $roles['MIDDLE']['fills']));
        $this->assertSame([], $roles['TOP']['mains']);
        $this->assertSame([], $roles['UTILITY']['mains']);
    }

    public function test_best_this_week_can_be_limited_to_one_game_mode(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $this->addGame($alpha, ['champion_name' => 'Jinx'], queueId: 420);
        $this->addGame($alpha, ['champion_name' => 'Garen'], queueId: 400);

        $all = app(WeeklyHighlights::class)->build();
        $normal = app(WeeklyHighlights::class)->build(bestMode: MatchQueue::Normal);
        $flex = app(WeeklyHighlights::class)->build(bestMode: MatchQueue::Flex);

        $this->assertSame(2, $all['games']);
        $this->assertSame(['Garen'], collect($normal['best'])->pluck('game.champion_name')->unique()->all());
        $this->assertSame([], $flex['best']);
        // The awards are not affected by the filter.
        $this->assertSame($all['awards'], $normal['awards']);
    }

    public function test_the_page_has_a_mode_dropdown_that_filters_best_this_week(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $this->addGame($alpha, ['champion_name' => 'Jinx'], queueId: 420);

        $this->get('/leaderboard')
            ->assertOk()
            ->assertSee('<option value="normal"', false)
            ->assertSee('Solo/Duo');

        $this->get('/leaderboard?best=normal')
            ->assertOk()
            ->assertSee('<option value="normal" selected>', false)
            ->assertSee('Belum ada game Normal Draft minggu ini');

        // Unknown or untracked values fall back to all modes.
        $this->get('/leaderboard?best=nonsense')->assertOk()->assertSee('CARRY');
    }

    public function test_the_leaderboard_page_shows_the_sections(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $this->addGame($alpha);

        $this->get('/leaderboard')
            ->assertOk()
            ->assertSee('Best this week')
            ->assertSee('CARRY')
            ->assertSee('This week')
            ->assertSee('Role Coverage')
            ->assertSee(route('matches.show', 'SG2_100001'), false);
    }
}
