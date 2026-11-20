<?php

namespace App\Http\Controllers;

use App\Enums\MatchQueue;
use App\Enums\RankedQueue;
use App\Models\Player;
use App\Services\Stats\LpHistory;
use App\Services\Stats\PairStats;
use App\Services\Stats\PerformanceStats;
use App\Services\Stats\PerformanceTrend;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlayerController extends Controller
{
    private const RECENT_MATCHES = 20;

    private const TOP_CHAMPIONS = 5;

    public function __invoke(Request $request, Player $player, PerformanceStats $stats, LpHistory $lpHistory, PairStats $pairStats, PerformanceTrend $trend): View
    {
        // Null = every tracked game mode together.
        $queue = MatchQueue::fromSlug($request->query('queue'));

        // Only Solo/Duo and Flex have LP. Other views still show the Solo/Duo rank in the header.
        $rankQueue = $queue?->ranked();
        $headerQueue = $rankQueue ?? RankedQueue::Solo;

        $partners = $pairStats->forPlayer($player->id, $queue);

        return view('players.show', [
            'partners' => $partners,
            'partnerPlayers' => Player::query()->whereIn('id', array_column($partners, 'player_id'))->get()->keyBy('id'),
            'player' => $player,
            'queue' => $queue,
            'queueLabel' => $queue?->label() ?? 'Semua mode',
            'queueParam' => $queue?->slug() ?? 'all',
            'queueOptions' => MatchQueue::tracked(),
            'rankQueue' => $rankQueue,
            'headerQueue' => $headerQueue,
            'current' => $player->rankSnapshots()
                ->where('queue_type', $headerQueue->value)
                ->orderByDesc('captured_at')
                ->orderByDesc('id')
                ->first(),
            'stats' => $stats->forPlayer($player->id, $queue),
            'trend' => $trend->forPlayer($player->id, $queue),
            'topChampions' => $stats->topChampions($player->id, $queue, self::TOP_CHAMPIONS),
            'roles' => $stats->roles($player->id, $queue),
            'recentMatches' => $stats->recentMatches($player->id, $queue, self::RECENT_MATCHES),
            'lpChart' => $rankQueue ? $lpHistory->chartData(collect([$player]), $rankQueue) : null,
        ]);
    }
}
