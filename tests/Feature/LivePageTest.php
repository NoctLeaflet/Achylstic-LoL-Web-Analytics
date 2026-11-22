<?php

namespace Tests\Feature;

use App\Models\LiveGame;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class LivePageTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->withoutVite();
        Cache::put('ddragon.version', '16.1.1');
        Cache::put('ddragon.champion-names.16.1.1', [103 => 'Ahri', 222 => 'Jinx', 238 => 'Zed']);
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }

    private function liveGame($player, int $gameId = 77, array $overrides = []): LiveGame
    {
        return LiveGame::create($overrides + [
            'player_id' => $player->id, 'game_id' => $gameId, 'queue_id' => 400, 'champion_id' => 222,
            'participants' => [
                ['puuid' => $player->puuid, 'riot_id' => 'Alpha#SG2', 'champion_id' => 222, 'team_id' => 100],
                ['puuid' => 'stranger-1', 'riot_id' => 'Stranger#001', 'champion_id' => 103, 'team_id' => 100],
                ['puuid' => 'stranger-2', 'riot_id' => 'Enemy#002', 'champion_id' => 238, 'team_id' => 200],
            ],
            'bans' => [['champion_id' => 238, 'team_id' => 100], ['champion_id' => 103, 'team_id' => 200]],
            'started_at' => now()->subMinutes(12), 'last_seen_at' => now(),
        ]);
    }

    public function test_it_shows_the_running_game_with_both_teams_friends_and_bans(): void
    {
        Http::fake();
        $alpha = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);
        $this->liveGame($alpha);

        $this->get('/live')
            ->assertOk()
            ->assertSee('1 game berjalan sekarang')
            ->assertSee('Normal Draft')
            ->assertSee('Ayam di game ini')
            ->assertSeeInOrder(['Tim Biru', 'Ayam', 'Jinx', 'Teman', 'Stranger#001', 'Ahri', 'Tim Merah', 'Enemy#002', 'Zed'])
            ->assertSee('Ban')
            ->assertSee('12:');                      // the timer starts at the elapsed time
    }

    public function test_friends_in_the_same_game_share_one_panel(): void
    {
        Http::fake();
        $alpha = $this->makePlayer('Alpha');
        $beta = $this->makePlayer('Beta');
        $this->liveGame($alpha, 77);
        $this->liveGame($beta, 77);

        $response = $this->get('/live')->assertOk();

        $this->assertCount(1, $response->viewData('games'));
        $response->assertSee('Alpha, Beta di game ini');
    }

    public function test_every_player_gets_a_status(): void
    {
        Http::fake();
        $playing = $this->makePlayer('Playing');
        $recent = $this->makePlayer('Recent');
        $idle = $this->makePlayer('Idle');
        $ghost = $this->makePlayer('Ghost', ['is_active' => false]);
        $this->liveGame($playing);
        $this->addGame($recent, ['win' => true], startedAt: now()->subMinutes(45));      // ended 15 minutes ago
        $this->addGame($idle, ['win' => false], startedAt: now()->subHours(5));
        $this->addGame($ghost, [], startedAt: now()->subMinutes(45));

        $response = $this->get('/live')->assertOk();
        $rows = collect($response->viewData('rows'))->keyBy(fn ($r) => $r['player']->name);

        $this->assertSame(['in_game', 'recent', 'idle'], [$rows['Playing']['status'], $rows['Recent']['status'], $rows['Idle']['status']]);
        $this->assertArrayNotHasKey('Ghost', $rows->all());
        // In game first, then just played, then the rest.
        $this->assertSame(['Playing', 'Recent', 'Idle'], array_map(fn ($r) => $r['player']->name, $response->viewData('rows')));
        $response->assertSee('Baru selesai main')->assertSee('Tidak sedang main')->assertSee('Terakhir: Ahri · Win');
    }

    public function test_it_says_so_when_nobody_plays_and_explains_that_riot_has_no_online_status(): void
    {
        Http::fake();
        $this->makePlayer('Alpha');

        $this->get('/live')
            ->assertOk()
            ->assertSee('Tidak ada yang sedang bermain')
            ->assertSee('Riot tidak menyediakan status online')
            ->assertSee('href="'.route('live').'"', false);
    }

    public function test_a_game_without_the_player_list_says_it_is_coming(): void
    {
        Http::fake();
        $alpha = $this->makePlayer('Alpha');
        $this->liveGame($alpha, 77, ['participants' => null, 'bans' => null]);

        $this->get('/live')->assertOk()->assertSee('Daftar pemain game ini belum tersimpan');
    }

    public function test_stale_games_are_not_shown_as_running(): void
    {
        Http::fake();
        $alpha = $this->makePlayer('Alpha');
        $this->liveGame($alpha, 77, ['last_seen_at' => now()->subMinutes(LiveGame::STALE_AFTER_MINUTES + 1)]);

        $this->get('/live')->assertOk()->assertSee('Tidak ada yang sedang bermain');
    }

    public function test_the_sync_stores_the_players_and_bans_of_the_game(): void
    {
        $alpha = $this->makePlayer('Alpha');
        Http::fake(['*spectator/v5/*' => Http::response([
            'gameId' => 9, 'gameQueueConfigId' => 400, 'gameStartTime' => now()->getTimestampMs() - 60000,
            'participants' => [
                ['puuid' => 'puuid-alpha', 'riotId' => 'Alpha#SG2', 'championId' => 222, 'teamId' => 100],
                ['puuid' => 'x', 'riotId' => 'Enemy#1', 'championId' => 103, 'teamId' => 200],
            ],
            'bannedChampions' => [['championId' => 238, 'teamId' => 100, 'pickTurn' => 1], ['championId' => -1, 'teamId' => 200, 'pickTurn' => 2]],
        ])]);

        $this->artisan('riot:sync-live')->assertSuccessful();

        $game = LiveGame::sole();
        $this->assertSame([['puuid' => 'puuid-alpha', 'riot_id' => 'Alpha#SG2', 'champion_id' => 222, 'team_id' => 100], ['puuid' => 'x', 'riot_id' => 'Enemy#1', 'champion_id' => 103, 'team_id' => 200]], $game->participants);
        $this->assertSame([['champion_id' => 238, 'team_id' => 100]], $game->bans);   // "no ban" (-1) is dropped
    }
}
