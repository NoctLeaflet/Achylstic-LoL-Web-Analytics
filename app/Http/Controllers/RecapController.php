<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Services\Stats\SeasonRecap;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A shareable, card-shaped summary of one player over the season or the last
 * 30 / 90 days.
 */
class RecapController extends Controller
{
    private const PERIODS = [
        'season' => 'Season ini',
        '90d' => '90 hari',
        '30d' => '30 hari',
    ];

    public function __invoke(Request $request, Player $player, SeasonRecap $recap): View
    {
        $period = array_key_exists($request->query('period'), self::PERIODS) ? $request->query('period') : 'season';

        $since = match ($period) {
            '90d' => now()->subDays(90),
            '30d' => now()->subDays(30),
            default => SeasonRecap::seasonStart(),
        };

        return view('recap', [
            'player' => $player,
            'recap' => $recap->forPlayer($player, $since),
            'period' => $period,
            'periods' => self::PERIODS,
            'since' => $since,
        ]);
    }
}
