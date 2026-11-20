<?php

namespace Tests\Unit;

use App\Services\Riot\TimelineParser;
use PHPUnit\Framework\TestCase;
use Tests\Support\RiotFixtures;

class TimelineParserTest extends TestCase
{
    private function parser(): TimelineParser
    {
        return new TimelineParser;
    }

    public function test_players_are_matched_by_participant_id_not_by_the_metadata_order(): void
    {
        $timeline = RiotFixtures::timeline('SG2_1', ['alpha', 'beta'], [
            RiotFixtures::purchase(1, 1055, 30),
            RiotFixtures::purchase(2, 1056, 31),
        ]);

        $builds = $this->parser()->parse($timeline);

        // metadata.participants is reversed in the fixture: alpha would be last there.
        $this->assertSame([[30, 1055]], $builds['alpha']['item_purchases']);
        $this->assertSame([[31, 1056]], $builds['beta']['item_purchases']);
        $this->assertCount(10, $builds);
    }

    public function test_item_purchases_keep_their_order_and_round_the_time_to_seconds(): void
    {
        $timeline = RiotFixtures::timeline('SG2_1', ['alpha'], [
            RiotFixtures::purchase(1, 1055, 12),
            RiotFixtures::purchase(1, 2003, 13),
            ['type' => 'ITEM_PURCHASED', 'participantId' => 1, 'itemId' => 3006, 'timestamp' => 301499],
            RiotFixtures::purchase(1, 3153, 1500),
        ]);

        $this->assertSame(
            [[12, 1055], [13, 2003], [301, 3006], [1500, 3153]],
            $this->parser()->parse($timeline)['alpha']['item_purchases'],
        );
    }

    public function test_an_undone_purchase_disappears_but_only_the_last_one_of_that_item(): void
    {
        $timeline = RiotFixtures::timeline('SG2_1', ['alpha'], [
            RiotFixtures::purchase(1, 1001, 100),     // Boots
            RiotFixtures::purchase(1, 1055, 110),
            RiotFixtures::purchase(1, 1001, 200),     // bought again
            RiotFixtures::undo(1, 1001, 205),         // undo the second one
            RiotFixtures::undo(1, 9999, 206),         // undo of something never bought: ignored
        ]);

        $this->assertSame([[100, 1001], [110, 1055]], $this->parser()->parse($timeline)['alpha']['item_purchases']);
    }

    public function test_shop_events_without_a_real_participant_are_ignored(): void
    {
        $timeline = RiotFixtures::timeline('SG2_1', ['alpha'], [
            RiotFixtures::purchase(0, 3865, 0),      // participantId 0
            RiotFixtures::purchase(11, 1055, 10),    // does not exist
            RiotFixtures::purchase(1, 0, 20),        // item id 0
            RiotFixtures::purchase(1, 1055, 30),
        ]);

        $this->assertSame([[30, 1055]], $this->parser()->parse($timeline)['alpha']['item_purchases']);
    }

    public function test_other_event_types_are_ignored(): void
    {
        $timeline = RiotFixtures::timeline('SG2_1', ['alpha'], [
            ['type' => 'ITEM_SOLD', 'participantId' => 1, 'itemId' => 1055, 'timestamp' => 5000],
            ['type' => 'ITEM_DESTROYED', 'participantId' => 1, 'itemId' => 2003, 'timestamp' => 6000],
            ['type' => 'CHAMPION_KILL', 'killerId' => 1, 'timestamp' => 7000],
            RiotFixtures::purchase(1, 1055, 10),
        ]);

        $build = $this->parser()->parse($timeline)['alpha'];

        $this->assertSame([[10, 1055]], $build['item_purchases']);
        $this->assertSame([], $build['skill_order']);
    }

    public function test_skill_order_lists_the_slots_in_the_order_they_were_levelled(): void
    {
        $timeline = RiotFixtures::timeline('SG2_1', ['alpha', 'beta'], [
            RiotFixtures::skill(1, 1, 5),
            RiotFixtures::skill(1, 3, 90),
            RiotFixtures::skill(1, 2, 160),
            RiotFixtures::skill(1, 1, 220),
            RiotFixtures::skill(1, 4, 400),
            RiotFixtures::skill(2, 2, 6),
        ]);

        $builds = $this->parser()->parse($timeline);

        $this->assertSame([1, 3, 2, 1, 4], $builds['alpha']['skill_order']);
        $this->assertSame([2], $builds['beta']['skill_order']);
    }

    public function test_evolutions_and_unknown_slots_are_not_skill_points(): void
    {
        $timeline = RiotFixtures::timeline('SG2_1', ['alpha'], [
            RiotFixtures::skill(1, 1, 5),
            RiotFixtures::skill(1, 1, 600, 'EVOLVE'),
            RiotFixtures::skill(1, 5, 700),
            RiotFixtures::skill(1, 0, 800),
            RiotFixtures::skill(1, 2, 900),
        ]);

        $this->assertSame([1, 2], $this->parser()->parse($timeline)['alpha']['skill_order']);
    }

    public function test_players_without_events_get_empty_lists(): void
    {
        $builds = $this->parser()->parse(RiotFixtures::timeline('SG2_1', ['alpha']));

        $this->assertSame(['item_purchases' => [], 'skill_order' => [], 'metrics' => null], $builds['alpha']);
    }

    public function test_malformed_timelines_give_nothing(): void
    {
        $this->assertSame([], $this->parser()->parse([]));
        $this->assertSame([], $this->parser()->parse(['info' => ['frames' => [['events' => [RiotFixtures::purchase(1, 1055, 10)]]]]]));
        // A match payload where a timeline was expected must not crash.
        $this->assertSame([], $this->parser()->parse(RiotFixtures::match('SG2_1')));
    }
}
