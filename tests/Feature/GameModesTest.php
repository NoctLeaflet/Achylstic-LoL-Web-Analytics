<?php

namespace Tests\Feature;

use App\Enums\MatchQueue;
use App\Enums\RankedQueue;
use App\Models\GameMatch;
use App\Services\Riot\MatchService;
use App\Services\Stats\PairStats;
use App\Services\Stats\PerformanceStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsGames;
use Tests\Support\RiotFixtures;
use Tests\TestCase;

/**
 * Normal Draft, ARAM and ARAM Mayhem next to the ranked queues.
 */
class GameModesTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    private const URL = 'https://discord.com/api/webhooks/123456789012345678/abc-DEF_ghi123';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->withoutVite();
        Cache::put('ddragon.version', '16.1.1');
        Cache::put('ddragon.champions.16.1.1', []);
        Cache::put('ddragon.items.16.1.1', []);
        Cache::put('ddragon.runes.16.1.1', []);
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }

    /** An ARAM-style payload: no lanes (teamPosition is empty) and no vision. */
    private function aramPayload(string $id, array $puuids, int $queueId = 2400, int $minutesAgo = 10): array
    {
        $payload = RiotFixtures::match($id, $puuids, [
            'gameMode' => $queueId === 2400 ? 'KIWI' : 'ARAM',
            'gameDuration' => 1000,
            'gameStartTimestamp' => now()->subMinutes($minutesAgo)->subSeconds(1000)->getTimestamp() * 1000,
        ], $queueId);

        foreach ($payload['info']['participants'] as &$participant) {
            $participant['teamPosition'] = '';
            $participant['individualPosition'] = 'Invalid';
            $participant['visionScore'] = 0;
            $participant['wardsPlaced'] = 0;
        }

        return $payload;
    }

    /**
     * Fake match-v5: ids per queue, details by id, no timelines.
     *
     * @param  array<int, list<string>>  $idsByQueue
     * @param  array<string, array<string, mixed>>  $payloads
     */
    private function fakeRiot(array $idsByQueue, array $payloads): void
    {
        Http::fake(function (Request $request) use ($idsByQueue, $payloads) {
            $url = $request->url();

            if (preg_match('#/ids\?queue=(\d+)#', $url, $found)) {
                return Http::response($idsByQueue[(int) $found[1]] ?? []);
            }

            if (str_ends_with($url, '/timeline')) {
                return Http::response('', 404);
            }

            $id = basename(parse_url($url, PHP_URL_PATH));

            return isset($payloads[$id]) ? Http::response($payloads[$id]) : Http::response('', 404);
        });
    }

    // ---- fetching ------------------------------------------------------------

    public function test_every_tracked_game_mode_is_fetched_and_stored_with_its_queue_id(): void
    {
        $player = $this->makePlayer('Alpha');
        $payloads = [
            'SG2_SOLO' => RiotFixtures::match('SG2_SOLO', [$player->puuid], queueId: 420),
            'SG2_NORMAL' => RiotFixtures::match('SG2_NORMAL', [$player->puuid], queueId: 400),
            'SG2_ARAM' => $this->aramPayload('SG2_ARAM', [$player->puuid], 450),
            'SG2_MAYHEM' => $this->aramPayload('SG2_MAYHEM', [$player->puuid], 2400),
        ];
        $this->fakeRiot([420 => ['SG2_SOLO'], 400 => ['SG2_NORMAL'], 450 => ['SG2_ARAM'], 2400 => ['SG2_MAYHEM']], $payloads);

        $stored = app(MatchService::class)->syncPlayer($player);

        $this->assertSame(4, $stored);
        $this->assertEqualsCanonicalizing(
            ['SG2_SOLO' => 420, 'SG2_NORMAL' => 400, 'SG2_ARAM' => 450, 'SG2_MAYHEM' => 2400],
            GameMatch::pluck('queue_id', 'match_id')->all(),
        );

        // One id request per tracked mode, and none for a mode that is not tracked.
        foreach ([420, 440, 400, 450, 2400] as $queueId) {
            Http::assertSent(fn (Request $request) => str_contains($request->url(), "/ids?queue={$queueId}&"));
        }
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/ids?queue=480'));
    }

    public function test_the_tracked_modes_can_be_narrowed_or_extended_in_the_config(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->fakeRiot([], []);

        config(['services.riot.tracked_queues' => [450]]);
        app(MatchService::class)->syncPlayer($player);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/ids?queue=450&'));

        config(['services.riot.tracked_queues' => [420, 480]]);
        app(MatchService::class)->syncPlayer($player);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/ids?queue=480&'));
    }

    public function test_the_same_match_is_never_stored_twice_even_if_two_modes_list_it(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->fakeRiot([400 => ['SG2_X'], 450 => ['SG2_X']], ['SG2_X' => RiotFixtures::match('SG2_X', [$player->puuid], queueId: 400)]);

        $this->assertSame(1, app(MatchService::class)->syncPlayer($player));
        $this->assertSame(1, GameMatch::count());
    }

    public function test_an_aram_match_without_lanes_or_vision_is_stored_cleanly(): void
    {
        $player = $this->makePlayer('Alpha');

        $match = app(MatchService::class)->store($this->aramPayload('SG2_MAYHEM', [$player->puuid]), [$player->puuid => $player->id]);

        $this->assertSame(2400, $match->queue_id);
        $this->assertSame('KIWI', $match->game_mode);
        $this->assertCount(10, $match->participants);
        $this->assertNull($match->participants->first()->team_position);
        $this->assertSame(0, $match->participants->first()->vision_score);
    }

    // ---- statistics ----------------------------------------------------------

    public function test_stats_can_be_limited_to_each_game_mode_or_cover_all_of_them(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, queueId: 420);
        $this->addGame($player, queueId: 400);
        $this->addGame($player, queueId: 400);
        $this->addGame($player, queueId: 450);
        $this->addGame($player, queueId: 2400);

        $stats = app(PerformanceStats::class);

        $this->assertSame(5, $stats->forPlayer($player->id)['games']);
        $this->assertSame(5, $stats->forPlayer($player->id, null)['games']);
        $this->assertSame(1, $stats->forPlayer($player->id, MatchQueue::Solo)['games']);
        $this->assertSame(2, $stats->forPlayer($player->id, MatchQueue::Normal)['games']);
        $this->assertSame(1, $stats->forPlayer($player->id, MatchQueue::Aram)['games']);
        $this->assertSame(1, $stats->forPlayer($player->id, MatchQueue::AramMayhem)['games']);
        $this->assertSame(0, $stats->forPlayer($player->id, MatchQueue::Flex)['games']);
    }

    public function test_recent_matches_name_the_game_mode(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, queueId: 2400);
        $this->addGame($player, queueId: 400);
        $this->addGame($player, queueId: 1700);   // a mode we do not know

        $rows = app(PerformanceStats::class)->recentMatches($player->id);

        $this->assertEqualsCanonicalizing(['ARAM Mayhem', 'Normal Draft', null], array_column($rows, 'queue'));
    }

    public function test_duo_and_head_to_head_records_work_in_every_game_mode(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');
        $this->addTeamGame([$a, $b], [], blueWins: true, queueId: 450);
        $this->addTeamGame([$a], [$b], blueWins: true, queueId: 2400);

        $pairs = app(PairStats::class);

        $this->assertSame(2, $this->games($pairs->among([$a->id, $b->id])[0]));
        $this->assertSame(1, $pairs->among([$a->id, $b->id], MatchQueue::Aram)[0]['together']['games']);
        $this->assertSame(1, $pairs->among([$a->id, $b->id], MatchQueue::AramMayhem)[0]['versus']['games']);
        $this->assertSame(0, $pairs->among([$a->id, $b->id], MatchQueue::Solo)[0]['together']['games']);
    }

    private function games(array $pair): int
    {
        return $pair['together']['games'] + $pair['versus']['games'];
    }

    // ---- pages ---------------------------------------------------------------

    public function test_the_player_page_offers_all_tracked_modes_and_defaults_to_all_of_them(): void
    {
        Http::fake();
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, queueId: 420);
        $this->addGame($player, queueId: 2400);

        $response = $this->get("/players/{$player->id}")->assertOk();

        $this->assertNull($response->viewData('queue'));
        $this->assertSame(2, $response->viewData('stats')['games']);
        $response->assertSeeInOrder(['Semua', 'Solo/Duo', 'Flex', 'Normal Draft', 'ARAM', 'ARAM Mayhem']);
        $response->assertDontSee('Swiftplay');   // not tracked by default
    }

    public function test_the_toggle_follows_the_tracked_modes_in_the_config(): void
    {
        Http::fake();
        $player = $this->makePlayer('Alpha');
        config(['services.riot.tracked_queues' => [420, 480]]);

        $this->get("/players/{$player->id}")->assertOk()
            ->assertSeeInOrder(['Semua', 'Solo/Duo', 'Swiftplay'])
            ->assertDontSee('ARAM')
            ->assertDontSee('Normal Draft');
    }

    public function test_a_game_mode_filters_stats_champions_and_matches_on_the_player_page(): void
    {
        Http::fake();
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, ['champion_name' => 'Ahri'], queueId: 420);
        $this->addGame($player, ['champion_name' => 'Ziggs', 'champion_id' => 115], queueId: 2400);
        $this->addGame($player, ['champion_name' => 'Ziggs', 'champion_id' => 115], queueId: 2400);

        $response = $this->get("/players/{$player->id}?queue=mayhem")->assertOk();

        $this->assertSame(MatchQueue::AramMayhem, $response->viewData('queue'));
        $this->assertSame(2, $response->viewData('stats')['games']);
        $this->assertSame(['Ziggs'], array_column($response->viewData('topChampions'), 'champion_name'));
        $this->assertCount(2, $response->viewData('recentMatches'));
        $response->assertSee('ARAM Mayhem');
    }

    public function test_unknown_or_all_slugs_mean_all_modes(): void
    {
        Http::fake();
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, queueId: 420);
        $this->addGame($player, queueId: 450);

        foreach (['all', 'nonsense', ''] as $slug) {
            $response = $this->get("/players/{$player->id}?queue={$slug}")->assertOk();

            $this->assertNull($response->viewData('queue'), "queue={$slug}");
            $this->assertSame(2, $response->viewData('stats')['games'], "queue={$slug}");
        }
    }

    public function test_there_is_no_lp_chart_for_modes_without_a_rank_but_the_solo_rank_stays_in_the_header(): void
    {
        Http::fake();
        $player = $this->makePlayer('Alpha');
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'II', 54);
        $this->addSnapshot($player, RankedQueue::Flex, 'SILVER', 'IV', 10);

        foreach (['', '?queue=all', '?queue=aram', '?queue=mayhem', '?queue=normal'] as $query) {
            $response = $this->get("/players/{$player->id}{$query}")->assertOk();

            $this->assertNull($response->viewData('lpChart'), $query);
            $response->assertSee('Grafik LP hanya ada untuk Solo/Duo dan Flex');
            $response->assertDontSee('x-data="lpChart(', false);
            $response->assertSee('Gold II');   // the header shows the Solo/Duo rank
        }

        // Ranked modes keep their chart and their own rank in the header.
        $flex = $this->get("/players/{$player->id}?queue=flex")->assertOk();
        $this->assertNotNull($flex->viewData('lpChart'));
        $flex->assertSee('Silver IV');
    }

    public function test_links_on_the_player_page_keep_the_chosen_mode(): void
    {
        Http::fake();
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');
        $this->addTeamGame([$a, $b], [], blueWins: true, queueId: 2400);

        $this->get("/players/{$a->id}?queue=mayhem")->assertOk()
            ->assertSee("/compare?players={$a->id}&amp;queue=mayhem", false)
            ->assertSee("/players/{$b->id}?queue=mayhem", false);

        $this->get("/players/{$a->id}")->assertOk()
            ->assertSee("/compare?players={$a->id}&amp;queue=all", false);
    }

    public function test_the_compare_page_works_for_every_mode_and_skips_the_lp_chart_where_there_is_none(): void
    {
        Http::fake();
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');
        $this->addTeamGame([$a], [$b], blueWins: true, queueId: 450);

        foreach (['', '&queue=all', '&queue=aram'] as $query) {
            $response = $this->get("/compare?players={$a->id},{$b->id}{$query}")->assertOk();

            $this->assertTrue($response->viewData('ready'));
            $this->assertNull($response->viewData('lpChart'), $query);
            $response->assertSee('Grafik LP hanya ada untuk Solo/Duo dan Flex');
            $response->assertSee('x-data="radarChart(', false);
            $this->assertSame(1, $response->viewData('stats')[$a->id]['games']);
        }

        $solo = $this->get("/compare?players={$a->id},{$b->id}&queue=solo")->assertOk();
        $this->assertNotNull($solo->viewData('lpChart'));
        $this->assertSame(0, $solo->viewData('stats')[$a->id]['games']);

        $this->get("/compare?players={$a->id},{$b->id}&queue=aram")->assertOk()
            ->assertSee('name="queue" value="aram"', false)
            ->assertSee('Data: ARAM.');
    }

    public function test_the_leaderboard_stays_ranked_only(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Solo/Duo')
            ->assertSee('Flex')
            ->assertDontSee('ARAM')
            ->assertDontSee('Normal Draft')
            ->assertDontSee('Semua');
    }

    public function test_an_aram_mayhem_match_page_names_the_mode_and_has_no_lanes(): void
    {
        Http::fake();
        $player = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);
        app(MatchService::class)->store($this->aramPayload('SG2_MAYHEM', [$player->puuid], minutesAgo: 600), [$player->puuid => $player->id]);

        $response = $this->get('/matches/SG2_MAYHEM')->assertOk();

        $response->assertSee('ARAM Mayhem');
        $response->assertSee('Ayam');
        $response->assertSee('Klik seorang pemain untuk melihat item');
        $this->assertSame(10, substr_count($response->getContent(), 'id="build-'));   // one build panel per participant

        // No lane is shown next to the champion name.
        $teams = $response->viewData('teams');
        $this->assertSame([null, null, null, null, null], array_column($teams[0]['participants'], 'position'));
        $response->assertDontSee('· Top');
        $response->assertDontSee('· Support');

        // Scores and badges still work without lanes or vision.
        $this->assertContains('MVP', array_column($teams[0]['participants'], 'badge'));
        $this->assertContains('ACE', array_column($teams[1]['participants'], 'badge'));
    }

    // ---- Discord ---------------------------------------------------------------

    public function test_a_new_mayhem_match_is_announced_with_its_mode_and_counts_for_win_streaks(): void
    {
        config(['services.discord.webhook_url' => self::URL]);
        $this->freezeTime();
        Http::fake([self::URL => Http::response('', 204)]);
        $player = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);

        // Two earlier wins in other modes, then a Mayhem win: three in a row.
        $this->addGame($player, ['win' => true], queueId: 420, startedAt: now()->subHours(6));
        $this->addGame($player, ['win' => true], queueId: 400, startedAt: now()->subHours(5));

        app(MatchService::class)->store($this->aramPayload('SG2_MAYHEM', [$player->puuid]), [$player->puuid => $player->id]);

        $messages = Http::recorded(fn (Request $request) => $request->url() === self::URL)->map(fn ($pair) => $pair[0]->data())->values()->all();

        $this->assertCount(1, $messages);
        $this->assertSame('✅ Menang · ARAM Mayhem', $messages[0]['embeds'][0]['title']);
        $this->assertSame('🔥 Win streak 3!', $messages[0]['embeds'][1]['title']);
        $this->assertSame('**Ayam** menang 3 game berturut-turut.', $messages[0]['embeds'][1]['description']);
    }
}
