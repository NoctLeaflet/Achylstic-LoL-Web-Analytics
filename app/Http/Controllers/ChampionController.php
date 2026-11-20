<?php

namespace App\Http\Controllers;

use App\Enums\MatchQueue;
use App\Services\Stats\ChampionStats;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What the group plays: a champion table (sortable by games, winrate or KDA)
 * with the most played, best winrate and best KDA highlighted.
 */
class ChampionController extends Controller
{
    private const SORTS = ['games', 'winrate', 'kda'];

    public function __invoke(Request $request, ChampionStats $stats): View
    {
        $mode = MatchQueue::fromSlug($request->query('mode'));
        $mode = in_array($mode, MatchQueue::tracked(), true) ? $mode : null;
        $sort = in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'games';

        $champions = collect($stats->forGroup($mode));

        $enough = fn (array $c) => $c['games'] >= ChampionStats::MIN_GAMES_FOR_HIGHLIGHT;
        $best = fn (string $key) => $champions->filter($enough)
            ->sort(fn (array $a, array $b) => [$b[$key], $b['games'], $a['champion_name']] <=> [$a[$key], $a['games'], $b['champion_name']])
            ->first();

        // Winrate and KDA lists hide champions with too few games; the games list shows all.
        $table = $sort === 'games' ? $champions : $champions->filter($enough);

        return view('champions', [
            'champions' => $table
                ->sort(fn (array $a, array $b) => [$b[$sort], $b['games'], $a['champion_name']] <=> [$a[$sort], $a['games'], $b['champion_name']])
                ->values()
                ->all(),
            'mostPlayed' => $best('games'),
            'bestWinrate' => $best('winrate'),
            'bestKda' => $best('kda'),
            'minGames' => ChampionStats::MIN_GAMES_FOR_HIGHLIGHT,
            'sort' => $sort,
            'mode' => $mode,
            'modes' => MatchQueue::tracked(),
            'total' => $champions->count(),
        ]);
    }
}
