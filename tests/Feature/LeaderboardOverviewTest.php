<?php

namespace Tests\Feature;

use App\Enums\RankedQueue;
use App\Models\Player;
use App\Services\Stats\LeaderboardExtras;
use App\Services\Stats\LeaderboardPeriod;
use App\Services\Stats\ServerActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class LeaderboardOverviewTest extends TestCase
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

    /** Results are given oldest first. */
    private function playResults(Player $player, array $results, int $queueId = 420): void
    {
        foreach ($results as $i => $win) {
            $this->addGame($player, ['win' => $win], queueId: $queueId, startedAt: now()->subHours(count($results) - $i));
        }
    }

    public function test_form_shows_the_last_results_oldest_first_with_the_streak(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->playResults($player, [true, false, false, false]);

        $form = app(LeaderboardExtras::class)->form([$player->id], RankedQueue::Solo)[$player->id];

        $this->assertSame([true, false, false, false], $form['results']);
        $this->assertSame(3, $form['streak']);
        $this->assertSame('L', $form['streak_type']);
    }

    public function test_form_only_counts_the_chosen_queue_and_skips_remakes(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->playResults($player, [true, true]);
        $this->addGame($player, ['win' => false], duration: 200);                 // remake
        $this->addGame($player, ['win' => false], queueId: 440);                  // flex

        $solo = app(LeaderboardExtras::class)->form([$player->id], RankedQueue::Solo)[$player->id];

        $this->assertSame([true, true], $solo['results']);
        $this->assertSame('W', $solo['streak_type']);
        $this->assertSame(2, $solo['streak']);
    }

    public function test_the_page_shows_form_dots_and_the_streak_badge(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', 10);
        $this->playResults($player, [false, true, true, true]);

        $this->get('/leaderboard')->assertOk()
            ->assertSee('Form')
            ->assertSee('>W3<', false)
            ->assertSee('bg-emerald-400', false);
    }

    public function test_the_trend_line_needs_two_points_and_starts_before_the_window(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', 10, capturedAt: now()->subDays(20));
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', 40, capturedAt: now()->subDays(2));
        $loner = $this->makePlayer('Loner');
        $this->addSnapshot($loner, RankedQueue::Solo, 'GOLD', 'IV', 10, capturedAt: now()->subDays(1));

        $trend = app(LeaderboardExtras::class)->trend([$player->id, $loner->id], RankedQueue::Solo, LeaderboardPeriod::Week);

        $this->assertCount(2, $trend[$player->id]);   // the snapshot before the window + the one inside
        $this->assertCount(1, $trend[$loner->id]);
    }

    public function test_the_period_tabs_change_the_lp_gain_and_the_climber(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', 0, capturedAt: now()->subDays(20));
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', 30, capturedAt: now()->subDays(10));
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', 50, capturedAt: now()->subHours(2));

        $this->get('/leaderboard')->assertOk()->assertSee('Climber of the week')->assertSee('+20');
        $this->get('/leaderboard?period=month')->assertOk()->assertSee('Climber of the month')->assertSee('+50');
        $this->get('/leaderboard?period=all')->assertOk()->assertSee('Climber of all time')->assertSee('+50');
        $this->get('/leaderboard?period=day')->assertOk()->assertSee('Climber of the day');
        // An unknown period falls back to the week.
        $this->get('/leaderboard?period=nonsense')->assertOk()->assertSee('Climber of the week');
        // Every tab is offered and keeps the queue.
        $this->get('/leaderboard?queue=flex')->assertOk()->assertSee('period=month', false)->assertSee('queue=flex', false);
    }

    public function test_the_climber_card_lists_champion_role_and_games(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', 0, capturedAt: now()->subDays(3));
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', 40, capturedAt: now()->subHour());
        foreach (range(1, 3) as $i) {
            $this->addGame($player, ['champion_name' => 'Jinx', 'champion_id' => 222, 'team_position' => 'BOTTOM'], startedAt: now()->subHours($i));
        }
        $this->addGame($player, ['champion_name' => 'Garen', 'champion_id' => 86, 'team_position' => 'TOP'], startedAt: now()->subDays(20));   // outside the week

        $champions = $this->get('/leaderboard')->assertOk()->viewData('climberChampions');

        $this->assertSame([['champion_id' => 222, 'champion_name' => 'Jinx', 'role' => 'Bot', 'games' => 3]], $champions);
    }

    public function test_the_target_column_shows_the_rank_goal_progress(): void
    {
        $player = $this->makePlayer('Alpha', ['goal_tier' => 'PLATINUM', 'goal_division' => 'IV']);
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'I', 50);

        $this->get('/leaderboard')->assertOk()->assertSee('Target')->assertSee('Platinum IV');
    }

    public function test_the_overview_sections_below_the_table_are_shown(): void
    {
        [$a, $b] = [$this->makePlayer('Alpha'), $this->makePlayer('Beta')];
        $this->addSnapshot($a, RankedQueue::Solo, 'GOLD', 'IV', 10);
        foreach (range(1, 3) as $i) {
            $this->addTeamGame([$a, $b], [], $i !== 3, startedAt: now()->subDays($i));
        }

        $this->get('/leaderboard')->assertOk()
            ->assertSeeInOrder(['Best this week', 'Role Coverage', 'Top champion grup', 'Duo terbaik', 'Winrate per role', 'When the Server Plays', 'Per jam'])
            ->assertSee('Alpha &amp; Beta', false)
            ->assertSee('66.7%');   // 2 wins in 3 games together
    }

    public function test_duos_with_fewer_than_three_games_are_not_listed(): void
    {
        [$a, $b] = [$this->makePlayer('Alpha'), $this->makePlayer('Beta')];
        $this->addSnapshot($a, RankedQueue::Solo, 'GOLD', 'IV', 10);
        $this->addTeamGame([$a, $b], [], true);

        $this->assertSame([], $this->get('/leaderboard')->viewData('duos'));
    }

    public function test_server_activity_is_counted_in_wib(): void
    {
        $player = $this->makePlayer('Alpha');
        // 2026-11-23 is a Monday. 05:30 UTC is 12:30 WIB.
        $this->addGame($player, ['win' => true], startedAt: Carbon::parse('2026-11-23 05:30:00', 'UTC'));
        $this->addGame($player, ['win' => false], startedAt: Carbon::parse('2026-11-23 05:45:00', 'UTC'));
        // 18:30 UTC on Sunday is 01:30 WIB on Monday.
        $this->addGame($player, ['win' => true], startedAt: Carbon::parse('2026-11-22 18:30:00', 'UTC'));
        $this->addGame($player, ['win' => true], duration: 200, startedAt: Carbon::parse('2026-11-23 05:50:00', 'UTC'));   // remake

        $activity = app(ServerActivity::class)->build();

        $this->assertSame(['games' => 2, 'wins' => 1], $activity['cells'][0][12]);
        $this->assertSame(['games' => 1, 'wins' => 1], $activity['cells'][0][1]);
        $this->assertSame(3, $activity['total']);
        $this->assertSame(['day' => 'Sen', 'hour' => 12, 'games' => 2], $activity['busiest']);
    }

    public function test_the_page_only_reads_the_database(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', 10);
        $this->addGame($player);

        $this->get('/leaderboard?period=all')->assertOk()->assertSee('Paling ramai');

        // Champion icons may ask Data Dragon for its static files, but the Riot API is never called.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.riotgames.com'));
    }
}
