<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\MatchParticipant;
use App\Services\Riot\Exceptions\RiotAuthException;
use App\Services\Riot\MatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsGames;
use Tests\Support\RiotFixtures;
use Tests\TestCase;

class MatchTimelineTest extends TestCase
{
    use BuildsGames;
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

    private function service(): MatchService
    {
        return app(MatchService::class);
    }

    public function test_it_stores_the_build_of_every_participant_and_marks_the_match(): void
    {
        $this->freezeTime();
        $player = $this->makePlayer('Alpha');
        $match = $this->service()->store(RiotFixtures::match('SG2_1', [$player->puuid]), [$player->puuid => $player->id]);

        Http::fake(['*/matches/SG2_1/timeline' => Http::response(RiotFixtures::timeline('SG2_1', [$player->puuid, 'puuid-SG2_1-1'], [
            RiotFixtures::purchase(1, 1055, 14),
            RiotFixtures::purchase(1, 2003, 15),
            RiotFixtures::skill(1, 1, 5),
            RiotFixtures::skill(1, 2, 70),
            RiotFixtures::purchase(2, 1056, 20),
        ]))]);

        $this->assertTrue($this->service()->syncTimeline($match));

        $alpha = MatchParticipant::where('puuid', $player->puuid)->sole();
        $this->assertSame([[14, 1055], [15, 2003]], $alpha->item_purchases);
        $this->assertSame([1, 2], $alpha->skill_order);

        $second = MatchParticipant::where('puuid', 'puuid-SG2_1-1')->sole();
        $this->assertSame([[20, 1056]], $second->item_purchases);

        $this->assertSame(now()->getTimestamp(), $match->fresh()->timeline_fetched_at->getTimestamp());
        Http::assertSent(fn (Request $request) => $request->url() === 'https://sea.api.riotgames.com/lol/match/v5/matches/SG2_1/timeline');
    }

    public function test_a_match_riot_has_no_timeline_for_is_marked_so_it_is_not_asked_again(): void
    {
        $match = $this->service()->store(RiotFixtures::match('SG2_1'));
        Http::fake(['*/timeline' => Http::response('', 404)]);

        $this->assertFalse($this->service()->syncTimeline($match));

        $this->assertNotNull($match->fresh()->timeline_fetched_at);
        $this->assertNull(MatchParticipant::first()->item_purchases);
        $this->assertNull(MatchParticipant::first()->skill_order);
    }

    public function test_sync_player_fetches_the_timeline_of_each_new_match_but_not_of_remakes(): void
    {
        $player = $this->makePlayer('Alpha');

        Http::fake(function (Request $request) use ($player) {
            $url = $request->url();

            if (str_contains($url, '/ids?queue=420')) {
                return Http::response(['SG2_NORMAL', 'SG2_REMAKE']);
            }

            if (str_contains($url, '/ids?')) {
                return Http::response([]);
            }

            if (str_ends_with($url, '/timeline')) {
                return Http::response(RiotFixtures::timeline('SG2_NORMAL', [$player->puuid], [RiotFixtures::purchase(1, 1055, 14)]));
            }

            return Http::response(RiotFixtures::match(basename($url), [$player->puuid], str_contains($url, 'REMAKE') ? ['gameDuration' => 200] : []));
        });

        $this->assertSame(2, $this->service()->syncPlayer($player));

        $normal = GameMatch::where('match_id', 'SG2_NORMAL')->sole();
        $remake = GameMatch::where('match_id', 'SG2_REMAKE')->sole();

        $this->assertNotNull($normal->timeline_fetched_at);
        $this->assertSame([[14, 1055]], $normal->participants()->where('puuid', $player->puuid)->sole()->item_purchases);
        $this->assertNull($remake->timeline_fetched_at);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/matches/SG2_NORMAL/timeline'));
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/matches/SG2_REMAKE/timeline'));
    }

    public function test_a_failing_timeline_never_stops_the_sync_and_is_left_for_later(): void
    {
        $player = $this->makePlayer('Alpha');

        Http::fake(function (Request $request) use ($player) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/ids?queue=420') => Http::response(['SG2_1']),
                str_contains($url, '/ids?') => Http::response([]),
                str_ends_with($url, '/timeline') => Http::response('', 500),
                default => Http::response(RiotFixtures::match('SG2_1', [$player->puuid])),
            };
        });

        $this->assertSame(1, $this->service()->syncPlayer($player));

        $this->assertNull(GameMatch::sole()->timeline_fetched_at);
    }

    public function test_a_rejected_key_still_stops_the_sync_on_a_timeline_request(): void
    {
        $player = $this->makePlayer('Alpha');

        Http::fake(function (Request $request) use ($player) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/ids?queue=420') => Http::response(['SG2_1']),
                str_contains($url, '/ids?') => Http::response([]),
                str_ends_with($url, '/timeline') => Http::response('', 403),
                default => Http::response(RiotFixtures::match('SG2_1', [$player->puuid])),
            };
        });

        $this->expectException(RiotAuthException::class);

        $this->service()->syncPlayer($player);
    }

    public function test_a_timeline_in_an_unexpected_shape_does_not_break_anything(): void
    {
        $match = $this->service()->store(RiotFixtures::match('SG2_1'));
        Http::fake(['*/timeline' => Http::response(['unexpected' => true])]);

        $this->assertFalse($this->service()->syncTimeline($match));
        $this->assertNotNull($match->fresh()->timeline_fetched_at);
    }
}
