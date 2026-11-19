<?php

namespace App\Http\Controllers;

use App\Enums\RankedQueue;
use App\Models\Player;
use App\Services\Stats\LpHistory;
use App\Services\Stats\PairStats;
use App\Services\Stats\PerformanceStats;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlayerController extends Controller
{
    private const RECENT_MATCHES = 20;

    private const TOP_CHAMPIONS = 5;

    public function __invoke(Request $request, Player $player, PerformanceStats $stats, LpHistory $lpHistory, PairStats $pairStats): View
    {
        $queue = RankedQueue::fromSlug($request->query('queue'));
        $partners = $pairStats->forPlayer($player->id, $queue);

        return view('players.show', [
            'partners' => $partners,
            'partnerPlayers' => Player::query()->whereIn('id', array_column($partners, 'player_id'))->get()->keyBy('id'),
            'player' => $player,
            'queue' => $queue,
            'current' => $player->rankSnapshots()
                ->where('queue_type', $queue->value)
                ->orderByDesc('captured_at')
                ->orderByDesc('id')
                ->first(),
            'stats' => $stats->forPlayer($player->id, $queue),
            'topChampions' => $stats->topChampions($player->id, $queue, self::TOP_CHAMPIONS),
            'recentMatches' => $stats->recentMatches($player->id, $queue, self::RECENT_MATCHES),
            'lpChart' => $lpHistory->chartData(collect([$player]), $queue),
        ]);
    }
}
