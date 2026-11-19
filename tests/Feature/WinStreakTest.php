<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Player;
use App\Services\Stats\WinStreak;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class WinStreakTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
    }

    private function streak(): WinStreak
    {
        return app(WinStreak::class);
    }

    /**
     * Add games oldest first; $results lists win (true) or loss (false). Returns the matches in the same order.
     *
     * @param  list<bool>  $results
     * @return list<GameMatch>
     */
    private function play(Player $player, array $results, int $duration = 1800): array
    {
        $matches = [];

        foreach ($results as $index => $win) {
            $matches[] = $this->addGame($player, ['win' => $win], duration: $duration, startedAt: now()->subHours(count($results) - $index));
        }

        return $matches;
    }

    public function test_it_counts_consecutive_wins_ending_at_the_given_match(): void
    {
        $player = $this->makePlayer('Alpha');
        $matches = $this->play($player, [false, true, true, true]);

        $this->assertSame(3, $this->streak()->endingAt($player->id, $matches[3]));
    }

    public function test_a_loss_breaks_the_streak(): void
    {
        $player = $this->makePlayer('Alpha');
        $matches = $this->play($player, [true, true, false, true]);

        $this->assertSame(1, $this->streak()->endingAt($player->id, $matches[3]));
        $this->assertSame(0, $this->streak()->endingAt($player->id, $matches[2]));
    }

    public function test_it_looks_backwards_from_the_given_match_not_from_the_latest_one(): void
    {
        $player = $this->makePlayer('Alpha');
        $matches = $this->play($player, [true, true, true, false, true]);

        // The streak at the 3rd match was 3, even though later games exist.
        $this->assertSame(3, $this->streak()->endingAt($player->id, $matches[2]));
        $this->assertSame(2, $this->streak()->endingAt($player->id, $matches[1]));
    }

    public function test_remakes_neither_count_nor_break_a_streak(): void
    {
        $player = $this->makePlayer('Alpha');

        $this->addGame($player, ['win' => true], startedAt: now()->subHours(4));
        $this->addGame($player, ['win' => false], duration: 200, startedAt: now()->subHours(3));   // remake loss
        $this->addGame($player, ['win' => true], startedAt: now()->subHours(2));
        $last = $this->addGame($player, ['win' => true], startedAt: now()->subHour());

        $this->assertSame(3, $this->streak()->endingAt($player->id, $last));
    }

    public function test_it_only_looks_at_the_given_player(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');

        $this->addTeamGame([$a, $b], [], blueWins: true, startedAt: now()->subHours(3));
        $this->addTeamGame([$a], [$b], blueWins: true, startedAt: now()->subHours(2));          // Beta loses
        $last = $this->addTeamGame([$a, $b], [], blueWins: true, startedAt: now()->subHour());

        $this->assertSame(3, $this->streak()->endingAt($a->id, $last));
        $this->assertSame(1, $this->streak()->endingAt($b->id, $last));
    }

    public function test_matches_started_at_the_same_moment_are_ordered_by_id(): void
    {
        $player = $this->makePlayer('Alpha');
        $moment = now()->subHour();

        $first = $this->addGame($player, ['win' => true], startedAt: $moment);
        $second = $this->addGame($player, ['win' => true], startedAt: $moment);

        $this->assertSame(1, $this->streak()->endingAt($player->id, $first));
        $this->assertSame(2, $this->streak()->endingAt($player->id, $second));
    }

    public function test_a_player_without_other_games_has_a_streak_of_one_after_a_win(): void
    {
        $player = $this->makePlayer('Alpha');
        $match = $this->addGame($player, ['win' => true]);

        $this->assertSame(1, $this->streak()->endingAt($player->id, $match));
    }

    public function test_milestones_come_from_the_config(): void
    {
        $this->assertTrue($this->streak()->isMilestone(3));
        $this->assertTrue($this->streak()->isMilestone(10));
        $this->assertFalse($this->streak()->isMilestone(2));
        $this->assertFalse($this->streak()->isMilestone(4));
        $this->assertFalse($this->streak()->isMilestone(6));

        config(['services.discord.win_streak_milestones' => [4]]);

        $this->assertTrue($this->streak()->isMilestone(4));
        $this->assertFalse($this->streak()->isMilestone(3));
    }
}
