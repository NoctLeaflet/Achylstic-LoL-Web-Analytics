<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    /** A throwaway password that only exists in this test file. */
    private const PASSWORD = 'throwaway-pass-123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::put('ddragon.version', '16.1.1');
        Http::fake();
    }

    private function makeAdmin(string $username = 'boss'): Admin
    {
        return Admin::create(['username' => $username, 'password' => self::PASSWORD]);
    }

    public function test_the_password_is_stored_as_a_hash(): void
    {
        $admin = $this->makeAdmin();

        $stored = $admin->fresh()->getAttributes()['password'];

        $this->assertNotSame(self::PASSWORD, $stored);
        $this->assertTrue(Hash::check(self::PASSWORD, $stored));
        $this->assertArrayNotHasKey('password', $admin->toArray());
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/admin')->assertRedirect(route('admin.login'));
        $this->post('/admin/logout')->assertRedirect(route('admin.login'));
    }

    public function test_public_pages_show_a_login_button_to_guests_only(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(route('admin.login'), false)
            ->assertSee('Admin')
            ->assertDontSee('Keluar');

        $this->actingAs($this->makeAdmin(), 'admin')
            ->get('/')
            ->assertOk()
            ->assertSee(route('admin.dashboard'), false)
            ->assertSee('Keluar')
            ->assertDontSee(route('admin.login'), false);
    }

    public function test_the_login_page_renders(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Login Admin')
            ->assertSee('name="username"', false)
            ->assertSee('type="password"', false);
    }

    public function test_an_admin_can_log_in_and_reach_the_dashboard(): void
    {
        $this->makeAdmin();

        $this->post('/admin/login', ['username' => 'boss', 'password' => self::PASSWORD])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticated('admin');
        // Only the admin guard is used: no regular "web" user exists on this site.
        $this->assertGuest('web');

        $this->get('/admin')->assertOk()->assertSee('boss');
    }

    public function test_the_password_is_case_sensitive(): void
    {
        $this->makeAdmin();

        $this->post('/admin/login', ['username' => 'boss', 'password' => strtoupper(self::PASSWORD)])
            ->assertSessionHasErrors('username');
        $this->assertGuest('admin');

        $this->post('/admin/login', ['username' => 'boss', 'password' => self::PASSWORD])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated('admin');
    }

    public function test_a_wrong_password_and_an_unknown_username_get_the_same_message(): void
    {
        $this->makeAdmin();

        $wrongPassword = $this->post('/admin/login', ['username' => 'boss', 'password' => 'not-the-password']);
        $unknownUser = $this->post('/admin/login', ['username' => 'nobody', 'password' => self::PASSWORD]);

        $wrongPassword->assertSessionHasErrors(['username' => 'Username atau password salah.']);
        $unknownUser->assertSessionHasErrors(['username' => 'Username atau password salah.']);
        $this->assertGuest('admin');
    }

    public function test_the_password_is_never_flashed_back_into_the_form(): void
    {
        $this->makeAdmin();

        $this->post('/admin/login', ['username' => 'boss', 'password' => 'not-the-password'])
            ->assertSessionHasInput('username', 'boss')
            ->assertSessionMissing('_old_input.password');
    }

    public function test_both_fields_are_required(): void
    {
        $this->post('/admin/login', [])->assertSessionHasErrors(['username', 'password']);
    }

    public function test_repeated_failures_lock_the_login_even_for_the_right_password(): void
    {
        $this->makeAdmin();

        foreach (range(1, 5) as $attempt) {
            $this->post('/admin/login', ['username' => 'boss', 'password' => 'wrong-'.$attempt])
                ->assertSessionHasErrors(['username' => 'Username atau password salah.']);
        }

        $this->post('/admin/login', ['username' => 'boss', 'password' => self::PASSWORD])
            ->assertSessionHasErrors('username');

        $this->assertGuest('admin');
        $this->assertStringContainsString(
            'Terlalu banyak percobaan login',
            session('errors')->first('username'),
        );
    }

    public function test_the_lock_is_lifted_after_the_waiting_time(): void
    {
        $this->makeAdmin();

        foreach (range(1, 5) as $attempt) {
            $this->post('/admin/login', ['username' => 'boss', 'password' => 'wrong-'.$attempt]);
        }

        $this->travel(61)->seconds();

        $this->post('/admin/login', ['username' => 'boss', 'password' => self::PASSWORD])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated('admin');
    }

    public function test_guessing_many_usernames_from_one_address_gets_locked_too(): void
    {
        $this->makeAdmin();

        foreach (range(1, 20) as $attempt) {
            $this->post('/admin/login', ['username' => 'guess-'.$attempt, 'password' => 'x']);
        }

        $this->post('/admin/login', ['username' => 'boss', 'password' => self::PASSWORD])
            ->assertSessionHasErrors('username');
        $this->assertGuest('admin');
    }

    public function test_logging_in_clears_the_failure_counter(): void
    {
        $this->makeAdmin();

        foreach (range(1, 4) as $attempt) {
            $this->post('/admin/login', ['username' => 'boss', 'password' => 'wrong-'.$attempt]);
        }

        $this->post('/admin/login', ['username' => 'boss', 'password' => self::PASSWORD])
            ->assertRedirect(route('admin.dashboard'));
        $this->post('/admin/logout');

        // Four more failures are fine again because the counter was reset by the successful login.
        foreach (range(1, 4) as $attempt) {
            $this->post('/admin/login', ['username' => 'boss', 'password' => 'again-'.$attempt])
                ->assertSessionHasErrors(['username' => 'Username atau password salah.']);
        }
    }

    public function test_a_logged_in_admin_is_sent_away_from_the_login_page(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->get('/admin/login')
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_logging_out_ends_the_session(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->post('/admin/logout')
            ->assertRedirect(route('leaderboard'));

        $this->assertGuest('admin');
        $this->get('/admin')->assertRedirect(route('admin.login'));
    }
}
