<?php

namespace Tests\Feature;

use App\Enums\MatchQueue;
use App\Models\Admin;
use App\Models\GameMatch;
use App\Services\Sync\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsGames;
use Tests\Support\RiotFixtures;
use Tests\TestCase;

class QuickMatchSyncTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->withoutVite();
        Cache::put('ddragon.version', '16.1.1');
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }

    /**
     * Riot knows these newest ids for the player; per-mode requests answer from $byQueue, details from $payloads.
     *
     * @param  list<string>  $newest
     * @param  array<int, list<string>>  $byQueue
     * @param  array<string, array<string, mixed>>  $payloads
     */
    private function fakeRiot(array $newest, array $byQueue = [], array $payloads = []): void
    {
        Http::fake([
            '*/by-puuid/*/ids*' => function (Request $request) use ($newest, $byQueue) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return Http::response(isset($query['queue']) ? ($byQueue[(int) $query['queue']] ?? []) : $newest);
            },
            '*/matches/*/timeline' => Http::response('', 404),
            '*/matches/*' => function (Request $request) use ($payloads) {
                $id = basename((string) parse_url($request->url(), PHP_URL_PATH));

                return isset($payloads[$id]) ? Http::response($payloads[$id]) : Http::response('', 404);
            },
        ]);
    }

    private function idRequests(): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), '/ids'))->count();
    }

    public function test_nothing_new_costs_one_request_per_player(): void
    {
        $player = $this->makePlayer('Alpha');
        $match = $this->addGame($player);
        $this->fakeRiot([$match->match_id]);

        $this->artisan('riot:sync-matches', ['--quick' => true])->expectsOutputToContain('0 match baru')->assertSuccessful();

        $this->assertSame(1, $this->idRequests());
    }

    public function test_a_new_game_triggers_the_full_pass_and_is_stored(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->fakeRiot(['SG2_NEW'], [420 => ['SG2_NEW']], ['SG2_NEW' => RiotFixtures::match('SG2_NEW', [$player->puuid], queueId: 420)]);

        $this->artisan('riot:sync-matches', ['--quick' => true])->expectsOutputToContain('1 match baru')->assertSuccessful();

        $this->assertNotNull(GameMatch::where('match_id', 'SG2_NEW')->first());
        // 1 quick check + one request per tracked mode.
        $this->assertSame(1 + count(MatchQueue::tracked()), $this->idRequests());
    }

    public function test_games_of_untracked_modes_are_remembered_and_do_not_trigger_the_full_pass_again(): void
    {
        $this->makePlayer('Alpha');
        // A Swiftplay game: in the newest ids, but not in any tracked mode.
        $this->fakeRiot(['SG2_SWIFT']);

        $this->artisan('riot:sync-matches', ['--quick' => true])->assertSuccessful();
        $first = $this->idRequests();
        $this->artisan('riot:sync-matches', ['--quick' => true])->assertSuccessful();

        $this->assertSame(1 + count(MatchQueue::tracked()), $first);    // the first run did the full pass once
        $this->assertSame($first + 1, $this->idRequests());                         // the second one only the quick check
    }

    public function test_the_normal_run_is_unchanged_and_asks_every_mode(): void
    {
        $this->makePlayer('Alpha');
        $this->fakeRiot([]);

        $this->artisan('riot:sync-matches')->assertSuccessful();

        $this->assertSame(count(MatchQueue::tracked()), $this->idRequests());
    }

    public function test_the_admin_page_warns_when_the_scheduler_is_not_running(): void
    {
        Http::fake();
        $this->actingAs(Admin::create(['username' => 'boss', 'password' => 'throwaway-pass-123']), 'admin');

        $this->get('/admin')->assertOk()->assertSee('Penjadwal (scheduler) tidak berjalan')->assertSee('start-achylstic.bat');

        Cache::put(SchedulerHeartbeat::KEY, now()->toIso8601String());
        $this->get('/admin')->assertDontSee('Penjadwal (scheduler) tidak berjalan');

        $this->travel(SchedulerHeartbeat::STALE_AFTER_MINUTES + 1)->minutes();
        $this->get('/admin')->assertSee('Penjadwal (scheduler) tidak berjalan')->assertSee('Terakhir aktif');
    }
}
