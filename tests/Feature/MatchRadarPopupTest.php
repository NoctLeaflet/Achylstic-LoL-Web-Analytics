<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Player;
use App\Services\Riot\MatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\Support\RiotFixtures;
use Tests\TestCase;

/**
 * The lane radar pop-up on the match page, its entry points, and the "Kembali" links.
 */
class MatchRadarPopupTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::fake();
        Cache::put('ddragon.version', '16.1.1');
        Cache::put('ddragon.champions.16.1.1', []);
        Cache::put('ddragon.items.16.1.1', []);
        Cache::put('ddragon.runes.16.1.1', []);
    }

    /**
     * Alpha (blue, top) and Gamma (blue, support) against Beta (red, top) and Delta (red, support).
     *
     * @return array{0: GameMatch, 1: array<string, Player>}
     */
    private function laneMatch(): array
    {
        $players = [
            'alpha' => $this->makePlayer('Alpha', ['display_name' => 'Ayam']),
            'gamma' => $this->makePlayer('Gamma'),
            'beta' => $this->makePlayer('Beta', ['display_name' => 'Bebek']),
            'delta' => $this->makePlayer('Delta'),
        ];

        $match = $this->addTeamGame([$players['alpha'], $players['gamma']], [$players['beta'], $players['delta']], blueWins: true);

        $setup = [
            'alpha' => ['team_position' => 'TOP', 'champion_name' => 'Renekton', 'kills' => 9, 'deaths' => 2, 'assists' => 4],
            'beta' => ['team_position' => 'TOP', 'champion_name' => 'Teemo', 'kills' => 2, 'deaths' => 7, 'assists' => 3],
            'gamma' => ['team_position' => 'UTILITY', 'champion_name' => 'Thresh', 'kills' => 1, 'deaths' => 3, 'assists' => 18, 'vision_score' => 70],
            'delta' => ['team_position' => 'UTILITY', 'champion_name' => 'Leona', 'kills' => 1, 'deaths' => 6, 'assists' => 9, 'vision_score' => 40],
        ];

        foreach ($setup as $key => $stats) {
            $match->participants()->where('player_id', $players[$key]->id)->sole()->update($stats);
        }

        return [$match, $players];
    }

    // ---- the pop-up ------------------------------------------------------------

    public function test_the_page_has_a_dialog_with_a_tab_and_a_radar_for_every_lane(): void
    {
        [$match] = $this->laneMatch();

        $response = $this->get("/matches/{$match->match_id}")->assertOk();

        $response->assertSee('role="dialog"', false);
        $response->assertSee('aria-modal="true"', false);
        $response->assertSee('aria-labelledby="matchup-title"', false);
        $response->assertSee('id="matchup-title"', false);
        $response->assertSee('Compare lane');
        $response->assertDontSee('Radar lane');
        $response->assertSee('role="tablist"', false);
        $response->assertSeeInOrder(['role="tab"', 'Top', 'role="tab"', 'Support']);

        $matchups = $response->viewData('matchups');
        $this->assertSame(['TOP', 'UTILITY'], array_column($matchups, 'position'));
        $this->assertSame(2, substr_count($response->getContent(), 'x-data="radarChart('));   // one chart per lane
    }

    public function test_the_pop_up_names_both_laners_and_their_numbers(): void
    {
        [$match] = $this->laneMatch();

        $response = $this->get("/matches/{$match->match_id}")->assertOk();

        $response->assertSee('Tim Biru');
        $response->assertSee('Tim Merah');
        $response->assertSee('Renekton');
        $response->assertSee('Teemo');
        $response->assertSee('Thresh');
        $response->assertSee('Leona');
        $response->assertSee('Partisipasi kill %');
        $response->assertSee('Damage/mnt');
        $response->assertSee('Vision/mnt');

        // The chart data: both colours, scaled values and the real values.
        $response->assertSee('#38bdf8', false);
        $response->assertSee('#fb7185', false);
        $top = $response->viewData('matchups')[0];
        $this->assertSame('Ayam', $top['radar']['datasets'][0]['label']);
        $this->assertSame('Bebek', $top['radar']['datasets'][1]['label']);
        $this->assertSame(100.0, $top['radar']['datasets'][0]['values'][0]);   // Alpha has the better KDA
        $this->assertSame('blue', $top['rows'][0]['winner']);
    }

    public function test_the_colour_legend_sits_on_the_right_shows_player_names_only_and_the_chart_has_no_legend_of_its_own(): void
    {
        [$match] = $this->laneMatch();

        $response = $this->get("/matches/{$match->match_id}")->assertOk();
        $html = $response->getContent();

        // One legend per lane, each with the two player names and their colours.
        $this->assertSame(2, substr_count($html, 'aria-label="Keterangan warna"'));
        $response->assertSee('title="Ayam"', false);
        $response->assertSee('title="Bebek"', false);
        $response->assertSee('background-color: #38bdf8', false);
        $response->assertSee('background-color: #fb7185', false);

        // Vertically centred on the right from the md breakpoint, under the chart on small screens.
        $response->assertSee('md:absolute md:right-0 md:top-1/2', false);
        $response->assertSee('md:-translate-y-1/2', false);

        // No champion in the legend (the scoreboard rows may still mention it elsewhere)...
        preg_match_all('#aria-label="Keterangan warna".*?</ul>#s', $html, $legends);
        $this->assertCount(2, $legends[0]);
        foreach ($legends[0] as $legend) {
            $this->assertStringNotContainsString('(', $legend);
            foreach (['Renekton', 'Teemo', 'Thresh', 'Leona'] as $champion) {
                $this->assertStringNotContainsString($champion, $legend);
            }
        }
        $this->assertStringContainsString('Ayam', $legends[0][0]);
        $this->assertStringContainsString('Bebek', $legends[0][0]);

        // ...and Chart.js' own legend is switched off for these charts.
        $this->assertSame(2, substr_count($html, '{ legend: false }'));
    }

    public function test_a_lane_is_opened_from_the_scoreboard_row_the_header_and_the_build_panel(): void
    {
        [$match, $players] = $this->laneMatch();

        $response = $this->get("/matches/{$match->match_id}")->assertOk();
        $html = $response->getContent();

        // The header button opens the first lane.
        $response->assertSee("openMatchup('TOP', \$event)", false);
        // Row buttons: one per participant that has an opponent in the same role (4).
        $this->assertSame(4, substr_count($html, 'title="Compare dengan lawan lane"'));
        $response->assertSee('aria-label="Bandingkan Ayam dengan lawan lane (Top)"', false);
        $response->assertSee('aria-label="Bandingkan Gamma dengan lawan lane (Support)"', false);
        // And each build panel can jump to the radar too.
        $this->assertSame(4, substr_count($html, '>Compare dengan lawan lane</span>'));
    }

    public function test_the_page_can_open_a_lane_straight_from_the_url(): void
    {
        [$match] = $this->laneMatch();

        $response = $this->get("/matches/{$match->match_id}?vs=UTILITY")->assertOk();

        $response->assertSee("new URLSearchParams(window.location.search).get('vs')", false);
        // The script only accepts lanes that exist in this match (here top and support, no jungle).
        $response->assertSee('.includes(lane)', false);
        $response->assertSee('TOP', false);
        $response->assertSee('UTILITY', false);
        $response->assertDontSee('JUNGLE', false);
    }

    public function test_the_pop_up_can_be_closed_with_the_keyboard_and_by_clicking_outside(): void
    {
        [$match] = $this->laneMatch();

        $response = $this->get("/matches/{$match->match_id}")->assertOk();

        $response->assertSee('@keydown.escape.window', false);
        $response->assertSee('@click.self="closeMatchup()"', false);
        $response->assertSee('aria-label="Tutup"', false);
        $response->assertSee("document.body.classList.toggle('overflow-hidden'", false);
    }

    // ---- when there is nothing to compare ----------------------------------------

    public function test_a_match_without_lanes_has_no_pop_up_and_no_buttons(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $payload = RiotFixtures::match('SG2_ARAM', [$alpha->puuid], queueId: 450);
        foreach ($payload['info']['participants'] as &$participant) {
            $participant['teamPosition'] = '';
        }
        app(MatchService::class)->store($payload, [$alpha->puuid => $alpha->id]);

        $response = $this->get('/matches/SG2_ARAM?vs=TOP')->assertOk();

        $this->assertSame([], $response->viewData('matchups'));
        $response->assertDontSee('id="matchup-title"', false)->assertDontSee('id="lanes-title"', false);
        $response->assertDontSee('Compare lane');
        $this->assertSame(0, substr_count($response->getContent(), 'title="Compare dengan lawan lane"'));
    }

    public function test_remakes_have_no_pop_up(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $beta = $this->makePlayer('Beta');
        $match = $this->addTeamGame([$alpha], [$beta], blueWins: true, duration: 200);

        $response = $this->get("/matches/{$match->match_id}")->assertOk();

        $this->assertSame([], $response->viewData('matchups'));
        $response->assertDontSee('id="matchup-title"', false)->assertDontSee('id="lanes-title"', false);
    }

    public function test_a_lane_that_is_missing_on_one_team_has_no_tab(): void
    {
        [$match, $players] = $this->laneMatch();
        // Delta (red support) plays jungle instead: no support opponent any more.
        $match->participants()->where('player_id', $players['delta']->id)->sole()->update(['team_position' => 'JUNGLE']);

        $response = $this->get("/matches/{$match->match_id}")->assertOk();

        $this->assertSame(['TOP'], array_column($response->viewData('matchups'), 'position'));
        $response->assertDontSee('aria-label="Bandingkan Gamma dengan lawan lane', false);
    }

    // ---- entry point from the match history ---------------------------------------

    public function test_every_recent_match_with_a_lane_links_to_the_pop_up_on_that_lane(): void
    {
        [$match, $players] = $this->laneMatch();

        $response = $this->get("/players/{$players['gamma']->id}?queue=all")->assertOk();

        $response->assertSee("/matches/{$match->match_id}?vs=UTILITY", false);
        $response->assertSee('title="Compare dengan lawan lane"', false);
        $response->assertDontSee('Radar');
    }

    public function test_remakes_and_lane_less_matches_have_no_radar_link_in_the_history(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $this->addGame($alpha, duration: 200);                              // remake
        $this->addGame($alpha, ['team_position' => null], queueId: 450);    // ARAM style, no lane

        $this->get("/players/{$alpha->id}")->assertOk()->assertDontSee('title="Compare dengan lawan lane"', false);
    }

    // ---- "Kembali" ------------------------------------------------------------------

    public function test_the_match_page_goes_back_to_the_previous_page_and_falls_back_to_the_leaderboard(): void
    {
        [$match] = $this->laneMatch();

        $response = $this->get("/matches/{$match->match_id}")->assertOk();

        $response->assertSee('Kembali');
        $response->assertSee('href="'.route('leaderboard').'"', false);
        $response->assertSee('window.history.back()', false);
        $response->assertSee('document.referrer', false);
        $response->assertSee('window.location.origin', false);
        $response->assertDontSee('&larr; Leaderboard', false);
    }

    public function test_the_player_page_goes_back_to_the_previous_page_and_falls_back_to_the_leaderboard(): void
    {
        $alpha = $this->makePlayer('Alpha');

        $response = $this->get("/players/{$alpha->id}?queue=flex")->assertOk();

        $response->assertSee('Kembali');
        // Viewing Flex, so the fallback is the Flex leaderboard.
        $response->assertSee('href="'.route('leaderboard', ['queue' => 'flex']).'"', false);
        $response->assertSee('window.history.back()', false);
        $response->assertDontSee('&larr; Leaderboard', false);
    }
}
