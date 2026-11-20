<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class ChampionPageTest extends TestCase
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

    private function play($player, string $champion, int $id, int $games, int $wins, array $stats = [], int $queueId = 420): void
    {
        foreach (range(1, $games) as $i) {
            $this->addGame($player, ['champion_name' => $champion, 'champion_id' => $id, 'win' => $i <= $wins] + $stats, queueId: $queueId);
        }
    }

    public function test_it_combines_every_player_per_champion_and_names_the_best_players(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $beta = $this->makePlayer('Beta');
        $this->play($alpha, 'Jinx', 222, 4, 3);
        $this->play($beta, 'Jinx', 222, 2, 0);
        $this->play($beta, 'Garen', 86, 1, 1);

        $champions = $this->get('/champions')->assertOk()->viewData('champions');

        $this->assertSame(['Jinx', 'Garen'], array_column($champions, 'champion_name'));
        $this->assertSame(6, $champions[0]['games']);
        $this->assertSame(3, $champions[0]['wins']);
        $this->assertSame(50, $champions[0]['winrate']);
        $this->assertSame(['Alpha', 'Beta'], array_map(fn ($e) => $e['player']->name, $champions[0]['players']));
        $this->assertSame(75, $champions[0]['players'][0]['winrate']);
    }

    public function test_kda_is_a_ratio_of_totals(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $this->play($alpha, 'Jinx', 222, 2, 1, ['kills' => 6, 'deaths' => 1, 'assists' => 4]);

        $this->assertEquals(10.0, $this->get('/champions')->viewData('champions')[0]['kda']);   // (12 + 8) / 2
    }

    public function test_highlights_need_enough_games(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $this->play($alpha, 'Garen', 86, 1, 1);         // 100% but a single game
        $this->play($alpha, 'Jinx', 222, 6, 3);

        $response = $this->get('/champions')->assertOk();

        $this->assertSame('Jinx', $response->viewData('mostPlayed')['champion_name']);
        $this->assertSame('Jinx', $response->viewData('bestWinrate')['champion_name']);
        $this->assertSame('Jinx', $response->viewData('bestKda')['champion_name']);
    }

    public function test_sorting_by_winrate_hides_champions_with_few_games(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $this->play($alpha, 'Garen', 86, 1, 1);
        $this->play($alpha, 'Jinx', 222, 5, 5);
        $this->play($alpha, 'Ahri', 103, 6, 3);

        $this->assertSame(['Jinx', 'Ahri'], array_column($this->get('/champions?sort=winrate')->viewData('champions'), 'champion_name'));
        $this->assertCount(3, $this->get('/champions?sort=games')->viewData('champions'));
        $this->assertCount(3, $this->get('/champions?sort=nonsense')->viewData('champions'));
    }

    public function test_the_mode_filter_remakes_and_hidden_players(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $ghost = $this->makePlayer('Ghost', ['is_active' => false]);
        $this->play($alpha, 'Jinx', 222, 2, 1, queueId: 400);
        $this->play($alpha, 'Ahri', 103, 1, 1, queueId: 420);
        $this->play($ghost, 'Zed', 238, 3, 3);
        $this->addGame($alpha, ['champion_name' => 'Lux', 'champion_id' => 99], duration: 200);   // remake

        $this->assertSame(['Jinx'], array_column($this->get('/champions?mode=normal')->viewData('champions'), 'champion_name'));
        $this->assertSame(['Jinx', 'Ahri'], array_column($this->get('/champions')->viewData('champions'), 'champion_name'));
    }

    public function test_the_page_renders_when_empty_and_is_linked_in_the_nav(): void
    {
        $this->get('/champions')
            ->assertOk()
            ->assertSee('0 champion dimainkan')
            ->assertSee('href="'.route('champions').'"', false);
    }
}
