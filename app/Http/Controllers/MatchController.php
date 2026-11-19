<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Services\Stats\MatchScoreboard;
use Illuminate\View\View;

class MatchController extends Controller
{
    public function __invoke(GameMatch $gameMatch, MatchScoreboard $scoreboard): View
    {
        return view('matches.show', $scoreboard->build($gameMatch));
    }
}
