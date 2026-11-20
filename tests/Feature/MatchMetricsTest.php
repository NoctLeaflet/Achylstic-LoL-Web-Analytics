<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Services\Riot\MatchService;
use App\Services\Riot\TimelineParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\Support\RiotFixtures;
use Tests\TestCase;

class MatchMetricsTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::put('ddragon.version', '16.1.1');
        Http::fake();
    }

    /** A timeline whose three frames carry per-minute totals for participants 1 and 2. */
    private function timelineWithFrames(array $puuids): array
    {
        $timeline = RiotFixtures::timeline('SG2_1', $puuids);
        $frame = fn (int $gold, int $xp, int $cs, int $jungle, int $damage) => [
            'totalGold' => $gold, 'xp' => $xp, 'minionsKilled' => $cs, 'jungleMinionsKilled' => $jungle,
            'damageStats' => ['totalDamageDoneToChampions' => $damage],
        ];

        $timeline['info']['frames'] = [
            ['events' => [], 'participantFrames' => ['1' => $frame(500, 0, 0, 0, 0), '2' => $frame(500, 0, 0, 0, 0)]],
            ['events' => [], 'participantFrames' => ['1' => $frame(900, 400, 8, 2, 150), '2' => $frame(700, 300, 5, 0, 40)]],
            ['events' => [], 'participantFrames' => ['1' => $frame(1500, 900, 20, 4, 600), '2' => $frame(1100, 700, 12, 1, 90)]],
        ];

        return $timeline;
    }

    public function test_the_parser_keeps_per_minute_totals_per_player(): void
    {
        $result = (new TimelineParser)->parse($this->timelineWithFrames(['alpha', 'beta']));

        $this->assertSame([500, 900, 1500], $result['alpha']['metrics']['gold']);
        $this->assertSame([0, 150, 600], $result['alpha']['metrics']['damage']);
        $this->assertSame([0, 400, 900], $result['alpha']['metrics']['xp']);
        $this->assertSame([0, 10, 24], $result['alpha']['metrics']['cs']);   // lane + jungle minions
        $this->assertSame([500, 700, 1100], $result['beta']['metrics']['gold']);
    }

    public function test_players_without_frames_get_no_metrics(): void
    {
        $result = (new TimelineParser)->parse(RiotFixtures::timeline('SG2_1', ['alpha']));

        $this->assertNull($result['alpha']['metrics']);
    }

    public function test_storing_a_timeline_saves_the_metrics_on_the_participant(): void
    {
        $player = $this->makePlayer('Alpha');
        $match = $this->addGame($player);

        app(MatchService::class)->storeTimeline($match, $this->timelineWithFrames([$player->puuid]));

        $this->assertSame([500, 900, 1500], $match->participants()->first()->metrics['gold']);
    }

    /** A 5v5 match; the first participant of each team gets per-minute metrics. */
    private function matchWithMetrics(bool $withMetrics = true, int $duration = 1800): GameMatch
    {
        $blue = array_map(fn ($n) => $this->makePlayer("Blue{$n}"), range(1, 5));
        $red = array_map(fn ($n) => $this->makePlayer("Red{$n}"), range(1, 5));
        $match = $this->addTeamGame($blue, $red, true, $duration);

        if ($withMetrics) {
            foreach ($match->participants as $participant) {
                $participant->update(['metrics' => [
                    'gold' => [500, 1000 + $participant->id, 2000],
                    'damage' => [0, 100, 900],
                    'xp' => [0, 500, 1500],
                    'cs' => [0, 8, 30],
                ]]);
            }
        }

        return $match;
    }

    public function test_the_match_page_offers_the_metrics_pop_up_when_the_timeline_has_numbers(): void
    {
        $match = $this->matchWithMetrics();

        $response = $this->get(route('matches.show', $match->match_id))
            ->assertOk()
            ->assertSee('Metrics')
            ->assertSee('metricsChart(', false)
            ->assertSee('metricsOpen', false);

        $metrics = $response->viewData('metrics');
        $this->assertCount(10, $metrics['players']);
        $this->assertSame([0, 1, 2], $metrics['minutes']);
        $this->assertSame(['gold' => 'Gold', 'damage' => 'Damage', 'xp' => 'Exp', 'cs' => 'CS'], $metrics['metrics']);
        $this->assertSame([500, 1000 + $metrics['players'][0]['id'], 2000], $metrics['players'][0]['series']['gold']);
        // Blue and red team use different colour families.
        $this->assertNotSame($metrics['players'][0]['color'], $metrics['players'][5]['color']);
        $this->assertSame(['blue', 'red'], [$metrics['players'][0]['team'], $metrics['players'][5]['team']]);
    }

    public function test_there_is_no_metrics_button_without_timeline_numbers(): void
    {
        $match = $this->matchWithMetrics(withMetrics: false);

        $response = $this->get(route('matches.show', $match->match_id))->assertOk();

        $this->assertNull($response->viewData('metrics'));
        $response->assertDontSee('metricsChart(', false);
    }

    public function test_remakes_have_no_metrics(): void
    {
        $match = $this->matchWithMetrics(duration: 200);

        $this->assertNull($this->get(route('matches.show', $match->match_id))->viewData('metrics'));
    }
}
