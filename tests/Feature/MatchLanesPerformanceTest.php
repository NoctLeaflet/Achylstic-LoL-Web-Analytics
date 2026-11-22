<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Player;
use App\Services\Stats\LaneOverview;
use App\Services\Stats\MatchPerformance;
use App\Services\Stats\MatchScoreboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class MatchLanesPerformanceTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    private const POSITIONS = ['TOP', 'JUNGLE', 'MIDDLE', 'BOTTOM', 'UTILITY'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::put('ddragon.version', '16.1.1');
        Http::fake();
    }

    /**
     * A 5v5 match with real lanes; the blue Top ("Yomu") dominates the red Top, and gold/cs per minute
     * are stored for everyone when $timeline is true (blue Top is 40 gold per minute ahead).
     *
     * @return array{0: GameMatch, 1: array<string, Player>}
     */
    private function match(bool $timeline = true, int $duration = 1800): array
    {
        $blue = [];
        $red = [];
        foreach (self::POSITIONS as $i => $position) {
            $blue[$position] = $this->makePlayer($i === 0 ? 'Yomu' : "Blue{$i}");
            $red[$position] = $this->makePlayer("Red{$i}");
        }

        $match = $this->addTeamGame(array_values($blue), array_values($red), true, $duration, stats: ['kills' => 2, 'deaths' => 4, 'assists' => 3, 'total_damage_dealt_to_champions' => 8000]);

        foreach (['100' => $blue, '200' => $red] as $team => $players) {
            foreach (self::POSITIONS as $position) {
                $match->participants()->where('player_id', $players[$position]->id)->update(['team_position' => $position]);
            }
        }

        // Yomu outplays his lane opponent in every number.
        $match->participants()->where('player_id', $blue['TOP']->id)->update(['kills' => 12, 'deaths' => 1, 'assists' => 6, 'total_damage_dealt_to_champions' => 30000, 'gold_earned' => 16000, 'vision_score' => 40]);

        if ($timeline) {
            foreach ($match->participants()->get() as $p) {
                $ahead = $p->player_id === $blue['TOP']->id ? 40 : 0;
                $p->update(['metrics' => [
                    'gold' => array_map(fn ($m) => 500 + $m * 300 + $m * $ahead, range(0, 30)),
                    'cs' => array_map(fn ($m) => $m * 7, range(0, 30)),
                    'damage' => array_map(fn ($m) => $m * 200, range(0, 30)),
                    'xp' => array_map(fn ($m) => $m * 400, range(0, 30)),
                ]]);
            }
        }

        return [$match, ['blue' => $blue, 'red' => $red]];
    }

    private function board(GameMatch $match): array
    {
        return app(MatchScoreboard::class)->build($match);
    }

    public function test_the_lanes_show_scores_the_difference_and_the_categories_won(): void
    {
        [$match] = $this->match();

        $lanes = $this->board($match)['lanes'];

        $this->assertSame(['Top', 'Jungle', 'Mid', 'Bot', 'Support'], array_column($lanes, 'label'));

        $top = $lanes[0];
        $this->assertSame('Yomu', $top['blue']['name']);
        $this->assertSame('blue', $top['leader']);
        $this->assertGreaterThan(0, $top['diff']);
        $this->assertSame($top['blue']['score'] - $top['red']['score'], $top['diff']);
        $this->assertLessThanOrEqual(100, $top['width']);
        $this->assertContains('KDA', $top['blue_wins']);
        $this->assertContains('DMG', $top['blue_wins']);
        $this->assertSame([], $top['red_wins']);
    }

    public function test_the_bar_width_is_capped_and_scaled_to_the_difference(): void
    {
        $lane = fn (int $blue, int $red) => [
            'position' => 'TOP', 'label' => 'Top',
            'blue' => ['score' => $blue, 'badge' => null], 'red' => ['score' => $red, 'badge' => null],
            'rows' => [],
        ];

        $overview = app(LaneOverview::class)->build([$lane(80, 50), $lane(30, 100), $lane(60, 60)]);

        $this->assertSame([50, 100, 0], array_column($overview, 'width'));
        $this->assertSame(['blue', 'red', null], array_column($overview, 'leader'));
    }

    public function test_the_performance_of_a_tracked_player_against_the_lane_opponent(): void
    {
        [$match] = $this->match();

        $entries = collect($this->board($match)['performance'])->keyBy('name');
        $yomu = $entries['Yomu'];

        $this->assertSame('Red0', $yomu['opponent']['name']);
        $this->assertSame('won', $yomu['lane']['outcome']);
        $this->assertSame(14, $yomu['lane']['minute']);
        $this->assertSame(560, $yomu['lane']['gold']);                 // 40 gold per minute * 14
        $this->assertSame(31, count($yomu['lane']['diff']));
        $this->assertSame(['CS at 10', 'Vision score', 'Damage to champions'], array_column($yomu['cards'], 'label'));
        $this->assertSame(70, $yomu['cards'][0]['me']);
        $this->assertSame(70, $yomu['cards'][0]['opponent']);
        $this->assertSame(30000, $yomu['cards'][2]['me']);
        $this->assertSame(1, $yomu['place']);                          // best score of the lobby

        // The opponent sees the same lane from the other side.
        $this->assertSame('lost', $entries['Red0']['lane']['outcome']);
        $this->assertSame(-560, $entries['Red0']['lane']['gold']);
    }

    public function test_without_a_timeline_there_is_no_gold_chart_but_the_numbers_stay(): void
    {
        [$match] = $this->match(timeline: false);

        $yomu = collect($this->board($match)['performance'])->firstWhere('name', 'Yomu');

        $this->assertNull($yomu['lane']);
        $this->assertSame('CS', $yomu['cards'][0]['label']);
        $this->assertSame(30000, $yomu['cards'][2]['me']);
    }

    public function test_a_small_gold_difference_is_an_even_lane(): void
    {
        [$match, $players] = $this->match();
        $match->participants()->where('player_id', $players['blue']['TOP']->id)->update(['metrics' => [
            'gold' => array_map(fn ($m) => 500 + $m * 300 + $m * 10, range(0, 30)), 'cs' => range(0, 30), 'damage' => range(0, 30), 'xp' => range(0, 30),
        ]]);

        $yomu = collect($this->board($match->fresh())['performance'])->firstWhere('name', 'Yomu');

        $this->assertSame('even', $yomu['lane']['outcome']);
    }

    public function test_remakes_have_no_performance(): void
    {
        [$match] = $this->match(duration: 200);

        $this->assertSame([], app(MatchPerformance::class)->build([], $match->participants()->get(), $match));
        $this->assertSame([], $this->board($match)['lanes']);
    }

    public function test_the_match_page_has_the_two_buttons_and_both_pop_ups(): void
    {
        [$match] = $this->match();

        $this->get(route('matches.show', $match->match_id))
            ->assertOk()
            ->assertSee('Lanes')
            ->assertSee('Performance')
            ->assertSee('lanesOpen', false)
            ->assertSee('performanceOpen', false)
            ->assertSeeInOrder(['Yomu', 'won lane,', '+560 gold at 14 minutes'])
            ->assertSee('goldDiffChart(', false)
            ->assertSee('1st in lobby')
            ->assertSee('Also in this game');
    }

    public function test_the_pages_say_win_and_lose_instead_of_menang_and_kalah(): void
    {
        [$match, $players] = $this->match();

        $this->get(route('matches.show', $match->match_id))->assertSee('Win')->assertSee('Lose')->assertDontSee('Menang')->assertDontSee('Kalah');
        $this->get(route('players.show', $players['blue']['TOP']))->assertSee('Win');
    }
}
