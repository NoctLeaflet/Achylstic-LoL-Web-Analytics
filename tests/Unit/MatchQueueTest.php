<?php

namespace Tests\Unit;

use App\Enums\MatchQueue;
use App\Enums\RankedQueue;
use Tests\TestCase;

class MatchQueueTest extends TestCase
{
    public function test_queue_ids_labels_and_slugs(): void
    {
        $expected = [
            [MatchQueue::Solo, 420, 'solo', 'Solo/Duo'],
            [MatchQueue::Flex, 440, 'flex', 'Flex'],
            [MatchQueue::Normal, 400, 'normal', 'Normal Draft'],
            [MatchQueue::Aram, 450, 'aram', 'ARAM'],
            [MatchQueue::AramMayhem, 2400, 'mayhem', 'ARAM Mayhem'],
            [MatchQueue::Swiftplay, 480, 'swiftplay', 'Swiftplay'],
        ];

        foreach ($expected as [$queue, $id, $slug, $label]) {
            $this->assertSame($id, $queue->queueId());
            $this->assertSame($slug, $queue->slug());
            $this->assertSame($label, $queue->label());
        }
    }

    public function test_only_solo_and_flex_have_a_rank(): void
    {
        $this->assertSame(RankedQueue::Solo, MatchQueue::Solo->ranked());
        $this->assertSame(RankedQueue::Flex, MatchQueue::Flex->ranked());
        $this->assertTrue(MatchQueue::Solo->isRanked());

        foreach ([MatchQueue::Normal, MatchQueue::Aram, MatchQueue::AramMayhem, MatchQueue::Swiftplay] as $queue) {
            $this->assertNull($queue->ranked());
            $this->assertFalse($queue->isRanked());
        }
    }

    public function test_the_ranked_slugs_match_the_ranked_queue_enum(): void
    {
        // Links between the leaderboard (RankedQueue) and the other pages (MatchQueue) rely on this.
        $this->assertSame(RankedQueue::Solo->slug(), MatchQueue::Solo->slug());
        $this->assertSame(RankedQueue::Flex->slug(), MatchQueue::Flex->slug());
        $this->assertSame(RankedQueue::Solo->queueId(), MatchQueue::Solo->queueId());
        $this->assertSame(RankedQueue::Flex->queueId(), MatchQueue::Flex->queueId());
    }

    public function test_from_slug_returns_null_for_all_modes_missing_and_unknown_values(): void
    {
        $this->assertSame(MatchQueue::AramMayhem, MatchQueue::fromSlug('mayhem'));
        $this->assertSame(MatchQueue::Normal, MatchQueue::fromSlug('normal'));
        $this->assertNull(MatchQueue::fromSlug('all'));
        $this->assertNull(MatchQueue::fromSlug(null));
        $this->assertNull(MatchQueue::fromSlug('nonsense'));
        $this->assertNull(MatchQueue::fromSlug(''));
    }

    public function test_from_queue_id(): void
    {
        $this->assertSame(MatchQueue::Aram, MatchQueue::fromQueueId(450));
        $this->assertSame(MatchQueue::AramMayhem, MatchQueue::fromQueueId(2400));
        $this->assertNull(MatchQueue::fromQueueId(1700));   // Arena is not tracked
        $this->assertNull(MatchQueue::fromQueueId(null));
    }

    public function test_the_tracked_modes_follow_the_config_in_display_order(): void
    {
        $this->assertSame(
            [MatchQueue::Solo, MatchQueue::Flex, MatchQueue::Normal, MatchQueue::Aram, MatchQueue::AramMayhem],
            MatchQueue::tracked(),
        );

        config(['services.riot.tracked_queues' => [2400, 420, 480]]);

        // Always in the enum's order, whatever the order in the config.
        $this->assertSame([MatchQueue::Solo, MatchQueue::AramMayhem, MatchQueue::Swiftplay], MatchQueue::tracked());

        config(['services.riot.tracked_queues' => []]);
        $this->assertSame([], MatchQueue::tracked());
    }

    public function test_unknown_ids_in_the_config_are_ignored(): void
    {
        config(['services.riot.tracked_queues' => ['420', 9999, 450]]);

        $this->assertSame([MatchQueue::Solo, MatchQueue::Aram], MatchQueue::tracked());
    }
}
