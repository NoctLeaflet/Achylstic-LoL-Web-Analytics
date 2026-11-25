<?php

namespace App\Http\Controllers;

use App\Enums\MatchQueue;
use App\Models\MatchParticipant;
use App\Models\Player;
use App\Services\Stats\CurrentStreaks;
use App\Services\Stats\GameRows;
use App\Services\Stats\MatchHistorySummary;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * Every stored game of the tracked players, newest first, filterable by player
 * and game mode, with the most played champions and roles of that selection.
 */
class MatchListController extends Controller
{
    public const PER_PAGE = 30;

    public function __invoke(Request $request, GameRows $gameRows, MatchHistorySummary $summary, CurrentStreaks $streaks): View
    {
        $players = Player::active()->orderBy('id')->get();
        $player = $players->firstWhere('id', (int) $request->query('player'));
        $mode = MatchQueue::fromSlug($request->query('mode'));
        $mode = in_array($mode, MatchQueue::tracked(), true) ? $mode : null;

        $rows = MatchParticipant::query()
            ->whereIn('player_id', $player ? [$player->id] : $players->modelKeys())
            ->whereHas('gameMatch', function ($query) use ($mode) {
                $query->withoutRemakes();

                if ($mode !== null) {
                    $query->where('queue_id', $mode->queueId());
                }
            })
            ->with(['gameMatch', 'player'])
            ->get()
            ->sortByDesc(fn (MatchParticipant $p) => [$p->gameMatch->game_start_at, $p->id])
            ->values();

        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => route('matches.index'), 'query' => array_filter(['player' => $player?->id, 'mode' => $mode?->slug()])],
        );

        $games = $gameRows->build($paginator->getCollection(), detailed: true);

        return view('matches.index', [
            'players' => $players,
            'player' => $player,
            'mode' => $mode,
            'modes' => MatchQueue::tracked(),
            'paginator' => $paginator,
            // Grouped by day, newest day first, each with its wins and losses.
            'days' => $games->groupBy(fn (array $g) => $g['started_at']->format('Y-m-d')),
            'summary' => $summary->build($rows),
            'streaks' => $streaks->build($players, $mode),
            'total' => $rows->count(),
        ]);
    }
}
