<?php

namespace Tests\Feature;

use App\Enums\RankedQueue;
use App\Events\MatchRecorded;
use App\Models\Achievement;
use App\Services\Achievements\AchievementTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class AchievementsTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    private const WEBHOOK = 'https://discord.com/api/webhooks/123456789012345678/abc-DEF_ghi123';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->withoutVite();
        Cache::put('ddragon.version', '16.1.1');
        Http::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }

    private function earned($player): array
    {
        return app(AchievementTracker::class)->evaluate($player);
    }

    public function test_single_game_achievements(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, ['kills' => 20, 'deaths' => 0, 'assists' => 3, 'vision_score' => 100]);   // win, deathless, kills, vision
        $this->addGame($player, ['assists' => 25, 'win' => false]);

        $earned = $this->earned($player);

        foreach (['kills_20', 'deathless', 'vision_100', 'assists_25'] as $code) {
            $this->assertArrayHasKey($code, $earned, $code);
        }
        $this->assertArrayNotHasKey('games_100', $earned);
    }

    public function test_deathless_needs_a_win_and_enough_takedowns(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, ['kills' => 3, 'deaths' => 0, 'assists' => 3]);                   // too few takedowns
        $this->addGame($player, ['kills' => 9, 'deaths' => 0, 'assists' => 9, 'win' => false]);   // lost

        $this->assertArrayNotHasKey('deathless', $this->earned($player));
    }

    public function test_a_win_streak_needs_ten_in_a_row_and_is_broken_by_a_loss(): void
    {
        $player = $this->makePlayer('Alpha');
        foreach (range(1, 9) as $i) {
            $this->addGame($player, ['win' => true]);
        }
        $this->addGame($player, ['win' => false]);
        foreach (range(1, 9) as $i) {
            $this->addGame($player, ['win' => true]);
        }

        $this->assertArrayNotHasKey('win_streak_10', $this->earned($player));

        $this->addGame($player, ['win' => true]);

        $this->assertArrayHasKey('win_streak_10', $this->earned($player));
    }

    public function test_game_count_champion_loyalty_and_variety(): void
    {
        $player = $this->makePlayer('Alpha');
        foreach (range(1, 50) as $i) {
            $this->addGame($player, ['champion_id' => 222, 'champion_name' => 'Jinx']);
        }
        foreach (range(1, 19) as $id) {
            $this->addGame($player, ['champion_id' => 1000 + $id, 'champion_name' => "C{$id}"]);
        }

        $earned = $this->earned($player);
        $this->assertArrayHasKey('one_trick_50', $earned);
        $this->assertArrayHasKey('games_100', $this->earned($player) + ['games_100' => null]);   // 69 games: not yet
        $this->assertArrayNotHasKey('games_100', $earned);
        $this->assertArrayHasKey('champions_20', $earned);   // Jinx + 19 others
    }

    public function test_tier_achievements_come_from_the_highest_rank_reached(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addSnapshot($player, RankedQueue::Solo, 'SILVER', 'I', 50, capturedAt: now()->subDays(10));
        $this->addSnapshot($player, RankedQueue::Solo, 'PLATINUM', 'IV', 5, capturedAt: now()->subDays(5));
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'I', 5, capturedAt: now()->subDays(1));   // dropped back

        $earned = $this->earned($player);

        $this->assertSame(['tier_gold', 'tier_platinum'], array_keys(array_intersect_key($earned, array_flip(['tier_gold', 'tier_platinum', 'tier_emerald']))));
        $this->assertSame(now()->subDays(5)->toDateString(), $earned['tier_platinum']->toDateString());
    }

    public function test_history_is_stored_quietly_and_a_fresh_achievement_is_news(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, ['kills' => 25], startedAt: now()->subDays(20));     // old
        $this->addGame($player, ['assists' => 30], startedAt: now()->subMinutes(30)); // fresh

        $news = app(AchievementTracker::class)->sync($player);

        $this->assertSame(['assists_25'], array_map(fn ($a) => $a->code, $news));
        $this->assertNotNull(Achievement::where('code', 'kills_20')->first()->announced_at);   // old: marked announced
        $this->assertNull(Achievement::where('code', 'assists_25')->first()->announced_at);    // fresh: waits for Discord

        // Running again finds nothing new.
        $this->assertSame([], app(AchievementTracker::class)->sync($player));
    }

    public function test_a_recorded_match_announces_a_new_achievement_to_discord_once(): void
    {
        config(['services.discord.webhook_url' => self::WEBHOOK]);
        Http::fake([self::WEBHOOK => Http::response('', 204)]);
        $player = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);
        $match = $this->addGame($player, ['kills' => 22], startedAt: now()->subMinutes(20));

        MatchRecorded::dispatch($match);
        MatchRecorded::dispatch($match);

        $messages = Http::recorded(fn (Request $request) => str_starts_with($request->url(), self::WEBHOOK));
        $achievementMessages = $messages->filter(fn ($pair) => str_contains(json_encode($pair[0]->data()), 'Pencapaian baru'));

        $this->assertCount(1, $achievementMessages);
        $body = json_encode($achievementMessages->first()[0]->data(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Ayam', $body);
        $this->assertStringContainsString('Pembantai', $body);
        $this->assertNotNull(Achievement::where('code', 'kills_20')->first()->announced_at);
    }

    public function test_the_player_page_lists_earned_and_locked_achievements(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, ['kills' => 21]);

        $this->get(route('players.show', $player))
            ->assertOk()
            ->assertSee('Pencapaian')
            ->assertSee('Pembantai')
            ->assertSee('Diraih')
            ->assertSee('Belum diraih')
            ->assertSee('1 dari 13');
    }
}
