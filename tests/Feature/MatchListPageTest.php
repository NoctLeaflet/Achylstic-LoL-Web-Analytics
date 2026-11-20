<?php

namespace Tests\Feature;

use App\Http\Controllers\MatchListController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class MatchListPageTest extends TestCase
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

    public function test_it_lists_every_game_of_every_active_player_newest_first(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $beta = $this->makePlayer('Beta');
        $hidden = $this->makePlayer('Ghost', ['is_active' => false]);
        $old = $this->addGame($alpha, ['champion_name' => 'Garen'], startedAt: now()->subDays(3));
        $new = $this->addGame($beta, ['champion_name' => 'Jinx'], startedAt: now()->subHour());
        $this->addGame($hidden);
        $this->addGame($alpha, [], duration: 200);   // remake

        $response = $this->get('/matches')->assertOk();

        $response->assertSeeInOrder([route('matches.show', $new->match_id), route('matches.show', $old->match_id)], false);
        $this->assertSame(2, $response->viewData('total'));
        $response->assertDontSee('Ghost');
    }

    public function test_it_can_filter_by_player_and_mode(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $beta = $this->makePlayer('Beta');
        $this->addGame($alpha, [], queueId: 420);
        $this->addGame($alpha, [], queueId: 400);
        $this->addGame($beta, [], queueId: 400);

        $this->assertSame(1, $this->get("/matches?player={$alpha->id}&mode=solo")->viewData('total'));
        $this->assertSame(2, $this->get('/matches?mode=normal')->viewData('total'));
        $this->assertSame(2, $this->get("/matches?player={$alpha->id}")->viewData('total'));
        // Unknown values mean no filter.
        $this->assertSame(3, $this->get('/matches?player=999&mode=nonsense')->viewData('total'));
    }

    public function test_it_shows_most_played_champions_and_roles(): void
    {
        $alpha = $this->makePlayer('Alpha');
        foreach (range(1, 3) as $i) {
            $this->addGame($alpha, ['champion_name' => 'Jinx', 'champion_id' => 222, 'team_position' => 'BOTTOM']);
        }
        $this->addGame($alpha, ['champion_name' => 'Garen', 'champion_id' => 86, 'team_position' => 'TOP', 'win' => false]);

        $summary = $this->get('/matches')->assertOk()->viewData('summary');

        $this->assertSame('Jinx', $summary['champions'][0]['champion_name']);
        $this->assertSame(3, $summary['champions'][0]['games']);
        $this->assertSame(100, $summary['champions'][0]['winrate']);
        $this->assertSame(['Alpha'], $summary['champions'][0]['players']);
        $this->assertSame('Bot', $summary['roles'][0]['label']);
        $this->assertSame(75, $summary['roles'][0]['share']);
    }

    public function test_it_paginates(): void
    {
        $alpha = $this->makePlayer('Alpha');
        foreach (range(1, MatchListController::PER_PAGE + 2) as $i) {
            $this->addGame($alpha);
        }

        $this->get('/matches')->assertOk()->assertSee('Halaman 1 dari 2');
        $second = $this->get('/matches?page=2')->assertOk();
        $this->assertCount(2, $second->viewData('paginator')->items());
    }

    public function test_the_nav_links_to_the_list_and_an_empty_list_renders(): void
    {
        $this->get('/matches')
            ->assertOk()
            ->assertSee('href="'.route('matches.index').'"', false)
            ->assertSee('Belum ada match untuk pilihan ini');
    }
}
