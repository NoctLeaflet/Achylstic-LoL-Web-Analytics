<?php

namespace Tests\Feature;

use App\Enums\MatchQueue;
use App\Services\Stats\PerformanceStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class PerformanceStatsTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    private function stats(): PerformanceStats
    {
        return app(PerformanceStats::class);
    }

    public function test_it_computes_averages_ratios_and_per_minute_rates(): void
    {
        $player = $this->makePlayer();

        // 30 minute win.
        $this->addGame($player, [
            'win' => true, 'kills' => 10, 'deaths' => 2, 'assists' => 8,
            'total_minions_killed' => 200, 'neutral_minions_killed' => 20,
            'gold_earned' => 12000, 'total_damage_dealt_to_champions' => 30000, 'vision_score' => 40,
        ], duration: 1800);

        // 20 minute loss.
        $this->addGame($player, [
            'win' => false, 'kills' => 2, 'deaths' => 8, 'assists' => 4,
            'total_minions_killed' => 100, 'neutral_minions_killed' => 0,
            'gold_earned' => 8000, 'total_damage_dealt_to_champions' => 15000, 'vision_score' => 20,
        ], duration: 1200);

        $stats = $this->stats()->forPlayer($player->id);

        $this->assertSame(2, $stats['games']);
        $this->assertSame(1, $stats['wins']);
        $this->assertSame(1, $stats['losses']);
        $this->assertSame(50.0, $stats['winrate']);
        $this->assertSame(6.0, $stats['avg_kills']);
        $this->assertSame(5.0, $stats['avg_deaths']);
        $this->assertSame(6.0, $stats['avg_assists']);
        // (12 kills + 12 assists) / 10 deaths
        $this->assertSame(2.4, $stats['kda']);
        // 50 minutes played in total
        $this->assertSame(6.4, $stats['cs_per_min']);        // 320 CS
        $this->assertSame(900.0, $stats['damage_per_min']);  // 45000 damage
        $this->assertSame(400.0, $stats['gold_per_min']);    // 20000 gold
        $this->assertSame(1.2, $stats['vision_per_min']);    // 60 vision
    }

    public function test_kda_never_divides_by_zero(): void
    {
        $player = $this->makePlayer();
        $this->addGame($player, ['kills' => 7, 'deaths' => 0, 'assists' => 5]);

        $this->assertSame(12.0, $this->stats()->forPlayer($player->id)['kda']);
    }

    public function test_remakes_are_not_counted(): void
    {
        $player = $this->makePlayer();
        $this->addGame($player, ['win' => true, 'kills' => 1], duration: 1800);
        $this->addGame($player, ['win' => true, 'kills' => 50], duration: 299);   // remake
        $this->addGame($player, ['win' => false, 'kills' => 3], duration: 300);   // shortest counted game

        $stats = $this->stats()->forPlayer($player->id);

        $this->assertSame(2, $stats['games']);
        $this->assertSame(2.0, $stats['avg_kills']);
    }

    public function test_it_can_be_limited_to_one_queue(): void
    {
        $player = $this->makePlayer();
        $this->addGame($player, ['win' => true], queueId: 420);
        $this->addGame($player, ['win' => true], queueId: 420);
        $this->addGame($player, ['win' => false], queueId: 440);

        $this->assertSame(3, $this->stats()->forPlayer($player->id)['games']);
        $this->assertSame(2, $this->stats()->forPlayer($player->id, MatchQueue::Solo)['games']);
        $this->assertSame(1, $this->stats()->forPlayer($player->id, MatchQueue::Flex)['games']);
        $this->assertSame(0.0, $this->stats()->forPlayer($player->id, MatchQueue::Flex)['winrate']);
    }

    public function test_players_without_games_get_zeroed_stats(): void
    {
        $withGames = $this->makePlayer('Alpha');
        $without = $this->makePlayer('Beta');
        $this->addGame($withGames);

        $stats = $this->stats()->forPlayers([$withGames->id, $without->id]);

        $this->assertSame(1, $stats[$withGames->id]['games']);
        $this->assertSame(0, $stats[$without->id]['games']);
        $this->assertSame(0.0, $stats[$without->id]['kda']);
        $this->assertSame([], $this->stats()->forPlayers([]));
    }

    public function test_stats_are_kept_per_player_and_ignore_untracked_participants(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');
        $this->addGame($a, ['kills' => 10, 'deaths' => 1, 'assists' => 0]);
        $match = $this->addGame($b, ['kills' => 2, 'deaths' => 1, 'assists' => 0]);
        // An enemy that is not one of our players must not be counted.
        $match->participants()->create([
            'player_id' => null, 'puuid' => 'stranger', 'champion_id' => 1, 'champion_name' => 'Annie',
            'team_id' => 200, 'win' => false, 'kills' => 99, 'deaths' => 0, 'assists' => 0,
            'total_minions_killed' => 0, 'neutral_minions_killed' => 0, 'gold_earned' => 0,
            'total_damage_dealt_to_champions' => 0, 'damage_taken' => 0, 'vision_score' => 0, 'wards_placed' => 0,
        ]);

        $stats = $this->stats()->forPlayers([$a->id, $b->id]);

        $this->assertSame(10.0, $stats[$a->id]['kda']);
        $this->assertSame(2.0, $stats[$b->id]['kda']);
    }

    public function test_top_champions_are_ordered_by_games_then_wins(): void
    {
        $player = $this->makePlayer();

        foreach ([true, true, false] as $win) {
            $this->addGame($player, ['champion_id' => 103, 'champion_name' => 'Ahri', 'win' => $win, 'kills' => 6, 'deaths' => 3, 'assists' => 6]);
        }
        foreach ([true, false] as $win) {
            $this->addGame($player, ['champion_id' => 238, 'champion_name' => 'Zed', 'win' => $win, 'kills' => 4, 'deaths' => 0, 'assists' => 0]);
        }
        $this->addGame($player, ['champion_id' => 1, 'champion_name' => 'Annie', 'win' => true]);
        $this->addGame($player, ['champion_id' => 2, 'champion_name' => 'Olaf', 'win' => false]);
        // A remake does not count towards the champion either.
        $this->addGame($player, ['champion_id' => 238, 'champion_name' => 'Zed', 'win' => true], duration: 200);

        $top = $this->stats()->topChampions($player->id, limit: 3);

        $this->assertSame(['Ahri', 'Zed', 'Annie'], array_column($top, 'champion_name'));
        $this->assertSame(
            ['champion_id' => 103, 'champion_name' => 'Ahri', 'games' => 3, 'wins' => 2, 'winrate' => 66.7, 'kda' => 4.0],
            $top[0],
        );
        // Zed: 8 kills and no deaths -> KDA divides by max(deaths, 1).
        $this->assertSame(['games' => 2, 'wins' => 1, 'winrate' => 50.0, 'kda' => 8.0], array_intersect_key($top[1], array_flip(['games', 'wins', 'winrate', 'kda'])));
    }

    public function test_recent_matches_are_newest_first_limited_and_flag_remakes(): void
    {
        $player = $this->makePlayer();

        for ($i = 1; $i <= 25; $i++) {
            $this->addGame($player, ['kills' => $i], startedAt: now()->subHours(30 - $i));
        }
        $this->addGame($player, ['kills' => 99], duration: 200, startedAt: now()->addHour());   // newest, a remake

        $recent = $this->stats()->recentMatches($player->id);

        $this->assertCount(20, $recent);
        $this->assertSame(99, $recent[0]['kills']);
        $this->assertTrue($recent[0]['is_remake']);
        $this->assertSame(25, $recent[1]['kills']);
        $this->assertFalse($recent[1]['is_remake']);
        $this->assertSame(7, $recent[19]['kills']);
    }

    public function test_recent_match_rows_carry_everything_the_page_shows(): void
    {
        $player = $this->makePlayer();
        $this->addGame($player, ['kills' => 8, 'deaths' => 2, 'assists' => 10, 'total_minions_killed' => 270, 'neutral_minions_killed' => 30], duration: 1800, queueId: 440);

        $row = $this->stats()->recentMatches($player->id)[0];

        $this->assertSame('Flex', $row['queue']);
        $this->assertSame('Ahri', $row['champion_name']);
        $this->assertSame('MIDDLE', $row['position']);
        $this->assertSame(9.0, $row['kda']);
        $this->assertSame(300, $row['cs']);
        $this->assertSame(10.0, $row['cs_per_min']);
        $this->assertSame(1800, $row['duration']);
        $this->assertTrue($row['win']);
        $this->assertSame([3020, 6655, 0, 0, 0, 0, 3340], $row['items']);
        $this->assertSame('SG2_100001', $row['match_id']);

        $this->assertCount(0, $this->stats()->recentMatches($player->id, MatchQueue::Solo));
        $this->assertCount(1, $this->stats()->recentMatches($player->id, MatchQueue::Flex));
    }
}
