<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Services\Riot\Exceptions\RiotAuthException;
use App\Services\Riot\KeyHealth;
use App\Services\Riot\RiotClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KeyHealthTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://discord.com/api/webhooks/123456789012345678/abc-DEF_ghi123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::put('ddragon.version', '16.1.1');
    }

    private function callRiot(): void
    {
        app(RiotClient::class)->region('/lol/match/v5/matches/by-puuid/p/ids');
    }

    public function test_a_rejected_key_is_remembered(): void
    {
        Http::fake(['*riotgames.com*' => Http::response('', 403)]);

        try {
            $this->callRiot();
            $this->fail('Expected a RiotAuthException');
        } catch (RiotAuthException) {
        }

        $rejection = app(KeyHealth::class)->rejection();
        $this->assertSame(403, $rejection['status']);
    }

    public function test_a_working_key_clears_the_rejection(): void
    {
        Cache::forever('riot.key-rejected', ['status' => 401, 'since' => now()->subHour()->toIso8601String()]);
        Http::fake(['*riotgames.com*' => Http::response([])]);

        $this->callRiot();

        $this->assertNull(app(KeyHealth::class)->rejection());
    }

    public function test_discord_hears_about_it_once_per_episode_and_never_sees_the_key(): void
    {
        config(['services.discord.webhook_url' => self::WEBHOOK]);
        Http::fake([
            '*riotgames.com*' => Http::response('', 401),
            self::WEBHOOK => Http::response('', 204),
        ]);

        foreach (range(1, 3) as $i) {
            try {
                $this->callRiot();
            } catch (RiotAuthException) {
            }
        }

        $discord = Http::recorded(fn (Request $request) => str_starts_with($request->url(), self::WEBHOOK));
        $this->assertCount(1, $discord);

        $body = json_encode($discord->first()[0]->data());
        $this->assertStringContainsString('API key Riot ditolak', $body);
        $this->assertStringNotContainsString(config('services.riot.key'), $body);
    }

    public function test_a_new_episode_after_recovery_is_announced_again(): void
    {
        config(['services.discord.webhook_url' => self::WEBHOOK]);
        $key = 401;
        Http::fake([
            '*riotgames.com*' => function () use (&$key) {
                return Http::response($key === 200 ? [] : '', $key);
            },
            self::WEBHOOK => Http::response('', 204),
        ]);

        try {
            $this->callRiot();
        } catch (RiotAuthException) {
        }

        $key = 200;
        $this->callRiot();
        $this->assertNull(app(KeyHealth::class)->rejection());

        $key = 403;
        try {
            $this->callRiot();
        } catch (RiotAuthException) {
        }

        $this->assertCount(2, Http::recorded(fn (Request $request) => str_starts_with($request->url(), self::WEBHOOK)));
    }

    public function test_a_discord_outage_does_not_hide_the_auth_error(): void
    {
        config(['services.discord.webhook_url' => self::WEBHOOK]);
        Http::fake([
            '*riotgames.com*' => Http::response('', 403),
            self::WEBHOOK => Http::response('', 500),
        ]);

        $this->expectException(RiotAuthException::class);

        $this->callRiot();
    }

    public function test_the_admin_dashboard_warns_while_the_key_is_rejected(): void
    {
        $this->actingAs(Admin::create(['username' => 'boss', 'password' => 'throwaway-pass-123']), 'admin');

        $this->get('/admin')->assertOk()->assertDontSee('API key Riot ditolak');

        Cache::forever('riot.key-rejected', ['status' => 403, 'since' => now()->subHours(2)->toIso8601String()]);

        $this->get('/admin')->assertSee('API key Riot ditolak (HTTP 403)')->assertSee('RIOT_API_KEY');
    }
}
