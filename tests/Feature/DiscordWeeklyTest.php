<?php

namespace Tests\Feature;

use App\Enums\RankedQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class DiscordWeeklyTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    private const URL = 'https://discord.com/api/webhooks/123456789012345678/abc-DEF_ghi123';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Cache::put('ddragon.version', '16.1.1');
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }

    public function test_it_refuses_without_a_webhook(): void
    {
        Http::fake();
        config(['services.discord.webhook_url' => '']);

        $this->artisan('discord:weekly')->expectsOutputToContain('DISCORD_WEBHOOK_URL belum diisi')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_it_stays_quiet_when_nobody_played_this_week(): void
    {
        Http::fake();
        config(['services.discord.webhook_url' => self::URL]);
        $this->addGame($this->makePlayer('Alpha'), [], startedAt: now()->subDays(9));

        $this->artisan('discord:weekly')->expectsOutputToContain('Tidak ada game minggu ini')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_sends_climber_standout_games_and_awards(): void
    {
        Http::fake([self::URL => Http::response('', 204)]);
        config(['services.discord.webhook_url' => self::URL]);

        $alpha = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);
        foreach (range(1, 3) as $i) {
            $this->addGame($alpha, ['champion_name' => 'Jinx']);
        }
        $this->addSnapshot($alpha, RankedQueue::Solo, 'GOLD', 'IV', 10, capturedAt: now()->subDays(8));
        $this->addSnapshot($alpha, RankedQueue::Solo, 'GOLD', 'III', 30, capturedAt: now()->subHour());

        $this->artisan('discord:weekly')->expectsOutputToContain('Rangkuman mingguan terkirim')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $embed = $request['embeds'][0];
            $fields = collect($embed['fields'])->keyBy('name');

            $this->assertSame('📅 Rangkuman minggu ini', $embed['title']);
            $this->assertStringContainsString('3 game', $embed['description']);
            $this->assertStringContainsString('**Ayam** +120 LP di Solo/Duo', $fields['🧗 Top climber']['value']);
            $this->assertStringContainsString('**CARRY** · Ayam · Jinx (menang)', $fields['⭐ Game menonjol']['value']);
            $this->assertStringContainsString('**One-Trick Pony**: Ayam (100% Jinx)', $fields['🏆 Penghargaan']['value']);

            return true;
        });
    }

    public function test_the_climber_field_is_left_out_when_nobody_gained_lp(): void
    {
        Http::fake([self::URL => Http::response('', 204)]);
        config(['services.discord.webhook_url' => self::URL]);
        $this->addGame($this->makePlayer('Alpha'));

        $this->artisan('discord:weekly')->assertSuccessful();

        Http::assertSent(fn (Request $request) => ! collect($request['embeds'][0]['fields'])->contains('name', '🧗 Top climber'));
    }
}
