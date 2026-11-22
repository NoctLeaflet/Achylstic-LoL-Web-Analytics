<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\RankSnapshot;
use App\Services\Backup\DatabaseBackup;
use App\Services\Riot\KeyHealth;
use App\Services\Stats\RankGoal;
use App\Services\Sync\SchedulerHeartbeat;
use App\Services\Sync\SyncStatus;
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
            'sync' => app(SyncStatus::class)->get(),
            'keyRejection' => app(KeyHealth::class)->rejection(),
            'goalOptions' => RankGoal::options(),
            'scheduler' => ['alive' => app(SchedulerHeartbeat::class)->alive(), 'last' => app(SchedulerHeartbeat::class)->lastBeat()],
            'backup' => app(DatabaseBackup::class)->latest(),
            'lastRankSnapshot' => RankSnapshot::query()->max('captured_at'),
        ]);
    }
}
