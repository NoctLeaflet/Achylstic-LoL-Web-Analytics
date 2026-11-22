<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ChampionMastery;
use App\Services\Sync\SyncStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class ChampionMasteryTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->withoutVite();
        Cache::put('ddragon.version', '16.1.1');
        Cache::put('ddragon.champion-names.16.1.1', [222 => 'Jinx', 103 => 'Ahri']);
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }

    private function row(int $championId, int $level, int $points, ?int $lastPlayMs = 1_700_000_000_000): array
    {
        return ['puuid' => 'p', 'championId' => $championId, 'championLevel' => $level, 'championPoints' => $points, 'lastPlayTime' => $lastPlayMs];
    }

    public function test_it_stores_the_top_champions_and_asks_riot_for_the_players_server(): void
    {
        $player = $this->makePlayer('Alpha', ['platform' => 'vn2']);
        Http::fake(['*champion-mastery*' => Http::response([$this->row(222, 7, 245_300), $this->row(103, 5, 41_000, 0)])]);

        $this->artisan('riot:sync-mastery')->expectsOutputToContain('2 champion mastery tersimpan')->assertSuccessful();

        $jinx = ChampionMastery::where('champion_id', 222)->sole();
        $this->assertSame([$player->id, 7, 245_300], [$jinx->player_id, $jinx->level, $jinx->points]);
        $this->assertSame(1_700_000_000, $jinx->last_played_at->getTimestamp());
        $this->assertNull(ChampionMastery::where('champion_id', 103)->sole()->last_played_at);

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://vn2.api.riotgames.com/lol/champion-mastery/v4/champion-masteries/by-puuid/puuid-alpha/top')
            && $request['count'] === 10);
    }

    public function test_a_second_run_updates_rows_and_forgets_champions_that_left_the_top(): void
    {
        $player = $this->makePlayer('Alpha');
        ChampionMastery::create(['player_id' => $player->id, 'champion_id' => 99, 'level' => 3, 'points' => 5000]);
        Http::fake(['*champion-mastery*' => Http::sequence()
            ->push([$this->row(222, 6, 100_000)])
            ->push([$this->row(222, 7, 130_000)])]);

        $this->artisan('riot:sync-mastery')->assertSuccessful();
        $this->artisan('riot:sync-mastery')->assertSuccessful();

        $this->assertSame(1, ChampionMastery::count());
        $this->assertSame([7, 130_000], [ChampionMastery::sole()->level, ChampionMastery::sole()->points]);
    }

    public function test_hidden_players_are_skipped(): void
    {
        $this->makePlayer('Ghost', ['is_active' => false]);
        Http::fake();

        $this->artisan('riot:sync-mastery')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_rejected_key_stops_the_run(): void
    {
        $this->makePlayer('Alpha');
        Http::fake(['*' => Http::response('', 403)]);

        $this->artisan('riot:sync-mastery')->expectsOutputToContain('API key kedaluwarsa')->assertFailed();
    }

    public function test_a_mastery_failure_does_not_make_the_whole_sync_fail(): void
    {
        $this->makePlayer('Alpha');
        // Only mastery breaks; Riot knows nothing (404) about the player elsewhere, which is not an error.
        Http::fake(['*champion-mastery*' => Http::response('', 500), '*' => Http::response('', 404)]);

        $this->artisan('riot:sync-all')->assertSuccessful();

        $status = app(SyncStatus::class)->get();
        $this->assertSame('done', $status['state']);
        $this->assertStringContainsString('Champion mastery gagal', $status['message']);

        $this->withoutVite();
        $this->actingAs(Admin::create(['username' => 'boss', 'password' => 'throwaway-pass-123']), 'admin');
        $this->get('/admin')->assertSee('Selesai')->assertSee('Champion mastery gagal diambil');
    }

    public function test_the_player_page_lists_the_masteries_highest_first(): void
    {
        $player = $this->makePlayer('Alpha');
        Http::fake();
        ChampionMastery::create(['player_id' => $player->id, 'champion_id' => 103, 'level' => 5, 'points' => 41_000, 'last_played_at' => now()->subDays(3)]);
        ChampionMastery::create(['player_id' => $player->id, 'champion_id' => 222, 'level' => 7, 'points' => 245_300, 'last_played_at' => now()->subHour()]);

        $this->get(route('players.show', $player))
            ->assertOk()
            ->assertSee('Champion mastery')
            ->assertSeeInOrder(['Jinx', 'Level 7', '245.300 poin', 'Ahri', 'Level 5', '41.000 poin']);
    }

    public function test_the_mastery_pop_up_explains_when_there_is_no_data(): void
    {
        $player = $this->makePlayer('Alpha');
        Http::fake();

        $this->get(route('players.show', $player))->assertOk()->assertSee('Belum ada data mastery')->assertDontSee('Level 7');
    }

    public function test_the_player_page_has_the_menu_buttons_and_the_two_column_layout(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, ['champion_name' => 'Jinx', 'champion_id' => 222, 'team_position' => 'BOTTOM']);
        Http::fake();

        $html = $this->get(route('players.show', $player))->assertOk()->getContent();

        $this->assertStringContainsString("panel = 'mastery'", $html);
        $this->assertStringContainsString("panel = 'achievements'", $html);
        $this->assertStringContainsString('lg:grid-cols-2', $html);
        // Champion teratas comes before Lane (left, then right), and both are inside the same grid.
        $this->assertTrue(strpos($html, 'champions-heading') < strpos($html, 'roles-heading'));
    }
}
