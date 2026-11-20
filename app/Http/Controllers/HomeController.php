<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        return view('home', [
            'playerCount' => Player::active()->count(),
            'matchCount' => GameMatch::count(),
        ]);
    }
}
