<?php

namespace Tests\Feature;

use App\Enums\RankedQueue;
use App\Models\Player;
use App\Services\Stats\ComparisonBuilder;
use App\Services\Stats\LeaderboardBuilder;
use App\Services\Stats\LpHistory;
use App\Services\Stats\PerformanceStats;
use App\Services\Stats\PlayerPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class LeaderboardAndChartDataTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    // ---- LeaderboardBuilder ---------------------------------------------

    public function test_the_leaderboard_orders_by_tier_division_then_lp_with_unranked_last(): void
    {
        $gold = $this->makePlayer('Gold');
        $goldMore = $this->makePlayer('GoldMore');
        $emerald = $this->makePlayer('Emerald');
        $master = $this->makePlayer('Master');
        $zed = $this->makePlayer('Zed');       // unranked
        $abe = $this->makePlayer('abe');       // unranked, sorts before Zed
        $this->makePlayer('Off', ['is_active' => false]);

        $solo = RankedQueue::Solo;
        $this->addSnapshot($gold, $solo, 'GOLD', 'II', 54);
        $this->addSnapshot($goldMore, $solo, 'GOLD', 'II', 80);
        $this->addSnapshot($emerald, $solo, 'EMERALD', 'IV', 0);
        $this->addSnapshot($master, $solo, 'MASTER', 'I', 120);

        $rows = app(LeaderboardBuilder::class)->rows($solo);

        $this->assertSame(['Master', 'Emerald', 'GoldMore', 'Gold', 'abe', 'Zed'], array_map(fn ($row) => $row['player']->name, $rows));
        $this->assertSame([1, 2, 3, 4, null, null], array_column($rows, 'position'));
        $this->assertNull($rows[4]['snapshot']);
        $this->assertSame(0, $rows[4]['games']);
        $this->assertNull($rows[4]['winrate']);
    }

    public function test_the_leaderboard_uses_the_latest_snapshot_and_the_selected_queue(): void
    {
        $player = $this->makePlayer('Alpha');
        $other = $this->makePlayer('Beta');

        $this->addSnapshot($player, RankedQueue::Solo, 'SILVER', 'I', 90, 10, 10, now()->subDays(2));
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', 5, 21, 9, now()->subDay());
        $this->addSnapshot($player, RankedQueue::Flex, 'BRONZE', 'II', 10, 3, 7);
        $this->addSnapshot($other, RankedQueue::Flex, 'PLATINUM', 'IV', 0);

        $solo = app(LeaderboardBuilder::class)->rows(RankedQueue::Solo);
        $this->assertSame('Alpha', $solo[0]['player']->name);
        $this->assertSame('GOLD', $solo[0]['snapshot']->tier);
        $this->assertSame(30, $solo[0]['games']);
        $this->assertSame(70.0, $solo[0]['winrate']);
        $this->assertNull($solo[1]['position']);   // Beta has no solo rank

        $flex = app(LeaderboardBuilder::class)->rows(RankedQueue::Flex);
        $this->assertSame(['Beta', 'Alpha'], array_map(fn ($row) => $row['player']->name, $flex));
        $this->assertSame(30.0, $flex[1]['winrate']);
    }

    public function test_an_empty_leaderboard_is_just_empty(): void
    {
        $this->assertSame([], app(LeaderboardBuilder::class)->rows(RankedQueue::Solo));
    }

    // ---- LpHistory --------------------------------------------------------

    public function test_lp_history_builds_one_dataset_per_player_in_chronological_order(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta', ['display_name' => 'Bebek']);

        $this->addSnapshot($a, RankedQueue::Solo, 'GOLD', 'II', 54, capturedAt: now()->subDays(2)->startOfSecond());
        $this->addSnapshot($a, RankedQueue::Solo, 'GOLD', 'I', 10, capturedAt: now()->subDay()->startOfSecond());
        $this->addSnapshot($a, RankedQueue::Flex, 'IRON', 'IV', 0);                  // other queue
        $this->addSnapshot($b, RankedQueue::Solo, 'MASTER', 'I', 100);

        $data = app(LpHistory::class)->chartData(Player::orderBy('id')->get(), RankedQueue::Solo);

        $this->assertCount(2, $data['datasets']);

        [$alpha, $beta] = $data['datasets'];
        $this->assertSame($a->id, $alpha['player_id']);
        $this->assertSame('Alpha', $alpha['label']);
        $this->assertSame(PlayerPalette::COLORS[0], $alpha['color']);
        $this->assertCount(2, $alpha['points']);
        $this->assertSame([1454, 1510], array_column($alpha['points'], 'y'));
        $this->assertSame('Gold II · 54 LP', $alpha['points'][0]['label']);
        $this->assertLessThan($alpha['points'][1]['x'], $alpha['points'][0]['x']);
        $this->assertSame(now()->subDays(2)->startOfSecond()->getTimestamp() * 1000, $alpha['points'][0]['x']);

        $this->assertSame('Bebek', $beta['label']);
        $this->assertSame(PlayerPalette::COLORS[1], $beta['color']);
        $this->assertSame([2900], array_column($beta['points'], 'y'));

        $values = array_column($data['ticks'], 'value');
        $this->assertLessThanOrEqual(1454, min($values));
        $this->assertGreaterThanOrEqual(2900, max($values));
    }

    public function test_lp_history_without_snapshots_has_empty_series_and_no_ticks(): void
    {
        $this->makePlayer('Alpha');

        $data = app(LpHistory::class)->chartData(Player::all(), RankedQueue::Solo);

        $this->assertSame([], $data['datasets'][0]['points']);
        $this->assertSame([], $data['ticks']);
    }

    // ---- PlayerPalette ----------------------------------------------------

    public function test_a_player_keeps_the_same_colour_when_more_players_are_added(): void
    {
        $first = $this->makePlayer('Alpha');
        $second = $this->makePlayer('Beta');

        $palette = new PlayerPalette;
        $firstColour = $palette->colorFor($first->id);
        $secondColour = $palette->colorFor($second->id);

        $this->makePlayer('Gamma');

        $fresh = new PlayerPalette;
        $this->assertSame($firstColour, $fresh->colorFor($first->id));
        $this->assertSame($secondColour, $fresh->colorFor($second->id));
        $this->assertNotSame($firstColour, $secondColour);
    }

    public function test_the_palette_cycles_after_its_last_colour(): void
    {
        $ids = [];
        for ($i = 0; $i <= count(PlayerPalette::COLORS); $i++) {
            $ids[] = $this->makePlayer("P{$i}")->id;
        }

        $palette = new PlayerPalette;

        $this->assertSame($palette->colorFor($ids[0]), $palette->colorFor(end($ids)));
    }

    // ---- ComparisonBuilder ------------------------------------------------

    public function test_the_radar_is_normalised_against_the_best_player_per_metric(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');

        $stats = [
            $a->id => ['kda' => 4.0, 'cs_per_min' => 6.0, 'damage_per_min' => 800.0, 'vision_per_min' => 1.0, 'gold_per_min' => 400.0, 'winrate' => 60.0],
            $b->id => ['kda' => 2.0, 'cs_per_min' => 7.5, 'damage_per_min' => 400.0, 'vision_per_min' => 0.0, 'gold_per_min' => 400.0, 'winrate' => 30.0],
        ];

        $radar = app(ComparisonBuilder::class)->radar(Player::orderBy('id')->get(), $stats);

        $this->assertSame(['KDA', 'CS/min', 'Damage/min', 'Vision/min', 'Gold/min', 'Winrate %'], $radar['labels']);
        $this->assertSame([100.0, 80.0, 100.0, 100.0, 100.0, 100.0], $radar['datasets'][0]['values']);
        $this->assertSame([50.0, 100.0, 50.0, 0.0, 100.0, 50.0], $radar['datasets'][1]['values']);
        $this->assertSame([4.0, 6.0, 800.0, 1.0, 400.0, 60.0], $radar['datasets'][0]['raw']);
        $this->assertSame('Alpha', $radar['datasets'][0]['label']);
        $this->assertNotSame($radar['datasets'][0]['color'], $radar['datasets'][1]['color']);
    }

    public function test_the_radar_handles_players_without_any_games(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');

        $stats = app(PerformanceStats::class)->forPlayers([$a->id, $b->id]);
        $radar = app(ComparisonBuilder::class)->radar(Player::orderBy('id')->get(), $stats);

        $this->assertSame([0.0, 0.0, 0.0, 0.0, 0.0, 0.0], $radar['datasets'][0]['values']);
        $this->assertSame([0.0, 0.0, 0.0, 0.0, 0.0, 0.0], $radar['datasets'][1]['values']);
    }

    public function test_bars_give_one_chart_per_metric_with_real_values_and_player_colours(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');

        $stats = [
            $a->id => ['kda' => 4.0, 'cs_per_min' => 6.0, 'damage_per_min' => 800.0, 'vision_per_min' => 1.0, 'gold_per_min' => 400.0, 'winrate' => 60.0],
            $b->id => ['kda' => 2.5, 'cs_per_min' => 7.5, 'damage_per_min' => 400.0, 'vision_per_min' => 0.5, 'gold_per_min' => 350.0, 'winrate' => 30.0],
        ];

        $bars = app(ComparisonBuilder::class)->bars(Player::orderBy('id')->get(), $stats);

        $this->assertSame(['kda', 'cs_per_min', 'damage_per_min', 'vision_per_min', 'gold_per_min', 'winrate'], array_column($bars, 'key'));
        $this->assertSame('KDA', $bars[0]['label']);
        $this->assertSame(['Alpha', 'Beta'], $bars[0]['labels']);
        $this->assertSame([4.0, 2.5], $bars[0]['values']);
        $this->assertSame([800.0, 400.0], $bars[2]['values']);
        $this->assertSame([PlayerPalette::COLORS[0], PlayerPalette::COLORS[1]], $bars[0]['colors']);
    }
}
