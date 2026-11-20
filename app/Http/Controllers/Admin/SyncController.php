<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Sync\BackgroundRunner;
use App\Services\Sync\SyncStatus;
use Illuminate\Http\RedirectResponse;

class SyncController extends Controller
{
    /** Start ranks, matches and timelines in the background ("sync now"). */
    public function store(SyncStatus $status, BackgroundRunner $runner): RedirectResponse
    {
        if ($status->isRunning()) {
            return redirect()->route('admin.dashboard')->with('status', 'Sinkronisasi sedang berjalan.');
        }

        $status->running();

        if (! $runner->run('riot:sync-all')) {
            $status->failed('Proses latar belakang tidak bisa dimulai.');

            return redirect()->route('admin.dashboard')->withErrors(['sync' => 'Sinkronisasi tidak bisa dimulai. Jalankan php artisan riot:sync-all dari terminal.']);
        }

        return redirect()->route('admin.dashboard')->with('status', 'Sinkronisasi dimulai di latar belakang. Muat ulang halaman ini nanti untuk melihat hasilnya.');
    }
}
