<?php

namespace Tests\Feature;

use App\Services\Riot\Exceptions\RiotApiException;
use App\Services\Riot\Exceptions\RiotAuthException;
use App\Services\Riot\Exceptions\RiotRateLimitException;
use App\Services\Riot\RiotClient;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Tests\TestCase;

class RiotClientTest extends TestCase
{
    private const KEY = 'test-riot-key';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.riot.key' => self::KEY]);
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }

    private function client(): RiotClient
    {
        return app(RiotClient::class);
    }

    public function test_it_sends_the_token_and_uses_the_configured_routing_hosts(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $client = $this->client();
        $client->account('/riot/account/v1/accounts/by-riot-id/Name/TAG');
        $client->platform('/lol/summoner/v4/summoners/by-puuid/abc');
        $client->platform('/lol/league/v4/entries/by-puuid/abc', platform: 'ph2');
        $client->region('/lol/match/v5/matches/by-puuid/abc/ids', ['queue' => 420, 'start' => 0, 'count' => 20]);

        $urls = [
            'https://asia.api.riotgames.com/riot/account/v1/accounts/by-riot-id/Name/TAG',
            'https://sg2.api.riotgames.com/lol/summoner/v4/summoners/by-puuid/abc',
            'https://ph2.api.riotgames.com/lol/league/v4/entries/by-puuid/abc',
            'https://sea.api.riotgames.com/lol/match/v5/matches/by-puuid/abc/ids?queue=420&start=0&count=20',
        ];

        foreach ($urls as $url) {
            Http::assertSent(fn (Request $request) => $request->url() === $url
                && $request->hasHeader('X-Riot-Token', self::KEY));
        }

        Http::assertSentCount(4);
    }

    public function test_it_returns_decoded_json_and_null_for_404(): void
    {
        Http::fake([
            '*/found' => Http::response(['a' => 1]),
            '*/empty' => Http::response([]),
            '*/missing' => Http::response(['status' => ['status_code' => 404]], 404),
        ]);

        $this->assertSame(['a' => 1], $this->client()->platform('/found'));
        $this->assertSame([], $this->client()->platform('/empty'));
        $this->assertNull($this->client()->platform('/missing'));
    }

    public function test_rejected_keys_throw_a_clear_auth_exception(): void
    {
        Http::fake([
            '*/unauthorized' => Http::response('', 401),
            '*/forbidden' => Http::response('', 403),
        ]);

        foreach (['/unauthorized' => 401, '/forbidden' => 403] as $path => $status) {
            try {
                $this->client()->platform($path);
                $this->fail("Expected RiotAuthException for HTTP {$status}.");
            } catch (RiotAuthException $e) {
                $this->assertStringContainsString('API key kedaluwarsa/invalid', $e->getMessage());
                $this->assertSame($status, $e->status);
                $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            }
        }

        Sleep::assertNeverSlept();
    }

    public function test_a_missing_key_fails_before_any_request(): void
    {
        Http::fake();
        config(['services.riot.key' => null]);

        $this->expectException(RiotAuthException::class);
        $this->expectExceptionMessage('RIOT_API_KEY belum diisi');

        try {
            $this->client()->platform('/anything');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_it_waits_for_retry_after_on_429_and_retries(): void
    {
        Http::fake(['*' => Http::sequence()
            ->pushStatus(429, ['Retry-After' => '3'])
            ->push(['ok' => true])]);

        $this->assertSame(['ok' => true], $this->client()->platform('/anything'));

        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn (CarbonInterval $duration) => (int) $duration->totalSeconds === 3);
    }

    public function test_it_gives_up_when_429_keeps_coming(): void
    {
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '1'])]);

        try {
            $this->client()->platform('/anything');
            $this->fail('Expected RiotRateLimitException.');
        } catch (RiotRateLimitException $e) {
            $this->assertSame(429, $e->status);
        }

        // One initial attempt + 3 retries.
        Http::assertSentCount(4);
        Sleep::assertSleptTimes(3);
    }

    public function test_it_retries_5xx_responses_lightly(): void
    {
        Http::fake(['*' => Http::sequence()
            ->pushStatus(503)
            ->push(['ok' => true])]);

        $this->assertSame(['ok' => true], $this->client()->platform('/anything'));

        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_it_throws_after_repeated_5xx_responses(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        try {
            $this->client()->platform('/anything');
            $this->fail('Expected RiotApiException.');
        } catch (RiotApiException $e) {
            $this->assertSame(500, $e->status);
        }

        // One initial attempt + 2 retries.
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    }

    public function test_connection_failures_are_retried_then_reported(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: timed out')]);

        $this->expectException(RiotApiException::class);
        $this->expectExceptionMessage('Gagal terhubung ke Riot API');

        try {
            $this->client()->platform('/anything');
        } finally {
            Sleep::assertSleptTimes(2);
        }
    }

    public function test_it_waits_when_the_per_second_budget_is_used_up(): void
    {
        $this->freezeTime();
        Sleep::fake(syncWithCarbon: true);
        config(['services.riot.rate_limits.per_second' => 2]);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $client = $this->client();
        $client->platform('/one');
        $client->platform('/two');
        Sleep::assertNeverSlept();

        $client->platform('/three');

        Http::assertSentCount(3);
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn (CarbonInterval $duration) => (int) $duration->totalSeconds === 1);
    }

    public function test_it_waits_when_the_two_minute_budget_is_used_up(): void
    {
        $this->freezeTime();
        Sleep::fake(syncWithCarbon: true);
        config(['services.riot.rate_limits.per_second' => 100, 'services.riot.rate_limits.per_two_minutes' => 3]);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $client = $this->client();
        foreach (['/one', '/two', '/three', '/four'] as $path) {
            $client->platform($path);
        }

        Http::assertSentCount(4);
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn (CarbonInterval $duration) => (int) $duration->totalSeconds === 120);
    }

    public function test_it_rejects_invalid_routing_values(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->client()->platform('/anything', platform: 'evil.example.com/');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_the_api_key_never_reaches_the_logs(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        Http::fake([
            '*/forbidden' => Http::response('', 403),
            '*/broken' => Http::response('', 500),
            '*/missing' => Http::response('', 404),
            '*/limited' => Http::response('', 429, ['Retry-After' => '1']),
        ]);

        foreach (['/forbidden', '/broken', '/missing', '/limited'] as $path) {
            try {
                $this->client()->platform($path);
            } catch (RiotApiException) {
                // Expected: we only care about what was logged.
            }
        }

        $this->assertNotEmpty($logged);

        foreach ($logged as $line) {
            $this->assertStringNotContainsString(self::KEY, $line);
        }
    }
}
