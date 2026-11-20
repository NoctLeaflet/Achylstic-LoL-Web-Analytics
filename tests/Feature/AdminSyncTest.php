<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Services\Sync\BackgroundRunner;
use App\Services\Sync\SyncStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeBackgroundRunner;
use Tests\Support\RiotFixtures;
use Tests\TestCase;

class AdminSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeBackgroundRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::put('ddragon.version', '16.1.1');
        $this->runner = new FakeBackgroundRunner;
        $this->app->instance(BackgroundRunner::class, $this->runner);
        $this->actingAs(Admin::create(['username' => 'boss', 'password' => 'throwaway-pass-123']), 'admin');
    }

    public function test_the_button_starts_the_sync_in_the_background(): void
    {
        Http::fake();

        $this->post('/admin/sync')->assertRedirect(route('admin.dashboard'))->assertSessionHas('status');

        $this->assertSame(['riot:sync-all'], $this->runner->started);
        $this->assertTrue(app(SyncStatus::class)->isRunning());
        Http::assertNothingSent();   // the web request itself never calls Riot
    }

    public function test_a_second_click_while_it_runs_starts_nothing(): void
    {
        app(SyncStatus::class)->running();

        $this->post('/admin/sync')->assertSessionHas('status', 'Sinkronisasi sedang berjalan.');

        $this->assertSame([], $this->runner->started);
    }

    public function test_a_stale_running_state_can_be_restarted(): void
    {
        app(SyncStatus::class)->running();
        $this->travel(SyncStatus::STALE_AFTER_MINUTES + 1)->minutes();

        $this->assertSame('failed', app(SyncStatus::class)->get()['state']);
        $this->post('/admin/sync');

        $this->assertSame(['riot:sync-all'], $this->runner->started);
    }

    public function test_a_process_that_cannot_start_is_reported(): void
    {
        $this->runner->succeeds = false;

        $this->post('/admin/sync')->assertSessionHasErrors('sync');

        $this->assertSame('failed', app(SyncStatus::class)->get()['state']);
    }

    public function test_guests_cannot_start_a_sync(): void
    {
        $this->app['auth']->guard('admin')->logout();

        $this->post('/admin/sync')->assertRedirect(route('admin.login'));

        $this->assertSame([], $this->runner->started);
    }

    public function test_the_dashboard_shows_the_state(): void
    {
        $this->get('/admin')->assertOk()->assertSee('Sinkron sekarang')->assertSee('Snapshot rank terakhir: belum ada');

        app(SyncStatus::class)->running();
        $this->get('/admin')->assertSee('Sedang berjalan');

        app(SyncStatus::class)->failed('API key salah');
        $this->get('/admin')->assertSee('Gagal')->assertSee('API key salah');

        app(SyncStatus::class)->done();
        $this->get('/admin')->assertSee('Selesai');
    }

    public function test_adding_a_friend_starts_a_sync_right_away(): void
    {
        Http::fake([
            '*/riot/account/v1/accounts/by-riot-id/*' => Http::response(['puuid' => 'puuid-1', 'gameName' => 'Hide on bush', 'tagLine' => 'KR1']),
            '*/lol/summoner/v4/summoners/by-puuid/*' => Http::response(RiotFixtures::summoner()),
        ]);

        $this->post('/admin/players', ['riot_id' => 'Hide on bush#KR1', 'platform' => 'sg2'])
            ->assertSessionHas('status');

        $this->assertStringContainsString('mulai diambil di latar belakang', session('status'));
        $this->assertSame(['riot:sync-all'], $this->runner->started);
    }

    public function test_sync_all_runs_the_three_syncs_in_order_and_records_the_result(): void
    {
        Http::fake();

        $this->artisan('riot:sync-all')->assertSuccessful();

        $this->assertSame('done', app(SyncStatus::class)->get()['state']);
    }
}
