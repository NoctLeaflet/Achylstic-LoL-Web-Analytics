<?php

namespace Tests\Feature;

use App\Enums\RankedQueue;
use App\Models\Achievement;
use App\Models\Admin;
use App\Models\GameMatch;
use App\Models\MatchParticipant;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class AdminPlayerManagementTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->withoutVite();
        Cache::put('ddragon.version', '16.1.1');
        $this->actingAs(Admin::create(['username' => 'boss', 'password' => 'throwaway-pass-123']), 'admin');
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }

    public function test_the_server_of_a_player_can_be_changed_to_a_listed_one_only(): void
    {
        Http::fake();
        $player = $this->makePlayer('Alpha');

        $this->patch("/admin/players/{$player->id}/platform", ['platform' => 'vn2'])->assertSessionHas('status');
        $this->assertSame('vn2', $player->fresh()->platform);

        $this->patch("/admin/players/{$player->id}/platform", ['platform' => 'na1'])->assertSessionHasErrors('platform');
        $this->assertSame('vn2', $player->fresh()->platform);
        Http::assertNothingSent();
    }

    public function test_the_riot_id_can_be_refreshed_after_a_rename(): void
    {
        Http::fake(['*accounts/by-puuid/*' => Http::response(['puuid' => 'puuid-alpha', 'gameName' => 'New Name', 'tagLine' => 'NEW1'])]);
        $player = $this->makePlayer('Alpha');

        $this->post("/admin/players/{$player->id}/refresh")->assertSessionHas('status');

        $this->assertSame(['New Name', 'NEW1'], [$player->fresh()->game_name, $player->fresh()->tag_line]);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'https://asia.api.riotgames.com/riot/account/v1/accounts/by-puuid/puuid-alpha'));
    }

    public function test_an_unknown_puuid_or_a_riot_error_is_reported_without_changing_anything(): void
    {
        $player = $this->makePlayer('Alpha');

        Http::fake(['*' => Http::response('', 404)]);
        $this->post("/admin/players/{$player->id}/refresh")->assertSessionHasErrors('player');

        $this->assertSame('Alpha', $player->fresh()->game_name);
    }

    public function test_deleting_a_player_removes_their_data_but_keeps_the_matches(): void
    {
        Http::fake();
        $player = $this->makePlayer('Alpha');
        $other = $this->makePlayer('Beta');
        $match = $this->addTeamGame([$player, $other], [], true);
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', 10);
        Achievement::create(['player_id' => $player->id, 'code' => 'games_100', 'earned_at' => now()]);

        $this->delete("/admin/players/{$player->id}")->assertRedirect(route('admin.dashboard'))->assertSessionHas('status');

        $this->assertNull(Player::find($player->id));
        $this->assertSame(0, $player->rankSnapshots()->count());
        $this->assertSame(0, Achievement::where('player_id', $player->id)->count());
        // The match and both participant rows stay; only the link to the deleted player is cut.
        $this->assertSame(1, GameMatch::count());
        $this->assertSame(2, MatchParticipant::count());
        $this->assertSame([null, $other->id], MatchParticipant::orderBy('id')->pluck('player_id')->all());
    }

    public function test_the_dashboard_has_the_extra_actions_and_guests_cannot_use_them(): void
    {
        Http::fake();
        $player = $this->makePlayer('Alpha');

        $this->get('/admin')->assertSee('Segarkan Riot ID dari Riot')->assertSee('Hapus permanen')->assertSee('onsubmit="return confirm(', false);

        $this->app['auth']->guard('admin')->logout();
        $this->delete("/admin/players/{$player->id}")->assertRedirect(route('admin.login'));
        $this->post("/admin/players/{$player->id}/refresh")->assertRedirect(route('admin.login'));
        $this->patch("/admin/players/{$player->id}/platform", ['platform' => 'vn2'])->assertRedirect(route('admin.login'));
        $this->assertNotNull(Player::find($player->id));
    }

    public function test_relink_updates_a_changed_puuid_and_relinks_unlinked_games(): void
    {
        $player = $this->makePlayer('Alpha');
        $match = $this->addGame($this->makePlayer('Other'));
        $match->participants()->create([
            'player_id' => null, 'puuid' => 'new-puuid', 'riot_id_name' => 'Alpha', 'champion_id' => 1, 'champion_name' => 'Annie',
            'team_id' => 200, 'win' => false, 'kills' => 1, 'deaths' => 1, 'assists' => 1, 'total_minions_killed' => 1,
            'neutral_minions_killed' => 0, 'gold_earned' => 1, 'total_damage_dealt_to_champions' => 1, 'damage_taken' => 1,
            'vision_score' => 1, 'wards_placed' => 1,
        ]);
        Http::fake(['*by-riot-id/Alpha/SG2' => Http::response(['puuid' => 'new-puuid', 'gameName' => 'Alpha', 'tagLine' => 'SG2']),
            '*by-riot-id/Other/SG2' => Http::response(['puuid' => 'puuid-other', 'gameName' => 'Other', 'tagLine' => 'SG2'])]);

        $this->artisan('players:relink')->expectsOutputToContain('Alpha#SG2: PUUID berubah')->expectsOutputToContain('Other#SG2: sudah cocok')->assertSuccessful();

        $this->assertSame('new-puuid', $player->fresh()->puuid);
        $this->assertSame($player->id, MatchParticipant::where('puuid', 'new-puuid')->sole()->player_id);
        $this->assertSame(2, Player::count());   // no duplicate player
    }

    public function test_relink_dry_run_changes_nothing(): void
    {
        $player = $this->makePlayer('Alpha');
        Http::fake(['*' => Http::response(['puuid' => 'new-puuid', 'gameName' => 'Alpha', 'tagLine' => 'SG2'])]);

        $this->artisan('players:relink', ['--dry-run' => true])->expectsOutputToContain('dry run')->assertSuccessful();

        $this->assertSame('puuid-alpha', $player->fresh()->puuid);
    }

    public function test_relink_reports_unknown_riot_ids_and_stops_on_a_rejected_key(): void
    {
        $this->makePlayer('Alpha');
        Http::fake(['*' => Http::response('', 404)]);

        $this->artisan('players:relink')->expectsOutputToContain('Riot ID tidak ditemukan')->assertFailed();
    }

    public function test_relink_stops_when_the_key_is_rejected(): void
    {
        $this->makePlayer('Alpha');
        Http::fake(['*' => Http::response('', 403)]);

        $this->artisan('players:relink')->expectsOutputToContain('API key kedaluwarsa')->assertFailed();
    }
}
