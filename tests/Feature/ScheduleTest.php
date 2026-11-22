<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    private function scheduledEvent(string $command): ?Event
    {
        // Booting the console kernel registers the events from routes/console.php.
        $this->artisan('schedule:list')->assertSuccessful();

        return collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, $command));
    }

    public function test_ranks_are_synced_every_fifteen_minutes_without_overlapping(): void
    {
        $event = $this->scheduledEvent('riot:sync-ranks');

        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_missing_timelines_are_caught_up_twice_an_hour_without_overlapping(): void
    {
        $event = $this->scheduledEvent('riot:sync-timelines');

        $this->assertNotNull($event);
        $this->assertSame('15,45 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_matches_are_synced_every_thirty_minutes_without_overlapping(): void
    {
        $event = $this->scheduledEvent('riot:sync-matches');

        $this->assertNotNull($event);
        $this->assertSame('*/30 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_the_weekly_discord_summary_goes_out_on_monday_morning(): void
    {
        $event = $this->scheduledEvent('discord:weekly');

        $this->assertNotNull($event);
        $this->assertSame('0 9 * * 1', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_the_database_is_backed_up_every_night(): void
    {
        $event = $this->scheduledEvent('db:backup');

        $this->assertNotNull($event);
        $this->assertSame('30 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_who_is_playing_is_checked_every_two_minutes_without_overlapping(): void
    {
        $event = $this->scheduledEvent('riot:sync-live');

        $this->assertNotNull($event);
        $this->assertSame('*/2 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_champion_mastery_is_synced_once_a_day(): void
    {
        $event = $this->scheduledEvent('riot:sync-mastery');

        $this->assertNotNull($event);
        $this->assertSame('0 4 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_a_quick_match_check_runs_every_three_minutes_next_to_the_full_one(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $quick = collect(app(Schedule::class)->events())->first(fn (Event $e) => str_contains((string) $e->command, 'riot:sync-matches --quick'));

        $this->assertNotNull($quick);
        $this->assertSame('*/3 * * * *', $quick->expression);
        $this->assertTrue($quick->withoutOverlapping);
    }

    public function test_the_scheduler_stamps_a_heartbeat_every_minute(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $beat = collect(app(Schedule::class)->events())->first(fn (Event $e) => $e->description === 'scheduler-heartbeat');

        $this->assertNotNull($beat);
        $this->assertSame('* * * * *', $beat->expression);
    }
}
