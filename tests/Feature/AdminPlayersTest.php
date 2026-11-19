<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\MatchParticipant;
use App\Models\Player;
use App\Services\Riot\MatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsGames;
use Tests\Support\RiotFixtures;
use Tests\TestCase;

class AdminPlayersTest extends TestCase
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

    /** @param  array<string, mixed>|null  $account  null = unknown Riot ID */
    private function fakeRiot(?array $account, ?array $summoner = null): void
    {
        Http::fake([
            '*/riot/account/v1/accounts/by-riot-id/*' => $account === null ? Http::response('', 404) : Http::response($account),
            '*/lol/summoner/v4/summoners/by-puuid/*' => $summoner === null ? Http::response('', 404) : Http::response($summoner),
        ]);
    }

    private function account(string $puuid = 'puuid-1'): array
    {
        return ['puuid' => $puuid, 'gameName' => 'Hide on bush', 'tagLine' => 'KR1'];
    }

    public function test_the_dashboard_lists_shown_players_before_hidden_ones(): void
    {
        Http::fake();
        $this->makePlayer('Hidden', ['is_active' => false, 'display_name' => 'Ghost']);
        $this->makePlayer('Visible', ['display_name' => 'Vicky']);

        $this->get('/admin')
            ->assertOk()
            ->assertSeeInOrder(['Vicky', 'Visible#SG2', 'Ghost', 'Hidden#SG2', 'Disembunyikan'])
            ->assertSee('Tambah teman')
            ->assertSee('Singapore (SG2)');
    }

    public function test_the_dashboard_counts_the_stored_matches_of_a_player(): void
    {
        Http::fake();
        $player = $this->makePlayer('Alpha');
        $this->addGame($player);
        $this->addGame($player);

        $this->get('/admin')->assertOk()->assertSee('2 match');
    }

    public function test_an_admin_can_add_a_friend_by_riot_id(): void
    {
        $this->fakeRiot($this->account(), RiotFixtures::summoner());

        $this->post('/admin/players', ['riot_id' => 'hide on bush#kr1', 'nickname' => 'Ayam', 'platform' => 'vn2'])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        $player = Player::sole();
        $this->assertSame('Hide on bush', $player->game_name);
        $this->assertSame('KR1', $player->tag_line);
        $this->assertSame('puuid-1', $player->puuid);
        $this->assertSame('vn2', $player->platform);
        $this->assertSame('Ayam', $player->display_name);
        $this->assertSame(4567, $player->profile_icon_id);
        $this->assertTrue($player->is_active);

        // The server picked in the form is where the profile is looked up.
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://vn2.api.riotgames.com/lol/summoner/v4/summoners/by-puuid/puuid-1'));
    }

    public function test_the_new_friend_shows_up_on_the_leaderboard_without_a_rank_yet(): void
    {
        $this->fakeRiot($this->account(), RiotFixtures::summoner());

        $this->post('/admin/players', ['riot_id' => 'Hide on bush#KR1', 'nickname' => 'Ayam', 'platform' => 'sg2']);

        $this->get('/')->assertOk()->assertSee('Ayam')->assertSee('Hide on bush#KR1')->assertSee('Unranked');
    }

    public function test_a_game_name_with_spaces_and_a_hash_is_split_on_the_last_hash(): void
    {
        $this->fakeRiot(['puuid' => 'puuid-2', 'gameName' => 'A#B c', 'tagLine' => 'T1X'], RiotFixtures::summoner());

        $this->post('/admin/players', ['riot_id' => 'A#B c#T1X', 'platform' => 'sg2'])->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/by-riot-id/A%23B%20c/T1X'));
        $this->assertSame('A#B c', Player::sole()->game_name);
    }

    public function test_the_nickname_is_optional(): void
    {
        $this->fakeRiot($this->account(), RiotFixtures::summoner());

        $this->post('/admin/players', ['riot_id' => 'Hide on bush#KR1', 'nickname' => '', 'platform' => 'sg2'])
            ->assertSessionHasNoErrors();

        $this->assertNull(Player::sole()->display_name);
        $this->assertSame('Hide on bush', Player::sole()->name);
    }

    public function test_matches_stored_earlier_are_linked_to_the_new_friend(): void
    {
        app(MatchService::class)->store(RiotFixtures::match('SG2_9', ['puuid-1']));
        $this->fakeRiot($this->account('puuid-1'), RiotFixtures::summoner());

        $this->post('/admin/players', ['riot_id' => 'Hide on bush#KR1', 'platform' => 'sg2']);

        $this->assertSame(Player::sole()->id, MatchParticipant::where('puuid', 'puuid-1')->sole()->player_id);
    }

    public function test_adding_a_hidden_friend_again_shows_them_again(): void
    {
        $this->makePlayer('Old', ['puuid' => 'puuid-1', 'is_active' => false]);
        $this->fakeRiot($this->account(), RiotFixtures::summoner());

        $this->post('/admin/players', ['riot_id' => 'Hide on bush#KR1', 'nickname' => 'Back', 'platform' => 'sg2'])
            ->assertSessionHas('status');

        $player = Player::sole();
        $this->assertTrue($player->is_active);
        $this->assertSame('Back', $player->display_name);
        $this->assertSame('Hide on bush', $player->game_name);
    }

    public function test_the_riot_id_must_contain_a_tag(): void
    {
        Http::fake();

        foreach (['Hide on bush', 'Hide on bush#', '#KR1', 'Hide on bush#TOOLONGTAG', 'Name#K!1'] as $riotId) {
            $this->post('/admin/players', ['riot_id' => $riotId, 'platform' => 'sg2'])
                ->assertSessionHasErrors(['riot_id' => 'Tulis Riot ID lengkap dengan tag, contoh: Yomu#1002.']);
        }

        Http::assertNothingSent();
        $this->assertSame(0, Player::count());
    }

    public function test_only_the_listed_servers_are_accepted(): void
    {
        Http::fake();

        foreach (['na1', 'evil.com/', '', 'SG2'] as $platform) {
            $this->post('/admin/players', ['riot_id' => 'Hide on bush#KR1', 'platform' => $platform])
                ->assertSessionHasErrors('platform');
        }

        Http::assertNothingSent();
        $this->assertSame(0, Player::count());
    }

    public function test_an_unknown_riot_id_is_reported_on_the_form(): void
    {
        $this->fakeRiot(null);

        $this->post('/admin/players', ['riot_id' => 'Nobody#NONE', 'platform' => 'sg2'])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHasErrors(['riot_id' => 'Riot ID Nobody#NONE tidak ditemukan.'])
            ->assertSessionHasInput('riot_id', 'Nobody#NONE');

        $this->assertSame(0, Player::count());

        // Followed to the dashboard, the message and what was typed are shown again.
        $this->followingRedirects()
            ->post('/admin/players', ['riot_id' => 'Nobody#NONE', 'platform' => 'sg2'])
            ->assertSee('Riot ID Nobody#NONE tidak ditemukan.')
            ->assertSee('value="Nobody#NONE"', false);
    }

    public function test_an_account_without_a_profile_on_that_server_is_reported(): void
    {
        $this->fakeRiot($this->account(), null);

        $this->post('/admin/players', ['riot_id' => 'Hide on bush#KR1', 'platform' => 'th2'])
            ->assertSessionHasErrors('riot_id');

        $this->assertStringContainsString('tidak punya profil LoL di platform th2', session('errors')->first('riot_id'));
        $this->assertSame(0, Player::count());
    }

    public function test_an_expired_api_key_is_explained_and_the_key_is_never_shown(): void
    {
        Http::fake(['*' => Http::response('', 403)]);

        $page = $this->followingRedirects()
            ->post('/admin/players', ['riot_id' => 'Hide on bush#KR1', 'platform' => 'sg2'])
            ->assertOk()
            ->assertSee('API key kedaluwarsa/invalid');
        $this->assertStringNotContainsString(config('services.riot.key'), $page->getContent());
        $this->assertSame(0, Player::count());
    }

    public function test_guests_cannot_manage_players(): void
    {
        Http::fake();
        $player = $this->makePlayer('Alpha', ['display_name' => 'Keep']);
        $this->app['auth']->guard('admin')->logout();

        $this->post('/admin/players', ['riot_id' => 'Hide on bush#KR1', 'platform' => 'sg2'])
            ->assertRedirect(route('admin.login'));
        $this->patch("/admin/players/{$player->id}", ['display_name' => 'Hacked'])
            ->assertRedirect(route('admin.login'));
        $this->patch("/admin/players/{$player->id}/active")
            ->assertRedirect(route('admin.login'));

        Http::assertNothingSent();
        $this->assertSame(1, Player::count());
        $this->assertSame('Keep', $player->fresh()->display_name);
        $this->assertTrue($player->fresh()->is_active);
    }

    public function test_the_nickname_can_be_changed_and_cleared(): void
    {
        Http::fake();
        $player = $this->makePlayer('Alpha', ['display_name' => 'Old']);

        $this->patch("/admin/players/{$player->id}", ['display_name' => '  New name  '])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('status');
        $this->assertSame('New name', $player->fresh()->display_name);

        $this->patch("/admin/players/{$player->id}", ['display_name' => ''])->assertSessionHasNoErrors();
        $this->assertNull($player->fresh()->display_name);
        $this->assertSame('Alpha', $player->fresh()->name);
    }

    public function test_a_too_long_nickname_is_rejected(): void
    {
        Http::fake();
        $player = $this->makePlayer('Alpha', ['display_name' => 'Old']);

        $this->patch("/admin/players/{$player->id}", ['display_name' => str_repeat('x', 31)])
            ->assertSessionHasErrors('display_name');

        $this->assertSame('Old', $player->fresh()->display_name);
    }

    public function test_hiding_a_player_removes_them_from_the_leaderboard_and_showing_brings_them_back(): void
    {
        Http::fake();
        $player = $this->makePlayer('Alpha', ['display_name' => 'Visible Vicky']);
        $this->makePlayer('Beta', ['display_name' => 'Other Olga']);

        $this->patch("/admin/players/{$player->id}/active")
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('status');
        $this->assertFalse($player->fresh()->is_active);

        $this->get('/')->assertSee('Other Olga')->assertDontSee('Visible Vicky');
        // Data stays and the admin can still see them to bring them back.
        $this->get('/admin')->assertSee('Visible Vicky')->assertSee('Tampilkan');

        $this->patch("/admin/players/{$player->id}/active");
        $this->assertTrue($player->fresh()->is_active);
        $this->get('/')->assertSee('Visible Vicky');
    }
}
