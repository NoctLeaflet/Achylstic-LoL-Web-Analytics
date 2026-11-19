<?php

namespace App\Http\Controllers;

use App\Enums\RankedQueue;
use App\Services\Stats\ClimberOfTheWeek;
use App\Services\Stats\LeaderboardBuilder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeaderboardController extends Controller
{
    public function __invoke(Request $request, LeaderboardBuilder $leaderboard, ClimberOfTheWeek $climbers): View
    {
        $queue = RankedQueue::fromSlug($request->query('queue'));
        $ranking = $climbers->ranking($queue);

        return view('leaderboard', [
            'queue' => $queue,
            'rows' => $leaderboard->rows($queue),
            'weeklyGains' => collect($ranking)->mapWithKeys(fn (array $row) => [$row['player']->id => $row['gain']])->all(),
            'climber' => $climbers->climber($queue),
            'climberDays' => ClimberOfTheWeek::DAYS,
        ]);
    }
}
