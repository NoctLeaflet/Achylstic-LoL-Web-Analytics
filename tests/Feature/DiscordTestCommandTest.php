<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class DiscordTestCommandTest extends TestCase
{
    private const URL = 'https://discord.com/api/webhooks/123456789012345678/abc-DEF_ghi123';

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

    public function test_it_explains_how_to_set_up_a_webhook_when_none_is_configured(): void
    {
        Http::fake();
        config(['services.discord.webhook_url' => '']);

        $this->artisan('discord:test')
            ->expectsOutputToContain('DISCORD_WEBHOOK_URL belum diisi')
            ->expectsOutputToContain('Copy Webhook URL')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_it_rejects_a_url_that_is_not_a_discord_webhook(): void
    {
        Http::fake();
        config(['services.discord.webhook_url' => 'https://example.com/hook']);

        $this->artisan('discord:test')
            ->expectsOutputToContain('bukan URL webhook Discord yang valid')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_it_sends_a_test_message(): void
    {
        Http::fake([self::URL => Http::response('', 204)]);
        config(['services.discord.webhook_url' => self::URL]);

        $this->artisan('discord:test')
            ->expectsOutputToContain('Pesan tes terkirim')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->data()['embeds'][0]['title'] === '✅ Webhook terhubung');
    }

    public function test_it_reports_a_failure_without_printing_the_webhook_url(): void
    {
        Http::fake([self::URL => Http::response(['message' => 'Unknown Webhook'], 404)]);
        config(['services.discord.webhook_url' => self::URL]);

        $this->artisan('discord:test')
            ->expectsOutputToContain('Pesan gagal terkirim')
            ->doesntExpectOutputToContain('abc-DEF_ghi123')
            ->assertFailed();
    }
}
