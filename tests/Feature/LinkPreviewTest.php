<?php

namespace Tests\Feature;

use App\Enums\RankedQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class LinkPreviewTest extends TestCase
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

    public function test_every_page_has_open_graph_tags_with_the_logo_image(): void
    {
        $this->get('/leaderboard')
            ->assertOk()
            ->assertSee('<meta property="og:title" content="Leaderboard · '.config('app.name').'">', false)
            ->assertSee('<meta property="og:image" content="'.asset('og-image.png').'">', false)
            ->assertSee('<meta property="og:url" content="'.url('/leaderboard').'">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
            ->assertSee('<meta name="description" content="Pantau rank', false);
    }

    public function test_the_share_image_exists_with_the_declared_size(): void
    {
        $path = public_path('og-image.png');

        $this->assertFileExists($path);
        $this->assertSame([1200, 630], array_slice(getimagesize($path), 0, 2));
    }

    public function test_the_home_page_has_its_own_description(): void
    {
        $this->get('/')->assertSee('content="'.config('app.name').' memantau akun League of Legends', false);
    }

    public function test_a_player_page_describes_the_player_and_their_rank(): void
    {
        $player = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'II', 54);

        $this->get(route('players.show', $player))
            ->assertSee('name="description" content="Ayam (Alpha#SG2): Gold II', false);
    }

    public function test_the_description_is_escaped(): void
    {
        $player = $this->makePlayer('Evil', ['display_name' => '"><script>x</script>']);

        $this->get(route('players.show', $player))->assertDontSee('<script>x</script>', false);
    }
}
