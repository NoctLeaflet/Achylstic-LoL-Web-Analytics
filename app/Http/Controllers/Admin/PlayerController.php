<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Services\Players\PlayerRegistrar;
use App\Services\Players\PlayerRegistrationException;
use App\Services\Riot\Exceptions\RiotApiException;
use App\Services\Stats\RankGoal;
use App\Services\Sync\BackgroundRunner;
use App\Services\Sync\SyncStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlayerController extends Controller
{
    /** Add a friend by Riot ID ("Name#TAG"); resolves the PUUID and profile through Riot. */
    public function store(Request $request, PlayerRegistrar $registrar, SyncStatus $syncStatus, BackgroundRunner $runner): RedirectResponse
    {
        $data = $request->validate([
            'riot_id' => ['required', 'string', 'max:60', 'regex:/^.{1,40}#[A-Za-z0-9]{3,5}$/u'],
            'nickname' => ['nullable', 'string', 'max:30'],
            'platform' => ['required', Rule::in(array_keys(config('services.riot.platforms')))],
        ], [
            'riot_id.regex' => 'Tulis Riot ID lengkap dengan tag, contoh: Yomu#1002.',
        ]);

        // The tag is everything after the last "#"; a game name may contain spaces.
        $separator = strrpos($data['riot_id'], '#');
        $gameName = substr($data['riot_id'], 0, $separator);
        $tagLine = substr($data['riot_id'], $separator + 1);

        try {
            $result = $registrar->register($gameName, $tagLine, $data['platform'], $data['nickname'] ?? null);
        } catch (PlayerRegistrationException|RiotApiException $e) {
            // Includes the "API key kedaluwarsa/invalid" message; none of these messages contain the key.
            return redirect()->route('admin.dashboard')->withInput()->withErrors(['riot_id' => $e->getMessage()]);
        }

        $player = $result['player'];

        if ($result['created']) {
            $message = "{$player->name} ({$player->riot_id}) ditambahkan.";

            // Fetch their rank and matches right away instead of waiting for the scheduler.
            if (! $syncStatus->isRunning()) {
                $syncStatus->running();

                if ($runner->run('riot:sync-all')) {
                    $message .= ' Rank dan match mulai diambil di latar belakang.';
                } else {
                    $syncStatus->failed('Proses latar belakang tidak bisa dimulai.');
                    $message .= ' Rank dan match muncul setelah sync berikutnya.';
                }
            } else {
                $message .= ' Sinkronisasi sedang berjalan; datanya ikut terambil di sync berikutnya.';
            }
        } else {
            $message = "{$player->riot_id} sudah terdaftar, dan sekarang ditampilkan di leaderboard.";
        }

        return redirect()->route('admin.dashboard')->with('status', $message);
    }

    /** Change (or clear) the nickname shown in the group. */
    public function update(Request $request, Player $player): RedirectResponse
    {
        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:30'],
        ]);

        $name = isset($data['display_name']) ? trim($data['display_name']) : '';
        $player->update(['display_name' => $name !== '' ? $name : null]);

        return redirect()->route('admin.dashboard')->with('status', "Nama panggilan {$player->riot_id} disimpan.");
    }

    /** Set (or clear) the Solo/Duo rank the player is aiming for. */
    public function updateGoal(Request $request, Player $player): RedirectResponse
    {
        $data = $request->validate([
            'goal' => ['nullable', 'string', Rule::in(array_keys(RankGoal::options()))],
        ]);

        [$tier, $division] = ($data['goal'] ?? null) ? explode(':', $data['goal']) : [null, null];
        $player->update(['goal_tier' => $tier, 'goal_division' => $division]);

        return redirect()->route('admin.dashboard')->with('status', $tier
            ? "Target {$player->name}: ".RankGoal::options()[$data['goal']].'.'
            : "Target {$player->name} dihapus.");
    }

    /** Show or hide a player on the leaderboard (and stop syncing them). Their data is kept. */
    public function toggleActive(Player $player): RedirectResponse
    {
        $player->update(['is_active' => ! $player->is_active]);

        return redirect()->route('admin.dashboard')->with('status', $player->is_active
            ? "{$player->name} ditampilkan lagi di leaderboard."
            : "{$player->name} disembunyikan dari leaderboard (data tetap tersimpan).");
    }
}
