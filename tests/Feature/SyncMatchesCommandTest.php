<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\MatchParticipant;
use App\Models\Player;
use App\Services\Riot\MatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\RiotFixtures;
use Tests\TestCase;

class SyncMatchesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }

    private function makePlayer(string $puuid, array $overrides = []): Player
    {
        return Player::create(array_merge([
            'game_name' => strtoupper($puuid),
            'tag_line' => 'SG2',
            'puuid' => $puuid,
            'platform' => 'sg2',
        ], $overrides));
    }

    /**
     * Fake match-v5. Solo queue ids and flex queue ids are given per puuid.
     *
     * @param  array<string, list<string>>  $soloIds
     * @param  array<string, list<string>>  $flexIds
     * @param  list<string>  $participants  puuids placed in every returned match
     */
    private function fakeMatchApi(array $soloIds, array $flexIds = [], array $participants = []): void
    {
        Http::fake(function (Request $request) use ($soloIds, $flexIds, $participants) {
            $url = $request->url();

            if (preg_match('#/by-puuid/([^/]+)/ids\?queue=(\d+)#', $url, $found)) {
                $ids = $found[2] === '420' ? ($soloIds[$found[1]] ?? []) : ($flexIds[$found[1]] ?? []);

                return Http::response($ids);
            }

            if (preg_match('#/matches/(SG2_\d+)$#', $url, $found)) {
                return Http::response(RiotFixtures::match($found[1], $participants));
            }

            return Http::response('', 404);
        });
    }

    public function test_it_stores_new_matches_and_never_fetches_a_known_match_twice(): void
    {
        $a = $this->makePlayer('puuid-a');
        $b = $this->makePlayer('puuid-b', ['display_name' => 'Bebek']);

        // SG2_1 was played by both players together.
        $this->fakeMatchApi(
            soloIds: ['puuid-a' => ['SG2_1', 'SG2_2'], 'puuid-b' => ['SG2_1']],
            flexIds: ['puuid-b' => ['SG2_3']],
            participants: ['puuid-a', 'puuid-b'],
        );

        $this->artisan('riot:sync-matches')
            ->expectsOutputToContain('PUUID-A: 2 match baru')
            ->expectsOutputToContain('Bebek: 1 match baru')
            ->expectsOutputToContain('Selesai: 3 match baru, 0 pemain gagal.')
            ->assertSuccessful();

        $this->assertSame(3, GameMatch::count());
        $this->assertSame(30, MatchParticipant::count());
        $this->assertSame(3, MatchParticipant::where('player_id', $a->id)->count());
        $this->assertSame(3, MatchParticipant::where('player_id', $b->id)->count());

        // SG2_1 appeared in both id lists but its details were fetched once.
        $detailRequests = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/matches/SG2_1'));
        $this->assertCount(1, $detailRequests);

        // A second run only asks for the id lists.
        Http::fake(function (Request $request) {
            return preg_match('#/ids\?queue=420#', $request->url())
                ? Http::response(['SG2_1', 'SG2_2'])
                : Http::response([]);
        });

        $this->artisan('riot:sync-matches')
            ->expectsOutputToContain('Selesai: 0 match baru, 0 pemain gagal.')
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/matches/SG2_'));
        $this->assertSame(3, GameMatch::count());
    }

    public function test_the_count_option_is_sent_to_riot(): void
    {
        $this->makePlayer('puuid-a');
        $this->fakeMatchApi(soloIds: []);

        $this->artisan('riot:sync-matches', ['--count' => 5])->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/ids?queue=420&start=0&count=5'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/ids?queue=440&start=0&count=5'));
    }

    public function test_it_rejects_an_invalid_count(): void
    {
        $this->makePlayer('puuid-a');
        Http::fake();

        foreach (['0', '101', 'abc'] as $count) {
            $this->artisan('riot:sync-matches', ['--count' => $count])
                ->expectsOutputToContain('--count harus berupa angka 1 sampai 100')
                ->assertFailed();
        }

        Http::assertNothingSent();
    }

    public function test_it_warns_when_there_are_no_active_players(): void
    {
        $this->makePlayer('puuid-off', ['is_active' => false]);
        Http::fake();

        $this->artisan('riot:sync-matches')
            ->expectsOutputToContain('Belum ada pemain aktif')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_aborts_with_a_clear_message_when_the_api_key_is_rejected(): void
    {
        $this->makePlayer('puuid-a');
        $this->makePlayer('puuid-b');

        Http::fake(['*' => Http::response('', 401)]);

        $this->artisan('riot:sync-matches')
            ->expectsOutputToContain('API key kedaluwarsa/invalid')
            ->assertFailed();

        Http::assertSentCount(1);
        $this->assertSame(0, GameMatch::count());
    }

    public function test_one_failing_player_does_not_stop_the_others(): void
    {
        $this->makePlayer('puuid-a');
        $this->makePlayer('puuid-b');

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/by-puuid/puuid-a/ids')) {
                return Http::response('', 500);
            }

            if (preg_match('#/by-puuid/puuid-b/ids\?queue=420#', $url)) {
                return Http::response(['SG2_7']);
            }

            if (str_contains($url, '/ids')) {
                return Http::response([]);
            }

            return Http::response(RiotFixtures::match('SG2_7', ['puuid-b']));
        });

        $this->artisan('riot:sync-matches')
            ->expectsOutputToContain('PUUID-A: gagal')
            ->expectsOutputToContain('PUUID-B: 1 match baru')
            ->assertFailed();

        $this->assertSame(['SG2_7'], GameMatch::pluck('match_id')->all());
    }

    public function test_stored_matches_keep_participants_of_other_players(): void
    {
        $this->makePlayer('puuid-a');
        $this->fakeMatchApi(soloIds: ['puuid-a' => ['SG2_1']], participants: ['puuid-a']);

        $this->artisan('riot:sync-matches')->assertSuccessful();

        $match = GameMatch::sole();
        $this->assertCount(10, $match->participants);
        $this->assertSame(1, $match->participants->whereNotNull('player_id')->count());
        $this->assertFalse(app(MatchService::class)->store(RiotFixtures::match('SG2_1')) instanceof GameMatch);
    }
}
