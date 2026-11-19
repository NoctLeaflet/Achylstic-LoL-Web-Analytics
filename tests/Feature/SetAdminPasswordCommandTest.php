<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SetAdminPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_admin_and_stores_only_a_hash(): void
    {
        $this->artisan('admin:set-password', ['username' => 'boss', '--password' => 'throwaway-pass-123'])
            ->expectsOutputToContain('Admin "boss" dibuat')
            ->assertSuccessful();

        $stored = Admin::sole()->getAttributes()['password'];

        $this->assertNotSame('throwaway-pass-123', $stored);
        $this->assertTrue(Hash::check('throwaway-pass-123', $stored));
    }

    public function test_it_changes_the_password_of_an_existing_admin(): void
    {
        Admin::create(['username' => 'boss', 'password' => 'old-throwaway-1']);

        $this->artisan('admin:set-password', ['username' => 'boss', '--password' => 'new-throwaway-2'])
            ->expectsOutputToContain('Password admin "boss" diganti')
            ->assertSuccessful();

        $admin = Admin::sole();
        $this->assertTrue(Hash::check('new-throwaway-2', $admin->password));
        $this->assertFalse(Hash::check('old-throwaway-1', $admin->password));
    }

    public function test_it_asks_for_the_password_with_hidden_input_when_the_option_is_left_out(): void
    {
        $this->artisan('admin:set-password', ['username' => 'boss'])
            ->expectsQuestion('Password baru (input tersembunyi)', 'prompted-pass-123')
            ->expectsQuestion('Ulangi password', 'prompted-pass-123')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('prompted-pass-123', Admin::sole()->password));
    }

    public function test_it_refuses_when_the_two_typed_passwords_differ(): void
    {
        $this->artisan('admin:set-password', ['username' => 'boss'])
            ->expectsQuestion('Password baru (input tersembunyi)', 'prompted-pass-123')
            ->expectsQuestion('Ulangi password', 'something-else-123')
            ->expectsOutputToContain('Kedua password tidak sama')
            ->assertFailed();

        $this->assertSame(0, Admin::count());
    }

    public function test_it_refuses_a_short_password(): void
    {
        $this->artisan('admin:set-password', ['username' => 'boss', '--password' => 'short'])
            ->expectsOutputToContain('Password minimal 10 karakter')
            ->assertFailed();

        $this->assertSame(0, Admin::count());
    }

    public function test_it_refuses_an_odd_username(): void
    {
        $this->artisan('admin:set-password', ['username' => 'bo ss<>', '--password' => 'throwaway-pass-123'])
            ->expectsOutputToContain('Username hanya boleh berisi')
            ->assertFailed();

        $this->assertSame(0, Admin::count());
    }
}
