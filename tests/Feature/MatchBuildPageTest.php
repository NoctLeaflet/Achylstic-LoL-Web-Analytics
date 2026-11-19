<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Services\Stats\MatchScoreboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

/**
 * The scoreboard with scores, MVP/ACE badges and the build panel of each player.
 */
class MatchBuildPageTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::fake();
        Cache::put('ddragon.version', '16.1.1');
        Cache::put('ddragon.champions.16.1.1', []);   // champion images by id; empty = fall back to names
        Cache::put('ddragon.items.16.1.1', [
            1055 => ['name' => 'Dorans Blade', 'skip' => false],
            3068 => ['name' => 'Sunfire Aegis', 'skip' => false],
            2003 => ['name' => 'Health Potion', 'skip' => true],
        ]);
        Cache::put('ddragon.runes.16.1.1', [
            8000 => ['id' => 8000, 'name' => 'Precision', 'icon' => 'perk-images/Styles/7201_Precision.png', 'slots' => [
                [['id' => 8010, 'name' => 'Conqueror', 'icon' => 'perk-images/Styles/Precision/Conqueror/Conqueror.png'], ['id' => 8008, 'name' => 'LethalTempo', 'icon' => 'perk-images/Styles/Precision/LethalTempo/LethalTempo.png']],
                [['id' => 9111, 'name' => 'Triumph', 'icon' => 'perk-images/Styles/Precision/Triumph.png']],
            ]],
            8200 => ['id' => 8200, 'name' => 'Sorcery', 'icon' => 'perk-images/Styles/7202_Sorcery.png', 'slots' => [
                [['id' => 8214, 'name' => 'SummonAery', 'icon' => 'perk-images/Styles/Sorcery/SummonAery/SummonAery.png']],
                [['id' => 8226, 'name' => 'ManaflowBand', 'icon' => 'perk-images/Styles/Sorcery/ManaflowBand/ManaflowBand.png']],
            ]],
        ]);
    }

    private function perks(): array
    {
        return [
            'styles' => [
                ['style' => 8000, 'selections' => [['perk' => 8010], ['perk' => 9111]]],
                ['style' => 8200, 'selections' => [['perk' => 8226]]],
            ],
            'statPerks' => ['offense' => 5005, 'flex' => 5008, 'defense' => 5011],
        ];
    }

    /** A match with Alpha (blue, wins) against Beta (red) where only Alpha has a build. */
    private function matchWithBuild(): array
    {
        $alpha = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);
        $beta = $this->makePlayer('Beta');

        $match = $this->addTeamGame([$alpha], [$beta], blueWins: true);
        $participant = $match->participants()->where('player_id', $alpha->id)->sole();
        $participant->update([
            'perks' => $this->perks(),
            'item_purchases' => [[8, 1055], [9, 2003], [310, 3068]],
            'skill_order' => [1, 2, 3, 1, 1, 4],
        ]);

        return [$match, $alpha, $beta, $participant];
    }

    public function test_every_participant_has_a_closed_build_panel_and_a_row_that_opens_it(): void
    {
        [$match, , , $participant] = $this->matchWithBuild();

        $response = $this->get("/matches/{$match->match_id}")->assertOk();

        $response->assertSee('open: null,', false);
        $response->assertSee("toggle({$participant->id})", false);
        $response->assertSee("x-show=\"open === {$participant->id}\"", false);
        $response->assertSee("id=\"build-{$participant->id}\"", false);
        $response->assertSee("aria-controls=\"build-{$participant->id}\"", false);
        $this->assertSame(2, substr_count($response->getContent(), 'id="build-'));
        $response->assertSee('Klik seorang pemain untuk melihat item, urutan skill, dan rune-nya');
    }

    public function test_the_build_panel_shows_items_by_minute_without_potions(): void
    {
        [$match] = $this->matchWithBuild();

        $response = $this->get("/matches/{$match->match_id}")->assertOk();

        $response->assertSee('title="Dorans Blade"', false);
        $response->assertSee('title="Sunfire Aegis"', false);
        $response->assertDontSee('Health Potion');
        $response->assertSeeInOrder(['1m', '6m']);   // 8 s -> 1st minute, 310 s -> 6th minute
    }

    public function test_the_build_panel_shows_the_skill_order(): void
    {
        [$match] = $this->matchWithBuild();

        $response = $this->get("/matches/{$match->match_id}")->assertOk();

        $response->assertSee('Urutan skill');
        $response->assertSee('aria-label="Urutan skill"', false);
        // Q was taken at levels 1, 4 and 5.
        $response->assertSeeInOrder(['bg-rose-500 text-white', '>1<']);
    }

    public function test_the_build_panel_shows_the_runes_and_marks_the_picked_ones(): void
    {
        [$match] = $this->matchWithBuild();

        $response = $this->get("/matches/{$match->match_id}")->assertOk();

        $response->assertSee('Precision');
        $response->assertSee('Sorcery');
        $response->assertSee('title="Conqueror"', false);
        $response->assertSee('title="LethalTempo"', false);
        $response->assertSee('cdn/img/perk-images/Styles/Precision/Conqueror/Conqueror.png', false);

        // Picked runes are highlighted, the others dimmed.
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/title="Conqueror"[^>]*ring-2 ring-amber-400|ring-2 ring-amber-400[^>]*title="Conqueror"/s', $html);
        $this->assertMatchesRegularExpression('/title="LethalTempo"[^>]*opacity-30 grayscale|opacity-30 grayscale[^>]*title="LethalTempo"/s', $html);

        $response->assertSee('Attack Speed');
        $response->assertSee('Adaptive Force');
        $response->assertSee('cdn/img/perk-images/StatMods/StatModsAttackSpeedIcon.png', false);
    }

    public function test_players_without_a_timeline_still_get_runes_but_a_hint_for_items(): void
    {
        [$match, , $beta] = $this->matchWithBuild();
        $match->participants()->where('player_id', $beta->id)->sole()->update(['perks' => $this->perks()]);

        $response = $this->get("/matches/{$match->match_id}")->assertOk();

        $response->assertSee('Urutan item belum tersedia untuk match ini');
        $response->assertSee('php artisan riot:sync-timelines');
    }

    public function test_players_without_perks_get_a_note_instead_of_runes(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $match = $this->addGame($alpha);

        $this->get("/matches/{$match->match_id}")
            ->assertOk()
            ->assertSee('Data rune tidak tersedia untuk match ini');
    }

    public function test_the_page_survives_data_dragon_being_unreachable(): void
    {
        Cache::forget('ddragon.items.16.1.1');
        Cache::forget('ddragon.runes.16.1.1');
        Http::fake(['*' => Http::response('', 503)]);
        [$match] = $this->matchWithBuild();

        $response = $this->get("/matches/{$match->match_id}")->assertOk();

        // Shard names do not need Data Dragon; the trees do.
        $response->assertSee('Attack Speed');
        $response->assertSee('Rune utama tidak tersedia');
        $response->assertSee('Rune sekunder tidak tersedia');
    }

    // ---- scores and badges ---------------------------------------------------

    public function test_scores_and_badges_are_part_of_the_scoreboard(): void
    {
        [$match] = $this->matchWithBuild();

        $response = $this->get("/matches/{$match->match_id}")->assertOk();
        $teams = $response->viewData('teams');

        $this->assertSame('MVP', $teams[0]['participants'][0]['badge']);
        $this->assertSame('ACE', $teams[1]['participants'][0]['badge']);
        $this->assertIsInt($teams[0]['participants'][0]['score']);

        $response->assertSee('MVP');
        $response->assertSee('ACE');
        $response->assertSee('bukan skor resmi Riot');
        $response->assertSee('Pemain terbaik tim pemenang', false);
    }

    public function test_the_header_names_the_mvp(): void
    {
        [$match] = $this->matchWithBuild();

        $response = $this->get("/matches/{$match->match_id}")->assertOk();

        $mvp = $response->viewData('mvp');
        $this->assertSame('Ayam', $mvp['name']);
        $response->assertSee("skor {$mvp['score']}");
    }

    public function test_a_remake_has_no_mvp(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $match = $this->addGame($alpha, duration: 200);

        $response = $this->get("/matches/{$match->match_id}")->assertOk();

        $this->assertNull($response->viewData('mvp'));
        $response->assertDontSee('Pemain terbaik tim pemenang', false);
    }

    public function test_the_scoreboard_gives_every_participant_a_build_and_a_score(): void
    {
        [$match] = $this->matchWithBuild();

        $scoreboard = app(MatchScoreboard::class)->build($match);

        foreach ($scoreboard['teams'] as $team) {
            foreach ($team['participants'] as $participant) {
                $this->assertArrayHasKey('score', $participant);
                $this->assertArrayHasKey('build', $participant);
                $this->assertArrayHasKey('has_timeline', $participant['build']);
            }
        }
    }

    public function test_the_player_page_shows_the_score_and_badge_of_each_recent_match(): void
    {
        [$match, $alpha] = $this->matchWithBuild();

        $response = $this->get("/players/{$alpha->id}")->assertOk();

        $recent = $response->viewData('recentMatches')[0];
        $this->assertSame('MVP', $recent['badge']);
        $this->assertIsInt($recent['score']);

        $response->assertSeeInOrder(['Skor', (string) $recent['score'], 'MVP']);
    }

    public function test_remakes_show_no_score_on_the_player_page(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $this->addGame($alpha, duration: 200);

        $this->get("/players/{$alpha->id}")->assertOk()->assertDontSee('Skor');
    }

    public function test_the_page_only_reads_the_database_and_the_cache(): void
    {
        [$match] = $this->matchWithBuild();

        $this->get("/matches/{$match->match_id}")->assertOk();

        Http::assertNothingSent();
    }
}
