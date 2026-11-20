<?php

namespace Tests\Feature;

use App\Enums\RankedQueue;
use App\Models\LiveGame;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class LiveGameTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    private const WEBHOOK = 'https://discord.com/api/webhooks/123456789012345678/abc-DEF_ghi123';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->withoutVite();
        Cache::put('ddragon.version', '16.1.1');
        Cache::put('ddragon.champion-names.16.1.1', [145 => "Kai'Sa", 222 => 'Jinx']);
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }

    /** @param  list<string>  $puuids  tracked players in the game */
    private function activeGame(array $puuids, int $gameId = 7001, int $queueId = 400, ?int $startedMsAgo = 60_000): array
    {
        return [
            'gameId' => $gameId,
            'gameQueueConfigId' => $queueId,
            'gameStartTime' => $startedMsAgo === null ? 0 : (now()->getTimestampMs() - $startedMsAgo),
            'participants' => array_map(fn ($puuid) => ['puuid' => $puuid, 'championId' => 222, 'teamId' => 100], $puuids),
        ];
    }

    /** @var array<string, array<string, mixed>> */
    private array $spectatorGames = [];

    /**
     * Riot answers from $this->spectatorGames (puuid => game, anything else 404). Http::fake keeps the
     * first matching stub, so it is registered once and the tests change the array afterwards.
     */
    private function fakeSpectator(array $byPuuid): void
    {
        $this->spectatorGames = $byPuuid;

        if ($this->spectatorFaked ?? false) {
            return;
        }

        $this->spectatorFaked = true;
        Http::fake([
            '*spectator/v5/active-games/by-summoner/*' => function (Request $request) {
                foreach ($this->spectatorGames as $puuid => $game) {
                    if (str_contains($request->url(), $puuid)) {
                        return Http::response($game);
                    }
                }

                return Http::response('', 404);
            },
            self::WEBHOOK => Http::response('', 204),
        ]);
    }

    private bool $spectatorFaked = false;

    private function discordMessages(): array
    {
        return Http::recorded(fn (Request $request) => str_starts_with($request->url(), self::WEBHOOK))
            ->map(fn ($pair) => $pair[0]->data())->values()->all();
    }

    public function test_it_stores_who_is_playing_and_forgets_them_when_the_game_ends(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $beta = $this->makePlayer('Beta');
        $this->fakeSpectator(['puuid-alpha' => $this->activeGame(['puuid-alpha'])]);

        $this->artisan('riot:sync-live')->expectsOutputToContain('1 pemain sedang main')->assertSuccessful();

        $live = LiveGame::sole();
        $this->assertSame($alpha->id, $live->player_id);
        $this->assertSame(7001, $live->game_id);
        $this->assertSame(400, $live->queue_id);
        $this->assertSame(222, $live->champion_id);

        $this->fakeSpectator([]);
        $this->artisan('riot:sync-live')->assertSuccessful();

        $this->assertSame(0, LiveGame::count());
    }

    public function test_friends_in_the_same_game_get_one_message_and_it_is_sent_only_once(): void
    {
        config(['services.discord.webhook_url' => self::WEBHOOK]);
        $this->makePlayer('Alpha', ['display_name' => 'Ayam']);
        $this->makePlayer('Beta');
        $this->fakeSpectator([
            'puuid-alpha' => $this->activeGame(['puuid-alpha', 'puuid-beta']),
            'puuid-beta' => $this->activeGame(['puuid-alpha', 'puuid-beta']),
        ]);

        $this->artisan('riot:sync-live')->expectsOutputToContain('1 pesan Discord')->assertSuccessful();
        $this->artisan('riot:sync-live')->expectsOutputToContain('0 pesan Discord')->assertSuccessful();

        $messages = $this->discordMessages();
        $this->assertCount(1, $messages);
        $embed = $messages[0]['embeds'][0];
        $this->assertSame('🎮 Sedang bermain · Normal Draft', $embed['title']);
        $this->assertStringContainsString('**Ayam** · Jinx', $embed['description']);
        $this->assertStringContainsString('**Beta** · Jinx', $embed['description']);
    }

    public function test_a_game_that_was_already_running_for_a_while_is_not_news(): void
    {
        config(['services.discord.webhook_url' => self::WEBHOOK]);
        $this->makePlayer('Alpha');
        $this->fakeSpectator(['puuid-alpha' => $this->activeGame(['puuid-alpha'], startedMsAgo: 25 * 60_000)]);

        $this->artisan('riot:sync-live')->assertSuccessful();

        $this->assertCount(0, $this->discordMessages());
        $this->assertNotNull(LiveGame::sole()->announced_at);
    }

    public function test_a_new_game_of_the_same_player_is_announced_again(): void
    {
        config(['services.discord.webhook_url' => self::WEBHOOK]);
        $this->makePlayer('Alpha');

        $this->fakeSpectator(['puuid-alpha' => $this->activeGame(['puuid-alpha'], gameId: 1)]);
        $this->artisan('riot:sync-live')->assertSuccessful();
        $this->fakeSpectator(['puuid-alpha' => $this->activeGame(['puuid-alpha'], gameId: 2)]);
        $this->artisan('riot:sync-live')->assertSuccessful();

        $this->assertCount(2, $this->discordMessages());
        $this->assertSame(2, LiveGame::sole()->game_id);
    }

    public function test_nothing_is_sent_without_a_webhook_and_hidden_players_are_not_polled(): void
    {
        config(['services.discord.webhook_url' => '']);
        $this->makePlayer('Alpha');
        $this->makePlayer('Ghost', ['is_active' => false]);
        $this->fakeSpectator(['puuid-alpha' => $this->activeGame(['puuid-alpha']), 'puuid-ghost' => $this->activeGame(['puuid-ghost'])]);

        $this->artisan('riot:sync-live')->assertSuccessful();

        $this->assertSame(1, LiveGame::count());
        $this->assertCount(0, $this->discordMessages());
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'puuid-ghost'));
    }

    public function test_a_rejected_key_stops_the_run(): void
    {
        $this->makePlayer('Alpha');
        Http::fake(['*' => Http::response('', 403)]);

        $this->artisan('riot:sync-live')->expectsOutputToContain('API key kedaluwarsa')->assertFailed();
    }

    public function test_the_pages_show_who_is_playing(): void
    {
        $alpha = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);
        Http::fake();
        LiveGame::create(['player_id' => $alpha->id, 'game_id' => 1, 'queue_id' => 450, 'champion_id' => 145,
            'started_at' => now()->subMinutes(12), 'last_seen_at' => now()]);
        $this->addSnapshot($alpha, RankedQueue::Solo, 'GOLD', 'IV', 10);

        $this->get('/leaderboard')->assertOk()->assertSee('Sedang main · ARAM · Kai\'Sa · 12 mnt');
        $this->get(route('players.show', $alpha))->assertOk()->assertSee('Sedang main · ARAM');
    }

    public function test_stale_rows_are_ignored_by_the_pages(): void
    {
        $alpha = $this->makePlayer('Alpha');
        Http::fake();
        LiveGame::create(['player_id' => $alpha->id, 'game_id' => 1, 'queue_id' => 450, 'champion_id' => 222,
            'started_at' => now()->subHour(), 'last_seen_at' => now()->subMinutes(LiveGame::STALE_AFTER_MINUTES + 1)]);

        $this->get('/leaderboard')->assertOk()->assertDontSee('Sedang main');
    }
}
