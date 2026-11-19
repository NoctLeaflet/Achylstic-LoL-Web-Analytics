<?php

namespace Tests\Feature;

use App\Models\MatchParticipant;
use App\Models\Player;
use App\Services\Riot\MatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\RiotFixtures;
use Tests\TestCase;

class AddPlayerCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>|null  $account  null = Riot ID does not exist
     * @param  array<string, mixed>|null  $summoner  null = no LoL profile on that platform
     */
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

    public function test_it_adds_a_player_with_the_canonical_riot_id_and_profile(): void
    {
        $this->fakeRiot($this->account(), RiotFixtures::summoner());

        $this->artisan('players:add', ['gameName' => 'hide on bush', 'tagLine' => '#kr1', '--name' => 'Ayam'])
            ->expectsOutputToContain('Pemain Hide on bush#KR1 ditambahkan')
            ->assertSuccessful();

        $player = Player::sole();
        $this->assertSame('Hide on bush', $player->game_name);
        $this->assertSame('KR1', $player->tag_line);
        $this->assertSame('puuid-1', $player->puuid);
        $this->assertSame('sg2', $player->platform);
        $this->assertSame('Ayam', $player->display_name);
        $this->assertSame(4567, $player->profile_icon_id);
        $this->assertSame(123, $player->summoner_level);
        $this->assertTrue($player->is_active);

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://sg2.api.riotgames.com/lol/summoner/v4/summoners/by-puuid/puuid-1'));
    }

    public function test_it_uses_the_platform_option(): void
    {
        $this->fakeRiot($this->account(), RiotFixtures::summoner());

        $this->artisan('players:add', ['gameName' => 'Hide on bush', 'tagLine' => 'KR1', '--platform' => 'VN2'])
            ->assertSuccessful();

        $this->assertSame('vn2', Player::sole()->platform);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://vn2.api.riotgames.com/lol/summoner/'));
    }

    public function test_it_rejects_an_invalid_platform_without_calling_riot(): void
    {
        Http::fake();

        $this->artisan('players:add', ['gameName' => 'Hide on bush', 'tagLine' => 'KR1', '--platform' => 'evil.com/'])
            ->expectsOutputToContain('Platform "evil.com/" tidak valid')
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertSame(0, Player::count());
    }

    public function test_it_fails_for_an_unknown_riot_id(): void
    {
        $this->fakeRiot(null);

        $this->artisan('players:add', ['gameName' => 'Nobody', 'tagLine' => 'NONE'])
            ->expectsOutputToContain('Riot ID Nobody#NONE tidak ditemukan')
            ->assertFailed();

        $this->assertSame(0, Player::count());
    }

    public function test_it_fails_when_the_account_has_no_profile_on_the_platform(): void
    {
        $this->fakeRiot($this->account(), null);

        $this->artisan('players:add', ['gameName' => 'Hide on bush', 'tagLine' => 'KR1', '--platform' => 'th2'])
            ->expectsOutputToContain('tidak punya profil LoL di platform th2')
            ->assertFailed();

        $this->assertSame(0, Player::count());
    }

    public function test_adding_an_existing_player_updates_instead_of_duplicating(): void
    {
        Player::create([
            'game_name' => 'Old name',
            'tag_line' => 'OLD',
            'puuid' => 'puuid-1',
            'platform' => 'sg2',
            'display_name' => 'Ayam',
            'is_active' => false,
        ]);
        $this->fakeRiot($this->account(), RiotFixtures::summoner());

        $this->artisan('players:add', ['gameName' => 'Hide on bush', 'tagLine' => 'KR1', '--name' => 'Bebek'])
            ->expectsOutputToContain('sudah terdaftar')
            ->assertSuccessful();

        $player = Player::sole();
        $this->assertSame('Hide on bush', $player->game_name);
        $this->assertSame('KR1', $player->tag_line);
        $this->assertSame('Bebek', $player->display_name);
        $this->assertTrue($player->is_active);

        // Only the account lookup was needed.
        Http::assertSentCount(1);
    }

    public function test_it_links_matches_that_were_stored_before_the_player_existed(): void
    {
        app(MatchService::class)->store(RiotFixtures::match('SG2_9', ['puuid-1']));
        $this->assertSame(0, MatchParticipant::whereNotNull('player_id')->count());

        $this->fakeRiot($this->account('puuid-1'), RiotFixtures::summoner());

        $this->artisan('players:add', ['gameName' => 'Hide on bush', 'tagLine' => 'KR1'])
            ->expectsOutputToContain('1 match yang sudah tersimpan dihubungkan')
            ->assertSuccessful();

        $this->assertSame(Player::sole()->id, MatchParticipant::where('puuid', 'puuid-1')->sole()->player_id);
    }

    public function test_it_shows_a_clear_message_when_the_api_key_is_rejected(): void
    {
        Http::fake(['*' => Http::response('', 403)]);

        $this->artisan('players:add', ['gameName' => 'Hide on bush', 'tagLine' => 'KR1'])
            ->expectsOutputToContain('API key kedaluwarsa/invalid')
            ->assertFailed();

        $this->assertSame(0, Player::count());
    }
}
