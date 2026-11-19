<?php

namespace App\Http\Controllers;

use App\Enums\MatchQueue;
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
        // Null = every tracked game mode together. Only Solo/Duo and Flex have an LP chart.
        $queue = MatchQueue::fromSlug($request->query('queue'));
        $rankQueue = $queue?->ranked();

        $requested = $selection->players($request->query('players'));
        $players = $requested->take(ComparisonSelection::MAX);
        $available = Player::active()->orderBy('id')->get();

        $data = [
            'queue' => $queue,
            'queueLabel' => $queue?->label() ?? 'Semua mode',
            'queueParam' => $queue?->slug() ?? 'all',
            'queueOptions' => MatchQueue::tracked(),
            'rankQueue' => $rankQueue,
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
            'lpChart' => $rankQueue ? $lpHistory->chartData($players, $rankQueue) : null,
            'radar' => $comparison->radar($players, $statsByPlayer),
            'bars' => $comparison->bars($players, $statsByPlayer),
            'pairs' => $pairStats->among($players->pluck('id')->all(), $queue),
            'playersById' => $players->keyBy('id'),
        ]);
    }
}
