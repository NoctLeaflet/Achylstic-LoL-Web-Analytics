<?php

namespace App\Http\Controllers;

use App\Enums\MatchQueue;
use App\Enums\RankedQueue;
use App\Services\Stats\ClimberOfTheWeek;
use App\Services\Stats\LeaderboardBuilder;
use App\Services\Stats\RoleCoverage;
use App\Services\Stats\WeeklyHighlights;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeaderboardController extends Controller
{
    public function __invoke(Request $request, LeaderboardBuilder $leaderboard, ClimberOfTheWeek $climbers, WeeklyHighlights $weekly, RoleCoverage $roles): View
    {
        $queue = RankedQueue::fromSlug($request->query('queue'));
        $ranking = $climbers->ranking($queue);
        // "Best this week" has its own mode filter (?best=normal); unknown or untracked slugs mean all modes.
        $bestMode = MatchQueue::fromSlug($request->query('best'));
        $bestMode = in_array($bestMode, MatchQueue::tracked(), true) ? $bestMode : null;

        return view('leaderboard', [
            'queue' => $queue,
            'rows' => $leaderboard->rows($queue),
            'weeklyGains' => collect($ranking)->mapWithKeys(fn (array $row) => [$row['player']->id => $row['gain']])->all(),
            'climber' => $climbers->climber($queue),
            'climberDays' => ClimberOfTheWeek::DAYS,
            'weekly' => $weekly->build(bestMode: $bestMode),
            'bestMode' => $bestMode,
            'trackedModes' => MatchQueue::tracked(),
            'roles' => $roles->build(),
        ]);
    }
}
