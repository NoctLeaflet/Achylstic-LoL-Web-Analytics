<?php

namespace Tests\Feature;

use App\Services\Discord\DiscordNotifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class DiscordNotifierTest extends TestCase
{
    private const TOKEN = 'abc-DEF_ghi123';

    private const URL = 'https://discord.com/api/webhooks/123456789012345678/'.self::TOKEN;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.discord.webhook_url' => self::URL]);
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }

    private function notifier(): DiscordNotifier
    {
        return app(DiscordNotifier::class);
    }

    private function embed(string $title = 'Hello'): array
    {
        return ['title' => $title, 'description' => 'World'];
    }

    /**
     * @return list<string>
     */
    private function captureLogs(): array
    {
        $lines = [];

        Log::listen(function (MessageLogged $event) use (&$lines) {
            $lines[] = $event->message.' '.json_encode($event->context);
        });

        return $lines;
    }

    public function test_it_is_disabled_without_a_webhook_url(): void
    {
        Http::fake();
        config(['services.discord.webhook_url' => '']);

        $this->assertFalse($this->notifier()->enabled());
        $this->assertFalse($this->notifier()->send([$this->embed()]));

        Http::assertNothingSent();
    }

    public function test_it_refuses_urls_that_are_not_discord_webhooks(): void
    {
        Http::fake();

        foreach ([
            'https://evil.example.com/api/webhooks/123/token',
            'http://discord.com/api/webhooks/123/token',
            'https://discord.com.evil.example.com/api/webhooks/123/token',
            'https://discord.com/api/other/123/token',
            'not a url',
        ] as $url) {
            config(['services.discord.webhook_url' => $url]);

            $this->assertFalse($this->notifier()->enabled(), $url);
            $this->assertFalse($this->notifier()->send([$this->embed()]), $url);
        }

        Http::assertNothingSent();
    }

    public function test_it_accepts_the_usual_discord_webhook_url_shapes(): void
    {
        foreach ([
            'https://discord.com/api/webhooks/123456789012345678/abc-DEF_ghi123',
            'https://discordapp.com/api/webhooks/123456789012345678/abc-DEF_ghi123',
            'https://ptb.discord.com/api/v10/webhooks/123456789012345678/abc-DEF_ghi123',
            'https://discord.com/api/webhooks/123456789012345678/abc-DEF_ghi123?thread_id=42',
        ] as $url) {
            config(['services.discord.webhook_url' => $url]);

            $this->assertTrue($this->notifier()->enabled(), $url);
        }
    }

    public function test_it_posts_embeds_and_never_allows_mentions(): void
    {
        Http::fake(['discord.com/*' => Http::response('', 204)]);

        $this->assertTrue($this->notifier()->send([$this->embed('First'), $this->embed('Second')]));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $payload = $request->data();

            return $request->url() === self::URL
                && $request->method() === 'POST'
                && $payload['username'] === config('app.name')
                && count($payload['embeds']) === 2
                && $payload['embeds'][0]['title'] === 'First'
                && $payload['allowed_mentions'] === ['parse' => []];
        });
    }

    public function test_it_sends_at_most_ten_embeds(): void
    {
        Http::fake(['discord.com/*' => Http::response('', 204)]);

        $this->notifier()->send(array_map(fn ($i) => $this->embed("Embed {$i}"), range(1, 14)));

        Http::assertSent(fn (Request $request) => count($request->data()['embeds']) === 10);
    }

    public function test_an_empty_message_is_not_sent(): void
    {
        Http::fake();

        $this->assertFalse($this->notifier()->send([]));

        Http::assertNothingSent();
    }

    public function test_it_waits_for_retry_after_when_rate_limited_and_tries_again(): void
    {
        Http::fake(['discord.com/*' => Http::sequence()
            ->push(['message' => 'You are being rate limited.', 'retry_after' => 1.5, 'global' => false], 429)
            ->pushStatus(204)]);

        $this->assertTrue($this->notifier()->send([$this->embed()]));

        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 2);
    }

    public function test_it_retries_server_errors_a_couple_of_times_then_gives_up(): void
    {
        Http::fake(['discord.com/*' => Http::response('', 503)]);

        $this->assertFalse($this->notifier()->send([$this->embed()]));

        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    }

    public function test_a_server_error_that_clears_up_still_delivers(): void
    {
        Http::fake(['discord.com/*' => Http::sequence()->pushStatus(502)->pushStatus(204)]);

        $this->assertTrue($this->notifier()->send([$this->embed()]));

        Http::assertSentCount(2);
    }

    public function test_a_rejected_webhook_is_not_retried(): void
    {
        Http::fake(['discord.com/*' => Http::response(['message' => 'Unknown Webhook', 'code' => 10015], 404)]);

        $this->assertFalse($this->notifier()->send([$this->embed()]));

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_connection_failures_never_throw(): void
    {
        $attempts = 0;
        Http::fake(['discord.com/*' => function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 28: timed out for '.self::URL);
        }]);

        $this->assertFalse($this->notifier()->send([$this->embed()]));

        // Tried three times, waiting a little between the attempts.
        $this->assertSame(3, $attempts);
        Sleep::assertSleptTimes(2);
    }

    public function test_the_webhook_token_never_reaches_the_logs(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        // Every failure mode in turn: rejected, rate limited then fine, server errors, unreachable.
        $responses = [404, 429, 204, 500, 500, 500, 'down', 'down', 'down'];
        Http::fake(function () use (&$responses) {
            $next = array_shift($responses);

            if ($next === 'down') {
                throw new ConnectionException('cURL error for '.self::URL);
            }

            return Http::response('', $next);
        });

        foreach (range(1, 4) as $ignored) {
            $this->notifier()->send([$this->embed()]);
        }

        $this->assertSame([], $responses, 'every scripted failure mode was exercised');

        // And an invalid configuration.
        config(['services.discord.webhook_url' => 'https://evil.example.com/hook/'.self::TOKEN]);
        $this->notifier()->send([$this->embed()]);

        $this->assertNotEmpty($logged);

        foreach ($logged as $line) {
            $this->assertStringNotContainsString(self::TOKEN, $line);
            $this->assertStringNotContainsString('123456789012345678', $line);
        }
    }
}
