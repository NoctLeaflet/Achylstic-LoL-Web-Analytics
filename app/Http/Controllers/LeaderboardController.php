<?php

namespace App\Http\Controllers;

use App\Enums\MatchQueue;
use App\Enums\RankedQueue;
use App\Models\LiveGame;
use App\Models\Player;
use App\Services\Stats\ChampionStats;
use App\Services\Stats\ClimberOfTheWeek;
use App\Services\Stats\LeaderboardBuilder;
use App\Services\Stats\LeaderboardExtras;
use App\Services\Stats\LeaderboardPeriod;
use App\Services\Stats\PairStats;
use App\Services\Stats\RankGoal;
use App\Services\Stats\RoleCoverage;
use App\Services\Stats\RoleWinrates;
use App\Services\Stats\ServerActivity;
use App\Services\Stats\WeeklyHighlights;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeaderboardController extends Controller
{
    /** Pairs need this many games together to be called a "best duo". */
    private const DUO_MIN_GAMES = 3;

    public function __invoke(
        Request $request,
        LeaderboardBuilder $leaderboard,
        ClimberOfTheWeek $climbers,
        WeeklyHighlights $weekly,
        RoleCoverage $roles,
        LeaderboardExtras $extras,
        RankGoal $goals,
        ChampionStats $champions,
        PairStats $pairs,
        RoleWinrates $roleWinrates,
        ServerActivity $activity,
    ): View {
        $queue = RankedQueue::fromSlug($request->query('queue'));
        $period = LeaderboardPeriod::fromSlug($request->query('period'));
        $ranking = $climbers->ranking($queue, null, $period->days());
        $climber = $climbers->climber($queue, null, $period->days());
        // "Best this week" has its own mode filter (?best=normal); unknown or untracked slugs mean all modes.
        $bestMode = MatchQueue::fromSlug($request->query('best'));
        $bestMode = in_array($bestMode, MatchQueue::tracked(), true) ? $bestMode : null;

        $rows = $leaderboard->rows($queue);
        $playerIds = array_map(fn (array $row) => $row['player']->id, $rows);

        return view('leaderboard', [
            'queue' => $queue,
            'period' => $period,
            'periods' => LeaderboardPeriod::cases(),
            'rows' => $rows,
            'weeklyGains' => collect($ranking)->mapWithKeys(fn (array $row) => [$row['player']->id => $row['gain']])->all(),
            'climber' => $climber,
            'climberChampions' => $climber ? $extras->topChampions($climber['player'], $queue, $period) : [],
            'form' => $extras->form($playerIds, $queue),
            'trend' => $extras->trend($playerIds, $queue, $period),
            'goals' => collect($rows)->mapWithKeys(fn (array $row) => [$row['player']->id => $goals->progress($row['player'])])->all(),
            'weekly' => $weekly->build(bestMode: $bestMode),
            'bestMode' => $bestMode,
            'trackedModes' => MatchQueue::tracked(),
            'roles' => $roles->build(),
            'topChampions' => array_slice($this->sortedByGames($champions->forGroup()), 0, 5),
            'duos' => $this->bestDuos($pairs, $playerIds),
            'roleWinrates' => $roleWinrates->build(),
            'serverActivity' => $activity->build(),
            // Friends in a game right now, by player id (the table is filled by riot:sync-live).
            'liveGames' => LiveGame::current()->whereHas('player', fn ($q) => $q->where('is_active', true))->with('player')->get()->keyBy('player_id'),
        ]);
    }

    /**
     * @param  list<array{champion_id: int, champion_name: string, games: int}>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortedByGames(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => [$b['games'], $a['champion_name']] <=> [$a['games'], $b['champion_name']]);

        return $rows;
    }

    /**
     * The three pairs with the best winrate on the same team (at least DUO_MIN_GAMES games together).
     *
     * @param  list<int>  $playerIds
     * @return list<array{a: Player, b: Player, record: array<string, int|float|null>}>
     */
    private function bestDuos(PairStats $pairs, array $playerIds): array
    {
        $players = Player::active()->get()->keyBy('id');

        return collect($pairs->among($playerIds))
            ->filter(fn (array $pair) => $pair['together']['games'] >= self::DUO_MIN_GAMES)
            ->sort(fn (array $x, array $y) => [$y['together']['winrate'], $y['together']['games']] <=> [$x['together']['winrate'], $x['together']['games']])
            ->take(3)
            ->map(fn (array $pair) => ['a' => $players[$pair['a']], 'b' => $players[$pair['b']], 'record' => $pair['together']])
            ->values()
            ->all();
    }
}
