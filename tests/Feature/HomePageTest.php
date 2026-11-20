<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class HomePageTest extends TestCase
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

    public function test_the_landing_page_introduces_the_app_with_its_logo_and_links(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('images/logo.webp', false)
            ->assertSee('memantau akun League of Legends')
            ->assertSee('href="'.route('leaderboard').'"', false)
            ->assertSee('href="'.route('compare').'"', false)
            ->assertSee('rel="icon"', false);
    }

    public function test_it_shows_how_many_players_and_matches_are_tracked(): void
    {
        $shown = $this->makePlayer('Alpha');
        $this->makePlayer('Beta', ['is_active' => false]);
        $this->addGame($shown);
        $this->addGame($shown);

        $response = $this->get('/')->assertOk();

        $this->assertSame(1, $response->viewData('playerCount'));
        $this->assertSame(2, $response->viewData('matchCount'));
    }

    public function test_the_leaderboard_lives_at_its_own_address(): void
    {
        $this->assertSame('/leaderboard', route('leaderboard', absolute: false));
        $this->get('/leaderboard')->assertOk()->assertSee('Leaderboard');
    }

    public function test_the_brand_in_the_header_links_to_the_landing_page(): void
    {
        $this->get('/leaderboard')->assertSee('href="'.route('home').'"', false);
    }
}
