<?php

namespace Tests\Feature;

use App\Enums\RankedQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

/**
 * Tahap 2 on the pages: climber of the week, duo / head-to-head records.
 */
class TeamStatsPagesTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::put('ddragon.version', '16.1.1');
        Http::fake();
        $this->freezeTime();
    }

    // ---- Climber of the week ---------------------------------------------

    public function test_the_leaderboard_highlights_the_climber_of_the_week(): void
    {
        $up = $this->makePlayer('Upper', ['display_name' => 'Naik']);
        $down = $this->makePlayer('Downer');

        $this->addSnapshot($up, RankedQueue::Solo, 'GOLD', 'IV', 10, capturedAt: now()->subDays(6));
        $this->addSnapshot($up, RankedQueue::Solo, 'GOLD', 'III', 5, capturedAt: now()->subHour());
        $this->addSnapshot($down, RankedQueue::Solo, 'GOLD', 'II', 50, capturedAt: now()->subDays(6));
        $this->addSnapshot($down, RankedQueue::Solo, 'GOLD', 'II', 20, capturedAt: now()->subHour());

        $response = $this->get('/')->assertOk();

        $response->assertSee('Climber of the week');
        $response->assertSee('Gold IV · 10 LP');
        $response->assertSee('Gold III · 5 LP');
        $response->assertSee('+95');
        $this->assertSame('Naik', $response->viewData('climber')['player']->name);
        $this->assertSame(95, $response->viewData('weeklyGains')[$up->id]);
        $this->assertSame(-30, $response->viewData('weeklyGains')[$down->id]);
        $response->assertSee('+95 LP');
        $response->assertSee('-30 LP');
    }

    public function test_there_is_no_climber_when_nobody_gained_lp(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'II', 50, capturedAt: now()->subDays(6));
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'II', 20, capturedAt: now()->subHour());

        $this->get('/')->assertOk()->assertDontSee('Climber of the week');
    }

    public function test_the_climber_follows_the_selected_queue(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addSnapshot($player, RankedQueue::Flex, 'SILVER', 'IV', 0, capturedAt: now()->subDays(5));
        $this->addSnapshot($player, RankedQueue::Flex, 'SILVER', 'IV', 40, capturedAt: now()->subHour());

        $this->get('/')->assertOk()->assertDontSee('Climber of the week');
        $this->get('/?queue=flex')->assertOk()->assertSee('Climber of the week')->assertSee('+40');
    }

    public function test_the_leaderboard_still_works_without_any_ranked_player(): void
    {
        $this->makePlayer('Alpha');

        $this->get('/')->assertOk()->assertDontSee('Climber of the week');
    }

    // ---- Duo / head-to-head on the compare page ---------------------------

    public function test_the_compare_page_lists_duo_and_head_to_head_records_for_every_pair(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');
        $c = $this->makePlayer('Gamma');

        // Alpha + Beta: 2 wins together. Alpha vs Gamma: Alpha 1, Gamma 2.
        $this->addTeamGame([$a, $b], [], blueWins: true);
        $this->addTeamGame([$a, $b], [], blueWins: true);
        $this->addTeamGame([$a], [$c], blueWins: true);
        $this->addTeamGame([$a], [$c], blueWins: false);
        $this->addTeamGame([$c], [$a], blueWins: true);

        $response = $this->get("/compare?players={$a->id},{$b->id},{$c->id}")->assertOk();

        $response->assertSee('Duo dan head-to-head');

        $pairs = $response->viewData('pairs');
        $this->assertCount(3, $pairs);

        $response->assertSee('2M 0K');                  // Alpha & Beta together
        $response->assertSee('100.0%');
        $response->assertSee('(2 game)');
        $response->assertSee('Belum pernah');           // never met: Beta & Gamma, Alpha & Gamma together, ...
        $response->assertSeeInOrder(['Alpha', '1', '–', '2', 'Gamma']);   // head-to-head from Alpha's side
    }

    public function test_the_compare_pairs_follow_the_selected_queue(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');

        $this->addTeamGame([$a, $b], [], blueWins: true, queueId: 440);

        $solo = $this->get("/compare?players={$a->id},{$b->id}")->assertOk();
        $this->assertSame(0, $solo->viewData('pairs')[0]['together']['games']);

        $flex = $this->get("/compare?players={$a->id},{$b->id}&queue=flex")->assertOk();
        $this->assertSame(1, $flex->viewData('pairs')[0]['together']['games']);
    }

    // ---- "Main bareng teman" on the player page ---------------------------

    public function test_the_player_page_lists_records_with_other_tracked_players(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta', ['display_name' => 'Bebek']);

        $this->addTeamGame([$a, $b], [], blueWins: true);
        $this->addTeamGame([$a], [$b], blueWins: false);

        $response = $this->get("/players/{$a->id}")->assertOk();

        $response->assertSee('Main bareng teman');
        $response->assertSee('Bebek');
        $response->assertSee("/players/{$b->id}", false);
        $response->assertSee('1M 0K');   // together
        $response->assertSee('0M 1K');   // Alpha lost the head-to-head

        $partners = $response->viewData('partners');
        $this->assertCount(1, $partners);
        $this->assertSame($b->id, $partners[0]['player_id']);
    }

    public function test_the_section_is_hidden_without_shared_matches(): void
    {
        $a = $this->makePlayer('Alpha');
        $this->makePlayer('Beta');
        $this->addGame($a);

        $this->get("/players/{$a->id}")->assertOk()->assertDontSee('Main bareng teman');
    }

    public function test_the_player_page_partners_follow_the_selected_queue(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');
        $this->addTeamGame([$a, $b], [], blueWins: true, queueId: 440);

        $this->get("/players/{$a->id}")->assertOk()->assertDontSee('Main bareng teman');
        $this->get("/players/{$a->id}?queue=flex")->assertOk()->assertSee('Main bareng teman');
    }

    public function test_the_new_sections_only_read_the_database(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');
        $this->addTeamGame([$a, $b], [], blueWins: true);

        $this->get('/')->assertOk();
        $this->get("/players/{$a->id}")->assertOk();
        $this->get("/compare?players={$a->id},{$b->id}")->assertOk();

        Http::assertNothingSent();
    }
}
