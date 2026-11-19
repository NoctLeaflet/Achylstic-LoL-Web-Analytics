<?php

namespace Tests\Feature;

use App\Enums\MatchQueue;
use App\Enums\RankedQueue;
use App\Services\Stats\PlayerPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class ComparePageTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::put('ddragon.version', '16.1.1');
        Http::fake();
    }

    public function test_without_a_selection_it_shows_the_picker_and_no_charts(): void
    {
        $this->makePlayer('Alpha');
        $this->makePlayer('Beta');

        $this->get('/compare')
            ->assertOk()
            ->assertSee('Alpha')
            ->assertSee('Beta')
            ->assertSee('Pilih minimal 2 pemain')
            ->assertDontSee('radarChart(', false)
            ->assertDontSee('lpChart(', false);
    }

    public function test_it_explains_how_to_add_players_when_there_are_none(): void
    {
        $this->get('/compare')->assertOk()->assertSee('php artisan players:add', false);
    }

    public function test_a_single_player_is_not_enough(): void
    {
        $a = $this->makePlayer('Alpha');

        $response = $this->get("/compare?players={$a->id}")->assertOk();

        $this->assertFalse($response->viewData('ready'));
        $response->assertDontSee('radarChart(', false);
    }

    public function test_two_players_get_the_lp_chart_the_radar_and_the_bar_charts(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta', ['display_name' => 'Bebek']);

        $this->addSnapshot($a, RankedQueue::Solo, 'GOLD', 'II', 54);
        $this->addSnapshot($b, RankedQueue::Solo, 'PLATINUM', 'IV', 10);
        $this->addGame($a, ['kills' => 10, 'deaths' => 2, 'assists' => 8]);
        $this->addGame($b, ['kills' => 4, 'deaths' => 4, 'assists' => 4]);

        $response = $this->get("/compare?players={$a->id},{$b->id}&queue=solo")->assertOk();

        $this->assertTrue($response->viewData('ready'));
        $response->assertSee('x-data="lpChart(', false);
        $response->assertSee('x-data="radarChart(', false);
        $this->assertSame(6, substr_count($response->getContent(), 'x-data="barChart('));
        $response->assertSee('Bebek');

        $radar = $response->viewData('radar');
        $this->assertSame(['Alpha', 'Bebek'], array_column($radar['datasets'], 'label'));
        // Alpha is the best on KDA, so it gets the full 100.
        $this->assertSame(100.0, $radar['datasets'][0]['values'][0]);
        $this->assertLessThan(100.0, $radar['datasets'][1]['values'][0]);

        $lp = $response->viewData('lpChart');
        $this->assertCount(2, $lp['datasets']);
    }

    public function test_the_order_of_the_selection_is_kept_and_bad_ids_are_ignored(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');

        $response = $this->get("/compare?players={$b->id},abc,0,-3,999,{$a->id},{$b->id}")->assertOk();

        $this->assertSame([$b->id, $a->id], $response->viewData('selectedIds'));
        $this->assertTrue($response->viewData('ready'));
    }

    public function test_at_most_five_players_are_compared(): void
    {
        $ids = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $name) {
            $ids[] = $this->makePlayer("P{$name}")->id;
        }

        $response = $this->get('/compare?players='.implode(',', $ids))->assertOk();

        $this->assertCount(5, $response->viewData('selectedIds'));
        $this->assertTrue($response->viewData('tooMany'));
        $response->assertSee('Maksimal 5 pemain');
        $this->assertCount(5, $response->viewData('radar')['datasets']);
    }

    public function test_each_player_has_the_same_colour_in_every_chart(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');

        $response = $this->get("/compare?players={$a->id},{$b->id}&queue=solo")->assertOk();

        $expected = [PlayerPalette::COLORS[0], PlayerPalette::COLORS[1]];

        $this->assertSame($expected, array_column($response->viewData('radar')['datasets'], 'color'));
        $this->assertSame($expected, array_column($response->viewData('lpChart')['datasets'], 'color'));
        $this->assertSame($expected, $response->viewData('bars')[0]['colors']);
        $this->assertSame($expected, [$response->viewData('colors')[$a->id], $response->viewData('colors')[$b->id]]);
    }

    public function test_the_queue_parameter_is_applied_and_kept_in_the_form(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');
        $this->addGame($a, queueId: 420);
        $this->addGame($b, queueId: 440);

        $response = $this->get("/compare?players={$a->id},{$b->id}&queue=flex")->assertOk();

        $this->assertSame(MatchQueue::Flex, $response->viewData('queue'));
        $this->assertSame(0, $response->viewData('stats')[$a->id]['games']);
        $this->assertSame(1, $response->viewData('stats')[$b->id]['games']);
        $response->assertSee('name="queue" value="flex"', false);
    }

    public function test_players_without_any_data_do_not_break_the_page(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');

        $this->get("/compare?players={$a->id},{$b->id}&queue=solo")
            ->assertOk()
            ->assertSee('Belum ada snapshot LP')
            ->assertSee('x-data="radarChart(', false);
    }

    public function test_the_page_only_reads_the_database(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');

        $this->get("/compare?players={$a->id},{$b->id}")->assertOk();

        Http::assertNothingSent();
    }
}
