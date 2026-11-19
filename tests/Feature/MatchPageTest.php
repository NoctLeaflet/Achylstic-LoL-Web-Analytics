<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Services\Riot\MatchService;
use App\Services\Stats\MatchScoreboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\Support\RiotFixtures;
use Tests\TestCase;

class MatchPageTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::put('ddragon.version', '16.1.1');
        Cache::put('ddragon.champions.16.1.1', []);   // champion images by id; empty = fall back to names
        // Item and rune data of the build panels come from Data Dragon and are cached; empty = "already looked up".
        Cache::put('ddragon.items.16.1.1', []);
        Cache::put('ddragon.runes.16.1.1', []);
        Http::fake();
    }

    public function test_unknown_matches_give_a_404(): void
    {
        $this->get('/matches/SG2_DOES_NOT_EXIST')->assertNotFound();
    }

    public function test_it_shows_both_teams_with_every_participant(): void
    {
        $alpha = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);
        app(MatchService::class)->store(
            RiotFixtures::match('SG2_777', [$alpha->puuid]),
            [$alpha->puuid => $alpha->id],
        );

        $response = $this->get('/matches/SG2_777')->assertOk();

        $response->assertSee('Solo/Duo');
        $response->assertSee('30:00');
        $response->assertSee('Tim Biru');
        $response->assertSee('Tim Merah');
        $response->assertSee('Menang');
        $response->assertSee('Kalah');
        $response->assertSee('SG2_777');
        $response->assertSee('Patch 16.1');

        // 10 participants in 2 teams of 5, tracked player first (top lane).
        $teams = $response->viewData('teams');
        $this->assertCount(2, $teams);
        $this->assertSame([5, 5], array_map(fn ($team) => count($team['participants']), $teams));
        $this->assertSame(['TOP', 'JUNGLE', 'MIDDLE', 'BOTTOM', 'UTILITY'], array_column($teams[0]['participants'], 'position'));

        // The tracked player shows the nickname and links to the player page; strangers show their Riot ID.
        $response->assertSee('Ayam');
        $response->assertSee("/players/{$alpha->id}", false);
        $response->assertSee('Player3#SG2');
    }

    public function test_team_totals_and_results_are_correct(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $beta = $this->makePlayer('Beta');
        $this->addTeamGame([$alpha], [$beta], blueWins: true, stats: ['kills' => 7, 'deaths' => 3, 'assists' => 4, 'gold_earned' => 12000, 'total_damage_dealt_to_champions' => 30000]);

        $teams = $this->get('/matches/SG2_100001')->assertOk()->viewData('teams');

        $this->assertSame([100, 200], array_column($teams, 'team_id'));
        $this->assertSame([true, false], array_column($teams, 'win'));
        $this->assertSame(7, $teams[0]['kills']);
        $this->assertSame(12000, $teams[0]['gold']);
        $this->assertSame(30000, $teams[0]['damage']);
        $this->assertSame(100.0, $teams[0]['participants'][0]['damage_share']);
    }

    public function test_participant_numbers_are_derived_like_everywhere_else(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $this->addGame($alpha, ['kills' => 8, 'deaths' => 2, 'assists' => 10, 'total_minions_killed' => 270, 'neutral_minions_killed' => 30], duration: 1800);

        $scoreboard = app(MatchScoreboard::class)->build(GameMatch::sole());
        $row = $scoreboard['teams'][0]['participants'][0];

        $this->assertSame(9.0, $row['kda']);
        $this->assertSame(300, $row['cs']);
        $this->assertSame(10.0, $row['cs_per_min']);
    }

    public function test_damage_share_is_relative_to_the_team(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $beta = $this->makePlayer('Beta');
        $match = $this->addTeamGame([$alpha, $beta], [], blueWins: true);
        $match->participants()->where('player_id', $alpha->id)->update(['total_damage_dealt_to_champions' => 30000]);
        $match->participants()->where('player_id', $beta->id)->update(['total_damage_dealt_to_champions' => 10000]);

        $participants = app(MatchScoreboard::class)->build($match)['teams'][0]['participants'];

        $this->assertEqualsCanonicalizing([75.0, 25.0], array_column($participants, 'damage_share'));
    }

    public function test_remakes_are_labelled(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $this->addGame($alpha, duration: 200);

        $this->get('/matches/SG2_100001')->assertOk()->assertSee('Remake');
    }

    public function test_the_player_page_links_every_recent_match_to_its_detail_page(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $match = $this->addGame($alpha);

        $this->get("/players/{$alpha->id}")
            ->assertOk()
            ->assertSee("/matches/{$match->match_id}", false);
    }

    public function test_the_page_only_reads_the_database(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $match = $this->addGame($alpha);

        $this->get("/matches/{$match->match_id}")->assertOk();

        Http::assertNothingSent();
    }
}
