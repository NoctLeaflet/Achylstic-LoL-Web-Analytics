<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Player;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            // Shown players first, hidden ones at the end; oldest first within each group.
            'players' => Player::withCount('participations')->orderByDesc('is_active')->orderBy('id')->get(),
            'platforms' => config('services.riot.platforms'),
            'defaultPlatform' => config('services.riot.platform'),
        ]);
    }
}
