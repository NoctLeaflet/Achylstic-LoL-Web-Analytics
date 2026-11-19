<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsGames;
use Tests\Support\RiotFixtures;
use Tests\TestCase;

class SyncTimelinesCommandTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }

    /** Stored matches without a timeline, oldest first, e.g. SG2_100001 is the oldest. */
    private function pendingMatches(int $count, int $duration = 1800): array
    {
        $player = $this->makePlayer('Alpha');
        $matches = [];

        for ($i = 0; $i < $count; $i++) {
            $matches[] = $this->addGame($player, duration: $duration, startedAt: now()->subHours($count - $i));
        }

        return $matches;
    }

    private function fakeTimelines(): void
    {
        Http::fake(function (Request $request) {
            preg_match('#/matches/([^/]+)/timeline$#', $request->url(), $found);

            return Http::response(RiotFixtures::timeline($found[1] ?? 'x', ['puuid-alpha'], [RiotFixtures::purchase(1, 1055, 14)]));
        });
    }

    public function test_it_fetches_pending_timelines_newest_first_up_to_the_limit(): void
    {
        $matches = $this->pendingMatches(5);
        $this->fakeTimelines();

        $this->artisan('riot:sync-timelines', ['--limit' => 2])
            ->expectsOutputToContain('Selesai: 2 timeline tersimpan, 0 tidak tersedia di Riot, 0 gagal. Sisa antrean: 3.')
            ->assertSuccessful();

        // The two newest matches got theirs; the three oldest are still waiting.
        $this->assertSame(
            [false, false, false, true, true],
            array_map(fn (GameMatch $match) => $match->fresh()->timeline_fetched_at !== null, $matches),
        );

        Http::assertSentCount(2);
    }

    public function test_a_second_run_continues_where_the_first_stopped(): void
    {
        $this->pendingMatches(3);
        $this->fakeTimelines();

        $this->artisan('riot:sync-timelines', ['--limit' => 2])->assertSuccessful();
        $this->artisan('riot:sync-timelines', ['--limit' => 2])
            ->expectsOutputToContain('1 timeline tersimpan, 0 tidak tersedia di Riot, 0 gagal. Sisa antrean: 0.')
            ->assertSuccessful();

        $this->artisan('riot:sync-timelines')
            ->expectsOutputToContain('Semua match sudah punya timeline.')
            ->assertSuccessful();

        Http::assertSentCount(3);
    }

    public function test_remakes_and_matches_that_already_have_a_timeline_are_skipped(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, duration: 200);
        $done = $this->addGame($player);
        $done->update(['timeline_fetched_at' => now()]);

        Http::fake();

        $this->artisan('riot:sync-timelines')
            ->expectsOutputToContain('Semua match sudah punya timeline.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_matches_riot_no_longer_has_are_counted_as_unavailable(): void
    {
        $this->pendingMatches(2);
        Http::fake(['*' => Http::response('', 404)]);

        $this->artisan('riot:sync-timelines')
            ->expectsOutputToContain('0 timeline tersimpan, 2 tidak tersedia di Riot, 0 gagal. Sisa antrean: 0.')
            ->assertSuccessful();

        $this->assertSame(0, GameMatch::whereNull('timeline_fetched_at')->count());
    }

    public function test_one_failing_match_does_not_stop_the_others(): void
    {
        $matches = $this->pendingMatches(3);
        $newest = $matches[2]->match_id;

        Http::fake(function (Request $request) use ($newest) {
            return str_contains($request->url(), "/{$newest}/")
                ? Http::response('', 500)
                : Http::response(RiotFixtures::timeline('x', ['puuid-alpha']));
        });

        $this->artisan('riot:sync-timelines')
            ->expectsOutputToContain("{$newest}: gagal")
            ->expectsOutputToContain('2 timeline tersimpan, 0 tidak tersedia di Riot, 1 gagal. Sisa antrean: 1.')
            ->assertFailed();
    }

    public function test_it_aborts_with_a_clear_message_when_the_api_key_is_rejected(): void
    {
        $this->pendingMatches(3);
        Http::fake(['*' => Http::response('', 403)]);

        $this->artisan('riot:sync-timelines')
            ->expectsOutputToContain('API key kedaluwarsa/invalid')
            ->assertFailed();

        Http::assertSentCount(1);
    }

    public function test_it_rejects_an_invalid_limit(): void
    {
        Http::fake();

        foreach (['0', '201', 'abc'] as $limit) {
            $this->artisan('riot:sync-timelines', ['--limit' => $limit])
                ->expectsOutputToContain('--limit harus berupa angka 1 sampai 200')
                ->assertFailed();
        }

        Http::assertNothingSent();
    }

    public function test_the_builds_end_up_on_the_participants(): void
    {
        $matches = $this->pendingMatches(1);
        $this->fakeTimelines();

        $this->artisan('riot:sync-timelines')->assertSuccessful();

        $participant = $matches[0]->participants()->sole();
        $this->assertSame([[14, 1055]], $participant->item_purchases);
    }
}
