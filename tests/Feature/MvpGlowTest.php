<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class MvpGlowTest extends TestCase
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

    /** A 5v5 match where Alpha (blue, wins) is clearly the best player and Omega (red, loses) the best loser. */
    private function match(): array
    {
        $alpha = $this->makePlayer('Alpha');
        $blue = [$alpha, ...array_map(fn ($n) => $this->makePlayer("B{$n}"), range(1, 4))];
        $omega = $this->makePlayer('Omega');
        $red = [$omega, ...array_map(fn ($n) => $this->makePlayer("R{$n}"), range(1, 4))];
        $match = $this->addTeamGame($blue, $red, true, stats: ['kills' => 1, 'deaths' => 8, 'assists' => 1, 'total_damage_dealt_to_champions' => 2000]);

        $match->participants()->where('player_id', $alpha->id)->update(['kills' => 20, 'deaths' => 0, 'assists' => 10, 'total_damage_dealt_to_champions' => 40000]);
        $match->participants()->where('player_id', $omega->id)->update(['kills' => 12, 'deaths' => 2, 'assists' => 8, 'total_damage_dealt_to_champions' => 30000]);

        return [$match, $alpha, $omega];
    }

    public function test_mvp_and_ace_rows_get_their_glow_on_the_match_page(): void
    {
        [$match] = $this->match();

        $html = $this->get(route('matches.show', $match->match_id))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'glow-row-mvp'));
        $this->assertSame(1, substr_count($html, 'glow-row-ace'));
    }

    public function test_game_rows_and_the_player_page_glow_for_a_badge_only(): void
    {
        [, $alpha, $omega] = $this->match();
        $plain = $this->makePlayer('Plain');
        $this->addGame($plain, ['win' => false]);   // alone in its match: no badge pattern needed, just no glow expected on a loss row

        $this->get(route('players.show', $alpha))->assertOk()->assertSee('glow-mvp', false)->assertDontSee('glow-ace', false);
        $this->get(route('players.show', $omega))->assertOk()->assertSee('glow-ace', false)->assertDontSee('glow-mvp', false);
        $this->get('/matches')->assertOk()->assertSee('glow-mvp', false)->assertSee('glow-ace', false);
    }
}
