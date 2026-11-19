<?php

namespace Tests\Feature;

use App\Enums\RankedQueue;
use App\Services\Stats\ClimberOfTheWeek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class ClimberOfTheWeekTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
    }

    private function climbers(RankedQueue $queue = RankedQueue::Solo): ClimberOfTheWeek
    {
        return app(ClimberOfTheWeek::class);
    }

    public function test_the_gain_is_measured_from_the_last_snapshot_before_the_week(): void
    {
        $player = $this->makePlayer('Alpha');

        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', 10, capturedAt: now()->subDays(20));   // older, ignored
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', 50, capturedAt: now()->subDays(8));    // start of the window
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'III', 20, capturedAt: now()->subDays(3));
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'III', 70, capturedAt: now()->subHour());    // current

        $row = $this->climbers()->ranking(RankedQueue::Solo)[0];

        // Gold IV 50 (1250) -> Gold III 70 (1370)
        $this->assertSame(120, $row['gain']);
        $this->assertSame('Gold IV · 50 LP', $row['from']);
        $this->assertSame('Gold III · 70 LP', $row['to']);
        $this->assertSame(now()->subDays(8)->getTimestamp(), $row['since']->getTimestamp());
    }

    public function test_a_player_tracked_for_less_than_a_week_is_measured_from_the_first_snapshot(): void
    {
        $player = $this->makePlayer('Alpha');

        $this->addSnapshot($player, RankedQueue::Solo, 'SILVER', 'II', 40, capturedAt: now()->subDays(2));
        $this->addSnapshot($player, RankedQueue::Solo, 'SILVER', 'II', 15, capturedAt: now()->subHour());

        $this->assertSame(-25, $this->climbers()->ranking(RankedQueue::Solo)[0]['gain']);
    }

    public function test_a_promotion_counts_for_what_it_is_worth(): void
    {
        $player = $this->makePlayer('Alpha');

        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'I', 90, capturedAt: now()->subDays(8));
        $this->addSnapshot($player, RankedQueue::Solo, 'PLATINUM', 'IV', 10, capturedAt: now()->subDay());

        $this->assertSame(20, $this->climbers()->ranking(RankedQueue::Solo)[0]['gain']);
    }

    public function test_a_player_without_new_snapshots_this_week_gained_nothing(): void
    {
        $player = $this->makePlayer('Alpha');

        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'II', 54, capturedAt: now()->subDays(12));
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'II', 80, capturedAt: now()->subDays(9));

        $row = $this->climbers()->ranking(RankedQueue::Solo)[0];

        $this->assertSame(0, $row['gain']);
        $this->assertSame($row['from'], $row['to']);
    }

    public function test_a_single_snapshot_is_a_zero_gain(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'II', 54);

        $this->assertSame(0, $this->climbers()->ranking(RankedQueue::Solo)[0]['gain']);
    }

    public function test_the_ranking_orders_by_gain_and_ignores_other_queues_and_inactive_or_unranked_players(): void
    {
        $up = $this->makePlayer('Up');
        $flat = $this->makePlayer('Flat');
        $down = $this->makePlayer('Down');
        $inactive = $this->makePlayer('Inactive', ['is_active' => false]);
        $this->makePlayer('Unranked');

        foreach ([[$up, 40, 100], [$flat, 50, 50], [$down, 60, 10], [$inactive, 0, 90]] as [$player, $from, $to]) {
            $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', $from, capturedAt: now()->subDays(6));
            $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', $to, capturedAt: now()->subHour());
        }

        // A huge flex climb must not count for solo.
        $this->addSnapshot($down, RankedQueue::Flex, 'IRON', 'IV', 0, capturedAt: now()->subDays(6));
        $this->addSnapshot($down, RankedQueue::Flex, 'DIAMOND', 'I', 0, capturedAt: now()->subHour());

        $solo = $this->climbers()->ranking(RankedQueue::Solo);

        $this->assertSame(['Up', 'Flat', 'Down'], array_map(fn ($row) => $row['player']->name, $solo));
        $this->assertSame([60, 0, -50], array_column($solo, 'gain'));

        $flex = $this->climbers()->ranking(RankedQueue::Flex);
        $this->assertSame(['Down'], array_map(fn ($row) => $row['player']->name, $flex));
        // Iron IV 0 LP (0) -> Diamond I 0 LP (2400 + 300)
        $this->assertSame(2700, $flex[0]['gain']);
    }

    public function test_the_climber_is_the_biggest_gainer_and_only_when_someone_gained(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');

        $this->addSnapshot($a, RankedQueue::Solo, 'GOLD', 'IV', 10, capturedAt: now()->subDays(6));
        $this->addSnapshot($a, RankedQueue::Solo, 'GOLD', 'IV', 20, capturedAt: now()->subHour());
        $this->addSnapshot($b, RankedQueue::Solo, 'GOLD', 'IV', 10, capturedAt: now()->subDays(6));
        $this->addSnapshot($b, RankedQueue::Solo, 'GOLD', 'IV', 80, capturedAt: now()->subHour());

        $climber = $this->climbers()->climber(RankedQueue::Solo);

        $this->assertSame('Beta', $climber['player']->name);
        $this->assertSame(70, $climber['gain']);

        $this->assertNull($this->climbers()->climber(RankedQueue::Flex));
    }

    public function test_nobody_climbs_when_everyone_lost_or_stayed(): void
    {
        $a = $this->makePlayer('Alpha');
        $this->addSnapshot($a, RankedQueue::Solo, 'GOLD', 'IV', 50, capturedAt: now()->subDays(6));
        $this->addSnapshot($a, RankedQueue::Solo, 'GOLD', 'IV', 30, capturedAt: now()->subHour());

        $this->assertNull($this->climbers()->climber(RankedQueue::Solo));
    }

    public function test_an_empty_database_has_no_ranking(): void
    {
        $this->assertSame([], $this->climbers()->ranking(RankedQueue::Solo));
        $this->assertNull($this->climbers()->climber(RankedQueue::Solo));
    }
}
