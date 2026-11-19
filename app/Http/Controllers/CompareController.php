<?php

namespace App\Http\Controllers;

use App\Enums\RankedQueue;
use App\Models\Player;
use App\Services\Stats\ComparisonBuilder;
use App\Services\Stats\ComparisonSelection;
use App\Services\Stats\LpHistory;
use App\Services\Stats\PairStats;
use App\Services\Stats\PerformanceStats;
use App\Services\Stats\PlayerPalette;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompareController extends Controller
{
    public function __invoke(
        Request $request,
        ComparisonSelection $selection,
        PerformanceStats $stats,
        LpHistory $lpHistory,
        ComparisonBuilder $comparison,
        PlayerPalette $palette,
        PairStats $pairStats,
    ): View {
        $queue = RankedQueue::fromSlug($request->query('queue'));

        $requested = $selection->players($request->query('players'));
        $players = $requested->take(ComparisonSelection::MAX);
        $available = Player::active()->orderBy('id')->get();

        $data = [
            'queue' => $queue,
            'available' => $available,
            'colors' => $available->mapWithKeys(fn (Player $player) => [$player->id => $palette->colorFor($player->id)])->all(),
            'selectedIds' => $players->pluck('id')->all(),
            'tooMany' => $requested->count() > ComparisonSelection::MAX,
            'ready' => $players->count() >= ComparisonSelection::MIN,
            'min' => ComparisonSelection::MIN,
            'max' => ComparisonSelection::MAX,
            'players' => $players,
        ];

        if (! $data['ready']) {
            return view('compare', $data);
        }

        $statsByPlayer = $stats->forPlayers($players->pluck('id')->all(), $queue);

        return view('compare', $data + [
            'stats' => $statsByPlayer,
            'metrics' => ComparisonBuilder::METRICS,
            'lpChart' => $lpHistory->chartData($players, $queue),
            'radar' => $comparison->radar($players, $statsByPlayer),
            'bars' => $comparison->bars($players, $statsByPlayer),
            'pairs' => $pairStats->among($players->pluck('id')->all(), $queue),
            'playersById' => $players->keyBy('id'),
        ]);
    }
}
