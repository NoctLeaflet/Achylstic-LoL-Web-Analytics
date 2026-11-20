<?php

namespace App\Http\Controllers;

use App\Enums\MatchQueue;
use App\Models\Player;
use App\Services\Stats\PairStats;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ranks the pairs of tracked friends by how well they do on the same team, and
 * points out the most played duo and the biggest rivalry.
 */
class DuoController extends Controller
{
    /** A duo needs this many games together to be ranked (fewer say nothing). */
    public const MIN_GAMES = 3;

    public function __invoke(Request $request, PairStats $pairStats): View
    {
        $mode = MatchQueue::fromSlug($request->query('mode'));
        $mode = in_array($mode, MatchQueue::tracked(), true) ? $mode : null;

        $players = Player::active()->orderBy('id')->get()->keyBy('id');

        $pairs = collect($pairStats->among($players->modelKeys(), $mode))
            ->map(fn (array $pair) => $pair + ['first' => $players[$pair['a']], 'second' => $players[$pair['b']]]);

        $ranked = $pairs
            ->filter(fn (array $pair) => $pair['together']['games'] >= self::MIN_GAMES)
            ->sort(fn (array $x, array $y) => [$y['together']['winrate'], $y['together']['games'], $x['a'], $x['b']] <=> [$x['together']['winrate'], $x['together']['games'], $y['a'], $y['b']])
            ->values();

        $playedTogether = $pairs->filter(fn (array $pair) => $pair['together']['games'] > 0);

        return view('duos', [
            'ranked' => $ranked->all(),
            'minGames' => self::MIN_GAMES,
            'unranked' => $playedTogether->count() - $ranked->count(),
            'mostPlayed' => $playedTogether->sortByDesc(fn (array $pair) => $pair['together']['games'])->first(),
            'rivalry' => $pairs->filter(fn (array $pair) => $pair['versus']['games'] > 0)->sortByDesc(fn (array $pair) => $pair['versus']['games'])->first(),
            'mode' => $mode,
            'modes' => MatchQueue::tracked(),
        ]);
    }
}
