<?php

namespace Tests\Feature;

use App\Enums\RankedQueue;
use App\Models\GameMatch;
use App\Models\Player;
use App\Services\Riot\LeagueService;
use App\Services\Riot\MatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsGames;
use Tests\Support\RiotFixtures;
use Tests\TestCase;

/**
 * Discord notifications end to end: from a match or rank being stored to the
 * message that goes out. Nothing here talks to Discord or Riot for real.
 */
class DiscordNotificationsTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    private const URL = 'https://discord.com/api/webhooks/123456789012345678/abc-DEF_ghi123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        Sleep::fake();
        config(['services.discord.webhook_url' => self::URL]);
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }

    /**
     * @return list<array<string, mixed>> payloads posted to the webhook, in order
     */
    private function discordMessages(): array
    {
        return Http::recorded(fn (Request $request) => $request->url() === self::URL)
            ->map(fn (array $pair) => $pair[0]->data())
            // Achievement messages have their own tests (AchievementsTest); here only matches and ranks.
            ->reject(fn (array $payload) => ($payload['embeds'][0]['title'] ?? '') === '🏅 Pencapaian baru!')
            ->values()
            ->all();
    }

    /**
     * A match-v5 payload for a game that ended $minutesAgo minutes ago.
     *
     * @param  list<string>|array<int, string>  $puuids
     * @return array<string, mixed>
     */
    private function payload(string $id, array $puuids, int $minutesAgo = 10, int $duration = 1800): array
    {
        $startedAt = now()->subMinutes($minutesAgo)->subSeconds($duration);

        return RiotFixtures::match($id, $puuids, [
            'gameDuration' => $duration,
            'gameStartTimestamp' => $startedAt->getTimestamp() * 1000,
        ]);
    }

    private function record(array $payload, Player ...$players): ?GameMatch
    {
        $map = [];
        foreach ($players as $player) {
            $map[$player->puuid] = $player->id;
        }

        return app(MatchService::class)->store($payload, $map);
    }

    // ---- Matches ----------------------------------------------------------

    public function test_a_fresh_match_is_announced_once_with_the_player_and_result(): void
    {
        Http::fake([self::URL => Http::response('', 204)]);
        $player = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);

        $this->record($this->payload('SG2_9001', [$player->puuid]), $player);

        $messages = $this->discordMessages();
        $this->assertCount(1, $messages);
        $this->assertCount(1, $messages[0]['embeds']);
        $this->assertSame('✅ Menang · Solo/Duo', $messages[0]['embeds'][0]['title']);
        $this->assertStringContainsString('**Ayam** · Champ0 (Top) vs Champ5', $messages[0]['embeds'][0]['description']);
        $this->assertStringContainsString('**1/2/5** · 3.00 KDA', $messages[0]['embeds'][0]['description']);
    }

    public function test_storing_the_same_match_again_does_not_announce_it_again(): void
    {
        Http::fake([self::URL => Http::response('', 204)]);
        $player = $this->makePlayer('Alpha');
        $payload = $this->payload('SG2_9001', [$player->puuid]);

        $this->record($payload, $player);
        $this->assertNull($this->record($payload, $player));

        $this->assertCount(1, $this->discordMessages());
    }

    public function test_old_matches_are_history_and_stay_quiet(): void
    {
        Http::fake([self::URL => Http::response('', 204)]);
        $player = $this->makePlayer('Alpha');

        // Ended 4 hours ago (the cut-off is 3): think of a newly added player's backlog.
        $this->record($this->payload('SG2_9001', [$player->puuid], minutesAgo: 240), $player);
        // Ended 2 hours ago: still news.
        $this->record($this->payload('SG2_9002', [$player->puuid], minutesAgo: 120), $player);

        $this->assertCount(1, $this->discordMessages());
        $this->assertSame(2, GameMatch::count());
    }

    public function test_remakes_are_not_announced(): void
    {
        Http::fake([self::URL => Http::response('', 204)]);
        $player = $this->makePlayer('Alpha');

        $this->record($this->payload('SG2_9001', [$player->puuid], duration: 200), $player);

        $this->assertSame([], $this->discordMessages());
    }

    public function test_matches_without_any_tracked_player_are_not_announced(): void
    {
        Http::fake([self::URL => Http::response('', 204)]);

        $this->record($this->payload('SG2_9001', []));

        $this->assertSame([], $this->discordMessages());
    }

    public function test_friends_playing_together_are_in_one_message(): void
    {
        Http::fake([self::URL => Http::response('', 204)]);
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');

        $this->record($this->payload('SG2_9001', [$a->puuid, $b->puuid]), $a, $b);

        $messages = $this->discordMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('**Alpha**', $messages[0]['embeds'][0]['description']);
        $this->assertStringContainsString('**Beta**', $messages[0]['embeds'][0]['description']);
    }

    public function test_nothing_is_sent_when_notifications_are_off(): void
    {
        Http::fake();
        config(['services.discord.webhook_url' => '']);
        $player = $this->makePlayer('Alpha');

        $match = $this->record($this->payload('SG2_9001', [$player->puuid]), $player);

        $this->assertNotNull($match);
        Http::assertNothingSent();
    }

    public function test_a_discord_outage_never_breaks_recording_a_match(): void
    {
        Http::fake([self::URL => Http::response('', 500)]);
        $player = $this->makePlayer('Alpha');

        $match = $this->record($this->payload('SG2_9001', [$player->puuid]), $player);

        $this->assertNotNull($match);
        $this->assertSame(1, GameMatch::count());
        $this->assertSame(10, $match->participants()->count());
    }

    // ---- Win streaks ------------------------------------------------------

    public function test_the_third_win_in_a_row_adds_a_win_streak_message(): void
    {
        Http::fake([self::URL => Http::response('', 204)]);
        $player = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);

        $this->addGame($player, ['win' => true], startedAt: now()->subHours(6));
        $this->addGame($player, ['win' => true], startedAt: now()->subHours(5));

        $this->record($this->payload('SG2_9001', [$player->puuid]), $player);

        $embeds = $this->discordMessages()[0]['embeds'];
        $this->assertCount(2, $embeds);
        $this->assertSame('🔥 Win streak 3!', $embeds[1]['title']);
        $this->assertSame('**Ayam** menang 3 game berturut-turut.', $embeds[1]['description']);
    }

    public function test_a_win_that_is_not_a_milestone_adds_nothing(): void
    {
        Http::fake([self::URL => Http::response('', 204)]);
        $player = $this->makePlayer('Alpha');

        // Streak would be 2 after this win; 2 is not a milestone.
        $this->addGame($player, ['win' => true], startedAt: now()->subHours(6));

        $this->record($this->payload('SG2_9001', [$player->puuid]), $player);

        $this->assertCount(1, $this->discordMessages()[0]['embeds']);
    }

    public function test_a_loss_never_gets_a_win_streak_message(): void
    {
        Http::fake([self::URL => Http::response('', 204)]);
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, ['win' => true], startedAt: now()->subHours(6));
        $this->addGame($player, ['win' => true], startedAt: now()->subHours(5));

        // Index 5 is on the losing team.
        $this->record($this->payload('SG2_9001', [5 => $player->puuid]), $player);

        $embeds = $this->discordMessages()[0]['embeds'];
        $this->assertCount(1, $embeds);
        $this->assertSame('❌ Kalah · Solo/Duo', $embeds[0]['title']);
    }

    // ---- Rank changes -----------------------------------------------------

    private function fakeRank(array &$entries): void
    {
        Http::fake(function (Request $request) use (&$entries) {
            if ($request->url() === self::URL) {
                return Http::response('', 204);
            }

            return Http::response($entries);
        });
    }

    public function test_a_promotion_is_announced(): void
    {
        $player = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', 90, capturedAt: now()->subHour());

        $entries = [RiotFixtures::leagueEntry('RANKED_SOLO_5x5', ['tier' => 'GOLD', 'rank' => 'III', 'leaguePoints' => 8])];
        $this->fakeRank($entries);

        app(LeagueService::class)->syncRanks($player);

        $messages = $this->discordMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('⬆️ Naik rank!', $messages[0]['embeds'][0]['title']);
        $this->assertSame('**Ayam** naik dari **Gold IV** ke **Gold III** di Ranked Solo/Duo (8 LP).', $messages[0]['embeds'][0]['description']);
    }

    public function test_a_demotion_is_announced(): void
    {
        $player = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);
        $this->addSnapshot($player, RankedQueue::Flex, 'SILVER', 'IV', 0, capturedAt: now()->subHour());

        $entries = [RiotFixtures::leagueEntry('RANKED_FLEX_SR', ['tier' => 'BRONZE', 'rank' => 'I', 'leaguePoints' => 75])];
        $this->fakeRank($entries);

        app(LeagueService::class)->syncRanks($player);

        $embed = $this->discordMessages()[0]['embeds'][0];
        $this->assertSame('⬇️ Turun rank', $embed['title']);
        $this->assertStringContainsString('turun dari **Silver IV** ke **Bronze I** di Ranked Flex', $embed['description']);
    }

    public function test_lp_changes_and_the_first_snapshot_are_not_announced(): void
    {
        $player = $this->makePlayer('Alpha');

        $entries = [RiotFixtures::leagueEntry('RANKED_SOLO_5x5', ['tier' => 'GOLD', 'rank' => 'II', 'leaguePoints' => 50])];
        $this->fakeRank($entries);

        // First snapshot ever.
        app(LeagueService::class)->syncRanks($player);

        // Same division, more LP and a win.
        $entries = [RiotFixtures::leagueEntry('RANKED_SOLO_5x5', ['tier' => 'GOLD', 'rank' => 'II', 'leaguePoints' => 73, 'wins' => 11])];
        app(LeagueService::class)->syncRanks($player);

        $this->assertSame(2, $player->rankSnapshots()->count());
        $this->assertSame([], $this->discordMessages());
    }

    public function test_the_two_queues_are_compared_separately(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'II', 50, capturedAt: now()->subHour());
        $this->addSnapshot($player, RankedQueue::Flex, 'IRON', 'IV', 0, capturedAt: now()->subHour());

        // Solo unchanged, flex climbed a division.
        $entries = [
            RiotFixtures::leagueEntry('RANKED_SOLO_5x5', ['tier' => 'GOLD', 'rank' => 'II', 'leaguePoints' => 60]),
            RiotFixtures::leagueEntry('RANKED_FLEX_SR', ['tier' => 'IRON', 'rank' => 'III', 'leaguePoints' => 5]),
        ];
        $this->fakeRank($entries);

        app(LeagueService::class)->syncRanks($player);

        $messages = $this->discordMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Ranked Flex', $messages[0]['embeds'][0]['description']);
    }

    // ---- Through the sync commands ----------------------------------------

    public function test_sync_matches_announces_new_matches_oldest_first_and_skips_the_backlog(): void
    {
        $player = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);

        $payloads = [
            'SG2_OLD' => $this->payload('SG2_OLD', [$player->puuid], minutesAgo: 600),
            'SG2_MID' => $this->payload('SG2_MID', [$player->puuid], minutesAgo: 70),
            'SG2_NEW' => $this->payload('SG2_NEW', [$player->puuid], minutesAgo: 20),
        ];

        Http::fake(function (Request $request) use ($payloads) {
            $url = $request->url();

            if ($url === self::URL) {
                return Http::response('', 204);
            }

            if (str_contains($url, '/ids?queue=420')) {
                return Http::response(['SG2_NEW', 'SG2_MID', 'SG2_OLD']);   // newest first, like Riot
            }

            if (str_contains($url, '/ids?')) {
                return Http::response([]);
            }

            return Http::response($payloads[basename(parse_url($url, PHP_URL_PATH))] ?? '', isset($payloads[basename(parse_url($url, PHP_URL_PATH))]) ? 200 : 404);
        });

        $this->artisan('riot:sync-matches')->assertSuccessful();

        // All three are stored, but only the two recent ones are announced, oldest first.
        $this->assertSame(3, GameMatch::count());

        $timestamps = array_map(fn ($message) => $message['embeds'][0]['timestamp'], $this->discordMessages());
        $this->assertCount(2, $timestamps);
        $this->assertTrue($timestamps[0] < $timestamps[1], 'the older match must be announced first');

        // Every announcement got its own win streak count: the 3rd win in a row is a milestone, and it is the newest one.
        $streaks = array_map(fn ($message) => count($message['embeds']), $this->discordMessages());
        $this->assertSame([1, 2], $streaks);
    }

    public function test_sync_ranks_announces_a_promotion_found_by_the_command(): void
    {
        $player = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);
        $this->addSnapshot($player, RankedQueue::Solo, 'EMERALD', 'I', 99, capturedAt: now()->subHour());

        $entries = [RiotFixtures::leagueEntry('RANKED_SOLO_5x5', ['tier' => 'DIAMOND', 'rank' => 'IV', 'leaguePoints' => 0])];
        Http::fake(function (Request $request) use (&$entries) {
            return match (true) {
                $request->url() === self::URL => Http::response('', 204),
                str_contains($request->url(), '/summoner/') => Http::response(RiotFixtures::summoner()),
                default => Http::response($entries),
            };
        });

        $this->artisan('riot:sync-ranks')->assertSuccessful();

        $this->assertCount(1, $this->discordMessages());
        $this->assertSame('⬆️ Naik rank!', $this->discordMessages()[0]['embeds'][0]['title']);
    }

    public function test_the_webhook_url_is_never_sent_to_riot(): void
    {
        Http::fake(function (Request $request) {
            return $request->url() === self::URL ? Http::response('', 204) : Http::response([]);
        });
        $player = $this->makePlayer('Alpha');

        $this->record($this->payload('SG2_9001', [$player->puuid]), $player);
        $this->artisan('riot:sync-ranks')->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'riotgames.com') && str_contains(json_encode($request->data()), 'webhooks'));
    }
}
