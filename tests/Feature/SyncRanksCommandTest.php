<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\RankSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\RiotFixtures;
use Tests\TestCase;

class SyncRanksCommandTest extends TestCase
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

    private function makePlayer(string $puuid, array $overrides = []): Player
    {
        return Player::create(array_merge([
            'game_name' => strtoupper($puuid),
            'tag_line' => 'SG2',
            'puuid' => $puuid,
            'platform' => 'sg2',
        ], $overrides));
    }

    public function test_it_stores_snapshots_for_active_players_only(): void
    {
        $this->makePlayer('puuid-a');
        $this->makePlayer('puuid-b', ['display_name' => 'Bebek']);
        $this->makePlayer('puuid-off', ['is_active' => false]);

        Http::fake([
            '*/lol/summoner/v4/summoners/by-puuid/*' => Http::response(RiotFixtures::summoner()),
            '*/lol/league/v4/entries/by-puuid/puuid-a' => Http::response([RiotFixtures::leagueEntry('RANKED_SOLO_5x5'), RiotFixtures::leagueEntry('RANKED_FLEX_SR')]),
            '*/lol/league/v4/entries/by-puuid/puuid-b' => Http::response([RiotFixtures::leagueEntry('RANKED_SOLO_5x5', ['tier' => 'DIAMOND', 'rank' => 'I'])]),
        ]);

        $this->artisan('riot:sync-ranks')
            ->expectsOutputToContain('PUUID-A: 2 snapshot baru')
            ->expectsOutputToContain('Bebek: 1 snapshot baru')
            ->expectsOutputToContain('Selesai: 3 snapshot baru, 0 pemain gagal.')
            ->assertSuccessful();

        $this->assertSame(3, RankSnapshot::count());
        $this->assertSame(0, Player::where('puuid', 'puuid-off')->first()->rankSnapshots()->count());
        $this->assertSame(4567, Player::where('puuid', 'puuid-a')->first()->profile_icon_id);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'puuid-off'));
    }

    public function test_running_it_again_without_changes_adds_no_snapshots(): void
    {
        $this->makePlayer('puuid-a');

        Http::fake([
            '*/lol/summoner/v4/summoners/by-puuid/*' => Http::response(RiotFixtures::summoner()),
            '*/lol/league/v4/entries/by-puuid/*' => Http::response([RiotFixtures::leagueEntry()]),
        ]);

        $this->artisan('riot:sync-ranks')->assertSuccessful();
        $this->artisan('riot:sync-ranks')
            ->expectsOutputToContain('PUUID-A: 0 snapshot baru')
            ->assertSuccessful();

        $this->assertSame(1, RankSnapshot::count());
    }

    public function test_it_warns_when_there_are_no_active_players(): void
    {
        Http::fake();

        $this->artisan('riot:sync-ranks')
            ->expectsOutputToContain('Belum ada pemain aktif')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_aborts_with_a_clear_message_when_the_api_key_is_rejected(): void
    {
        $this->makePlayer('puuid-a');
        $this->makePlayer('puuid-b');

        Http::fake(['*' => Http::response('', 403)]);

        $this->artisan('riot:sync-ranks')
            ->expectsOutputToContain('API key kedaluwarsa/invalid')
            ->assertFailed();

        // Stopped at the first rejected request instead of hammering Riot for every player.
        Http::assertSentCount(1);
        $this->assertSame(0, RankSnapshot::count());
    }

    public function test_one_failing_player_does_not_stop_the_others(): void
    {
        $this->makePlayer('puuid-a');
        $this->makePlayer('puuid-b');

        Http::fake([
            '*/lol/summoner/v4/summoners/by-puuid/*' => Http::response(RiotFixtures::summoner()),
            '*/lol/league/v4/entries/by-puuid/puuid-a' => Http::response('', 500),
            '*/lol/league/v4/entries/by-puuid/puuid-b' => Http::response([RiotFixtures::leagueEntry()]),
        ]);

        $this->artisan('riot:sync-ranks')
            ->expectsOutputToContain('PUUID-A: gagal')
            ->expectsOutputToContain('PUUID-B: 1 snapshot baru')
            ->expectsOutputToContain('Selesai: 1 snapshot baru, 1 pemain gagal.')
            ->assertFailed();

        $this->assertSame(1, RankSnapshot::count());
        $this->assertSame('puuid-b', RankSnapshot::first()->player->puuid);
    }
}
