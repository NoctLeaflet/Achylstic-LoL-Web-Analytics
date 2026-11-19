<?php

namespace Tests\Feature;

use App\Enums\RankedQueue;
use App\Models\GameMatch;
use App\Models\MatchParticipant;
use App\Models\Player;
use App\Models\RankSnapshot;
use App\Services\Riot\AccountService;
use App\Services\Riot\LeagueService;
use App\Services\Riot\MatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\RiotFixtures;
use Tests\TestCase;

class RiotServicesTest extends TestCase
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

    private function makePlayer(array $overrides = []): Player
    {
        return Player::create(array_merge([
            'game_name' => 'Hide on bush',
            'tag_line' => 'KR1',
            'puuid' => 'puuid-player-1',
            'platform' => 'sg2',
        ], $overrides));
    }

    // ---- AccountService -------------------------------------------------

    public function test_account_lookup_encodes_the_riot_id_and_returns_the_canonical_name(): void
    {
        Http::fake(['*' => Http::response(['puuid' => 'p-1', 'gameName' => 'Hide on bush', 'tagLine' => 'KR1'])]);

        $account = app(AccountService::class)->findByRiotId('hide on bush', 'kr1');

        $this->assertSame(['puuid' => 'p-1', 'game_name' => 'Hide on bush', 'tag_line' => 'KR1'], $account);
        Http::assertSent(fn (Request $request) => $request->url()
            === 'https://asia.api.riotgames.com/riot/account/v1/accounts/by-riot-id/hide%20on%20bush/kr1');
    }

    public function test_account_lookup_returns_null_for_an_unknown_riot_id(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->assertNull(app(AccountService::class)->findByRiotId('Nobody', 'NONE'));
    }

    // ---- LeagueService --------------------------------------------------

    public function test_rank_entries_only_include_the_tracked_queues(): void
    {
        Http::fake(['*' => Http::response([
            RiotFixtures::leagueEntry('RANKED_SOLO_5x5', ['tier' => 'EMERALD', 'rank' => 'IV', 'leaguePoints' => 12]),
            RiotFixtures::leagueEntry('RANKED_FLEX_SR', ['tier' => 'GOLD']),
            RiotFixtures::leagueEntry('RANKED_TFT_DOUBLE_UP'),
        ])]);

        $entries = app(LeagueService::class)->fetchRankEntries('puuid-player-1');

        $this->assertCount(2, $entries);
        $this->assertSame(RankedQueue::Solo, $entries[0]['queue_type']);
        $this->assertSame(
            ['queue_type' => RankedQueue::Solo, 'tier' => 'EMERALD', 'rank' => 'IV', 'league_points' => 12, 'wins' => 10, 'losses' => 8],
            $entries[0],
        );
        $this->assertSame(RankedQueue::Flex, $entries[1]['queue_type']);
    }

    public function test_sync_ranks_only_stores_a_snapshot_when_something_changed(): void
    {
        $player = $this->makePlayer();
        $service = app(LeagueService::class);

        $entries = [RiotFixtures::leagueEntry('RANKED_SOLO_5x5'), RiotFixtures::leagueEntry('RANKED_FLEX_SR', ['tier' => 'SILVER'])];
        Http::fake(function () use (&$entries) {
            return Http::response($entries);
        });

        // First sync: one snapshot per queue.
        $this->assertSame(2, $service->syncRanks($player));
        $this->assertSame(2, RankSnapshot::count());

        // Nothing changed: no new rows.
        $this->assertSame(0, $service->syncRanks($player));
        $this->assertSame(2, RankSnapshot::count());

        // LP and a win/loss changed on solo only: exactly one new row.
        $entries[0] = RiotFixtures::leagueEntry('RANKED_SOLO_5x5', ['leaguePoints' => 74, 'wins' => 11]);
        $this->assertSame(1, $service->syncRanks($player));

        $latest = $player->rankSnapshots()->where('queue_type', 'RANKED_SOLO_5x5')->orderByDesc('id')->first();
        $this->assertSame(74, $latest->league_points);
        $this->assertSame(11, $latest->wins);
        $this->assertSame(3, RankSnapshot::count());

        // A promotion with the same LP/wins/losses is still a change.
        $entries[0] = RiotFixtures::leagueEntry('RANKED_SOLO_5x5', ['tier' => 'PLATINUM', 'rank' => 'IV', 'leaguePoints' => 74, 'wins' => 11]);
        $this->assertSame(1, $service->syncRanks($player));
    }

    public function test_sync_ranks_stores_nothing_for_an_unranked_player(): void
    {
        Http::fake(['*' => Http::response([])]);

        $this->assertSame(0, app(LeagueService::class)->syncRanks($this->makePlayer()));
        $this->assertSame(0, RankSnapshot::count());
    }

    public function test_league_requests_use_the_platform_of_the_player(): void
    {
        Http::fake(['*' => Http::response([])]);

        app(LeagueService::class)->syncRanks($this->makePlayer(['platform' => 'ph2']));

        Http::assertSent(fn (Request $request) => $request->url()
            === 'https://ph2.api.riotgames.com/lol/league/v4/entries/by-puuid/puuid-player-1');
    }

    public function test_refresh_profile_updates_icon_and_level_only_when_they_changed(): void
    {
        $player = $this->makePlayer();
        $profile = RiotFixtures::summoner();
        Http::fake(function () use (&$profile) {
            return Http::response($profile);
        });

        $service = app(LeagueService::class);

        $this->assertTrue($service->refreshProfile($player));
        $this->assertSame(4567, $player->fresh()->profile_icon_id);
        $this->assertSame(123, $player->fresh()->summoner_level);

        $this->assertFalse($service->refreshProfile($player->fresh()));

        $profile = RiotFixtures::summoner(['summonerLevel' => 124]);
        $this->assertTrue($service->refreshProfile($player->fresh()));
        $this->assertSame(124, $player->fresh()->summoner_level);
    }

    // ---- MatchService ---------------------------------------------------

    public function test_match_ids_are_requested_with_queue_start_and_count(): void
    {
        Http::fake(['*' => Http::response(['SG2_3', 'SG2_2', 'SG2_1'])]);

        $ids = app(MatchService::class)->fetchMatchIds('puuid-player-1', 20, 420);

        $this->assertSame(['SG2_3', 'SG2_2', 'SG2_1'], $ids);
        Http::assertSent(fn (Request $request) => $request->url()
            === 'https://sea.api.riotgames.com/lol/match/v5/matches/by-puuid/puuid-player-1/ids?queue=420&start=0&count=20');
    }

    public function test_store_saves_the_match_with_all_ten_participants_and_links_tracked_players(): void
    {
        $player = $this->makePlayer();
        $payload = RiotFixtures::match('SG2_1001', [$player->puuid]);

        $match = app(MatchService::class)->store($payload, Player::pluck('id', 'puuid')->all());

        $this->assertNotNull($match);
        $this->assertSame('SG2_1001', $match->match_id);
        $this->assertSame(420, $match->queue_id);
        $this->assertSame(1800, $match->game_duration);
        $this->assertSame('CLASSIC', $match->game_mode);
        $this->assertSame(1763510400, $match->game_start_at->getTimestamp());
        $this->assertCount(10, $match->participants);

        $tracked = MatchParticipant::where('player_id', $player->id)->get();
        $this->assertCount(1, $tracked);
        $this->assertSame($player->puuid, $tracked->first()->puuid);
        $this->assertSame('Player0', $tracked->first()->riot_id_name);
        $this->assertSame([3020, 6655, 0, 0, 0, 0, 3340], $tracked->first()->items);
        $this->assertSame([4, 14], $tracked->first()->summoner_spells);
        $this->assertSame(8100, $tracked->first()->perks['styles'][0]['style']);

        $this->assertSame(1, MatchParticipant::whereNotNull('player_id')->count());
        $this->assertSame(5, MatchParticipant::where('win', true)->count());
    }

    public function test_storing_the_same_match_twice_does_not_duplicate_it(): void
    {
        $service = app(MatchService::class);
        $payload = RiotFixtures::match('SG2_1001');

        $this->assertNotNull($service->store($payload));
        $this->assertNull($service->store($payload));

        $this->assertSame(1, GameMatch::count());
        $this->assertSame(10, MatchParticipant::count());
    }

    public function test_malformed_payloads_are_skipped(): void
    {
        $service = app(MatchService::class);

        $this->assertNull($service->store(['metadata' => ['matchId' => 'SG2_1'], 'info' => []]));
        $this->assertNull($service->store([]));
        $this->assertSame(0, GameMatch::count());
    }

    public function test_sync_player_only_fetches_details_of_matches_that_are_not_stored_yet(): void
    {
        $player = $this->makePlayer();
        $service = app(MatchService::class);

        // SG2_1 is already in the database.
        $service->store(RiotFixtures::match('SG2_1', [$player->puuid]), [$player->puuid => $player->id]);

        Http::fake(function (Request $request) use ($player) {
            $url = $request->url();

            if (str_contains($url, '/ids')) {
                return Http::response(str_contains($url, 'queue=420') ? ['SG2_1', 'SG2_2'] : ['SG2_3']);
            }

            if (preg_match('#/matches/(SG2_\d+)$#', $url, $found)) {
                $queueId = $found[1] === 'SG2_3' ? 440 : 420;

                return Http::response(RiotFixtures::match($found[1], [$player->puuid], queueId: $queueId));
            }

            return Http::response('', 404);
        });

        $stored = $service->syncPlayer($player, 20);

        $this->assertSame(2, $stored);
        $this->assertSame(3, GameMatch::count());
        $this->assertSame(30, MatchParticipant::count());
        $this->assertSame(3, MatchParticipant::where('player_id', $player->id)->count());

        // Existing matches are never fetched again.
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/matches/SG2_1'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/matches/SG2_2'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/matches/SG2_3'));
    }

    public function test_sync_player_skips_matches_that_riot_no_longer_has(): void
    {
        $player = $this->makePlayer();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/ids')) {
                return Http::response(str_contains($request->url(), 'queue=420') ? ['SG2_404'] : []);
            }

            return Http::response('', 404);
        });

        $this->assertSame(0, app(MatchService::class)->syncPlayer($player));
        $this->assertSame(0, GameMatch::count());
    }
}
