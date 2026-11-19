<?php

namespace Tests\Unit;

use App\Services\Stats\RankScore;
use PHPUnit\Framework\TestCase;

class RankScoreTest extends TestCase
{
    public function test_score_is_continuous_across_divisions_and_tiers(): void
    {
        $this->assertSame(0, RankScore::score('IRON', 'IV', 0));
        $this->assertSame(100, RankScore::score('IRON', 'III', 0));
        $this->assertSame(399, RankScore::score('IRON', 'I', 99));
        $this->assertSame(400, RankScore::score('BRONZE', 'IV', 0));
        $this->assertSame(1454, RankScore::score('GOLD', 'II', 54));
        $this->assertSame(2799, RankScore::score('DIAMOND', 'I', 99));
    }

    public function test_master_and_above_share_the_ladder_above_diamond(): void
    {
        $this->assertSame(2800, RankScore::score('MASTER', 'I', 0));
        $this->assertSame(3000, RankScore::score('MASTER', 'I', 200));
        $this->assertSame(3300, RankScore::score('CHALLENGER', 'I', 500));
        $this->assertTrue(RankScore::isApex('GRANDMASTER'));
        $this->assertFalse(RankScore::isApex('DIAMOND'));
    }

    public function test_unknown_tiers_score_zero_and_are_case_insensitive(): void
    {
        $this->assertSame(0, RankScore::score('WOOD', 'I', 50));
        $this->assertSame(1454, RankScore::score('gold', 'ii', 54));
    }

    public function test_sort_key_orders_by_tier_then_division_then_lp(): void
    {
        $ordered = [
            RankScore::sortKey('GOLD', 'IV', 99),
            RankScore::sortKey('GOLD', 'III', 0),
            RankScore::sortKey('GOLD', 'I', 0),
            RankScore::sortKey('GOLD', 'I', 75),
            RankScore::sortKey('PLATINUM', 'IV', 0),
            RankScore::sortKey('DIAMOND', 'I', 99),
            RankScore::sortKey('MASTER', 'I', 0),
            RankScore::sortKey('MASTER', 'I', 400),
            RankScore::sortKey('GRANDMASTER', 'I', 100),
            RankScore::sortKey('CHALLENGER', 'I', 0),
        ];

        $sorted = $ordered;
        sort($sorted);

        $this->assertSame($ordered, $sorted);
    }

    public function test_labels(): void
    {
        $this->assertSame('Gold II', RankScore::tierLabel('GOLD', 'II'));
        $this->assertSame('Master', RankScore::tierLabel('MASTER', 'I'));
        $this->assertSame('Gold II · 54 LP', RankScore::label('GOLD', 'II', 54));
        $this->assertSame('Challenger · 612 LP', RankScore::label('CHALLENGER', 'I', 612));
    }

    public function test_ticks_have_one_label_per_division_for_a_narrow_range(): void
    {
        $ticks = RankScore::ticks(1454, 1620);

        $this->assertSame(['value' => 1300, 'label' => 'Gold III'], $ticks[0]);
        $this->assertContains(['value' => 1400, 'label' => 'Gold II'], $ticks);
        $this->assertContains(['value' => 1600, 'label' => 'Platinum IV'], $ticks);
        $this->assertSame(['value' => 1700, 'label' => 'Platinum III'], end($ticks));
    }

    public function test_ticks_switch_to_tiers_for_a_wide_range(): void
    {
        $ticks = RankScore::ticks(200, 2000);

        $this->assertContains(['value' => 400, 'label' => 'Bronze'], $ticks);
        $this->assertContains(['value' => 1200, 'label' => 'Gold'], $ticks);
        $this->assertNotContains(['value' => 500, 'label' => 'Bronze III'], $ticks);
    }

    public function test_ticks_never_start_below_zero_and_label_apex_lp(): void
    {
        $this->assertSame(0, RankScore::ticks(20, 150)[0]['value']);

        $ticks = RankScore::ticks(2750, 3000);
        $this->assertContains(['value' => 2800, 'label' => 'Master'], $ticks);
        $this->assertContains(['value' => 2900, 'label' => 'Master 100 LP'], $ticks);
    }
}
