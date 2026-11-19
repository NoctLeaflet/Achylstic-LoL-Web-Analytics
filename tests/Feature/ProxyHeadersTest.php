<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The site is sometimes shared through a tunnel (VS Code port forwarding, ngrok) or sits behind a
 * proxy. The visitor talks https to the proxy while PHP sees plain http, so URLs must follow the
 * forwarded headers or the browser blocks the CSS and JS as mixed content.
 */
class ProxyHeadersTest extends TestCase
{
    use RefreshDatabase;

    private const TUNNEL_HOST = 'abc123-8000.asse.devtunnels.ms';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::put('ddragon.version', '16.1.1');
        Http::fake();
    }

    public function test_generated_urls_follow_the_forwarded_scheme_and_host(): void
    {
        $this->withServerVariables([
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => self::TUNNEL_HOST,
        ])->get('/')
            ->assertOk()
            ->assertSee('href="https://'.self::TUNNEL_HOST.'/compare"', false)
            ->assertSee('href="https://'.self::TUNNEL_HOST.'/admin/login"', false)
            ->assertDontSee('http://localhost', false)
            ->assertDontSee('http://'.self::TUNNEL_HOST, false);
    }

    public function test_a_proxy_that_rewrites_the_host_to_localhost_still_gives_the_public_address(): void
    {
        // Some tunnels forward "Host: localhost:8000" and put the real address in X-Forwarded-Host.
        $this->withServerVariables([
            'HTTP_HOST' => 'localhost:8000',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => self::TUNNEL_HOST,
        ])->get('/')
            ->assertOk()
            ->assertSee('href="https://'.self::TUNNEL_HOST.'/compare"', false)
            ->assertDontSee('localhost:8000', false);
    }

    public function test_a_plain_request_keeps_plain_urls(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('href="'.rtrim(config('app.url'), '/').'/compare"', false)
            ->assertDontSee(self::TUNNEL_HOST, false);
    }

    public function test_a_fake_x_forwarded_for_cannot_dodge_the_login_throttle(): void
    {
        Admin::create(['username' => 'boss', 'password' => 'throwaway-pass-123']);

        // Five wrong guesses, each pretending to come from another address.
        foreach (range(1, 5) as $n) {
            $this->withHeaders(['X-Forwarded-For' => "203.0.113.{$n}"])
                ->post('/admin/login', ['username' => 'boss', 'password' => 'wrong-'.$n]);
        }

        // The right password is still refused: the sixth try counts against the real address.
        $this->withHeaders(['X-Forwarded-For' => '203.0.113.99'])
            ->post('/admin/login', ['username' => 'boss', 'password' => 'throwaway-pass-123'])
            ->assertSessionHasErrors('username');

        $this->assertGuest('admin');
        $this->assertStringContainsString('Terlalu banyak percobaan login', session('errors')->first('username'));
    }
}
