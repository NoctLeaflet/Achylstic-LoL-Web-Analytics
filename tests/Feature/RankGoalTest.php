<?php

namespace Tests\Feature;

use App\Enums\RankedQueue;
use App\Models\Admin;
use App\Services\Stats\RankGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class RankGoalTest extends TestCase
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

    private function goal(string $tier, string $division = 'I'): array
    {
        return ['goal_tier' => $tier, 'goal_division' => $division];
    }

    public function test_there_is_no_progress_without_a_goal_or_a_rank(): void
    {
        $noGoal = $this->makePlayer('Alpha');
        $this->addSnapshot($noGoal, RankedQueue::Solo, 'GOLD', 'IV', 0);
        $noRank = $this->makePlayer('Beta', $this->goal('GOLD'));

        $this->assertNull(app(RankGoal::class)->progress($noGoal));
        $this->assertNull(app(RankGoal::class)->progress($noRank));
    }

    public function test_it_measures_the_distance_progress_and_pace(): void
    {
        $this->freezeTime();
        $player = $this->makePlayer('Alpha', $this->goal('GOLD', 'I'));      // Gold I 0 LP = 1500
        $this->addSnapshot($player, RankedQueue::Solo, 'SILVER', 'IV', 0, capturedAt: now()->subDays(30));   // 800
        $this->addSnapshot($player, RankedQueue::Solo, 'SILVER', 'II', 0, capturedAt: now()->subDays(14));   // 1000
        $this->addSnapshot($player, RankedQueue::Solo, 'SILVER', 'I', 40, capturedAt: now());                 // 1140

        $progress = app(RankGoal::class)->progress($player);

        $this->assertSame('Gold I', $progress['label']);
        $this->assertSame('Silver I · 40 LP', $progress['current']);
        $this->assertFalse($progress['reached']);
        $this->assertSame(360, $progress['remaining']);
        $this->assertSame(49, $progress['percent']);                 // (1140 - 800) / (1500 - 800)
        $this->assertSame(10.0, $progress['per_day']);               // 140 LP in 14 days
        $this->assertSame(36, $progress['eta_days']);                // 360 / 10
    }

    public function test_a_reached_goal_is_complete_and_has_no_estimate(): void
    {
        $player = $this->makePlayer('Alpha', $this->goal('GOLD', 'II'));
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'II', 30);

        $progress = app(RankGoal::class)->progress($player);

        $this->assertTrue($progress['reached']);
        $this->assertSame(100, $progress['percent']);
        $this->assertSame(0, $progress['remaining']);
        $this->assertNull($progress['eta_days']);
    }

    public function test_without_upward_pace_there_is_no_estimate(): void
    {
        $this->freezeTime();
        $player = $this->makePlayer('Alpha', $this->goal('PLATINUM', 'IV'));
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'II', 50, capturedAt: now()->subDays(20));
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'III', 50, capturedAt: now());

        $progress = app(RankGoal::class)->progress($player);

        $this->assertNull($progress['per_day']);
        $this->assertNull($progress['eta_days']);
        $this->assertSame(0, $progress['percent']);   // below where they were 30 days ago
    }

    public function test_only_solo_duo_counts(): void
    {
        $player = $this->makePlayer('Alpha', $this->goal('GOLD'));
        $this->addSnapshot($player, RankedQueue::Flex, 'GOLD', 'I', 90);

        $this->assertNull(app(RankGoal::class)->progress($player));
    }

    public function test_the_player_page_shows_the_goal(): void
    {
        $this->freezeTime();
        $player = $this->makePlayer('Alpha', $this->goal('GOLD', 'I'));
        $this->addSnapshot($player, RankedQueue::Solo, 'SILVER', 'II', 0, capturedAt: now()->subDays(14));
        $this->addSnapshot($player, RankedQueue::Solo, 'SILVER', 'I', 0, capturedAt: now());

        $this->get(route('players.show', $player))
            ->assertOk()
            ->assertSee('Target: Gold I')
            ->assertSee('kurang')
            ->assertSee('perkiraan tercapai dalam');
    }

    public function test_the_player_page_has_no_goal_card_without_a_goal(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addSnapshot($player, RankedQueue::Solo, 'SILVER', 'I', 0);

        $this->get(route('players.show', $player))->assertOk()->assertDontSee('Target:');
    }

    public function test_the_admin_can_set_and_clear_a_goal(): void
    {
        $this->actingAs(Admin::create(['username' => 'boss', 'password' => 'throwaway-pass-123']), 'admin');
        $player = $this->makePlayer('Alpha');

        $this->patch("/admin/players/{$player->id}/goal", ['goal' => 'EMERALD:IV'])->assertSessionHas('status');
        $this->assertSame(['EMERALD', 'IV'], [$player->fresh()->goal_tier, $player->fresh()->goal_division]);

        $this->get('/admin')->assertOk()->assertSee('<option value="EMERALD:IV" selected>', false);

        $this->patch("/admin/players/{$player->id}/goal", ['goal' => ''])->assertSessionHasNoErrors();
        $this->assertNull($player->fresh()->goal_tier);
    }

    public function test_an_unknown_goal_is_rejected_and_guests_cannot_set_one(): void
    {
        $player = $this->makePlayer('Alpha');

        $this->patch("/admin/players/{$player->id}/goal", ['goal' => 'GOLD:I'])->assertRedirect(route('admin.login'));

        $this->actingAs(Admin::create(['username' => 'boss', 'password' => 'throwaway-pass-123']), 'admin');
        $this->patch("/admin/players/{$player->id}/goal", ['goal' => 'BANANA:I'])->assertSessionHasErrors('goal');

        $this->assertNull($player->fresh()->goal_tier);
    }

    public function test_master_and_above_have_no_divisions(): void
    {
        $options = RankGoal::options();

        $this->assertSame('Master', $options['MASTER:I']);
        $this->assertSame('Gold IV', $options['GOLD:IV']);
        $this->assertArrayNotHasKey('MASTER:IV', $options);
    }
}
