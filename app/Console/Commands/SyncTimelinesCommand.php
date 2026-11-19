<?php

namespace App\Console\Commands;

use App\Models\GameMatch;
use App\Services\Riot\Exceptions\RiotApiException;
use App\Services\Riot\Exceptions\RiotAuthException;
use App\Services\Riot\MatchService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('riot:sync-timelines {--limit=30 : Maximum number of matches to fetch a timeline for in this run (1-200)}')]
#[Description('Fetch item purchases and skill order for stored matches that do not have a timeline yet (newest first)')]
class SyncTimelinesCommand extends Command
{
    public function handle(MatchService $matches): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]);

        if ($limit === false) {
            $this->error('Opsi --limit harus berupa angka 1 sampai 200.');

            return self::FAILURE;
        }

        $pending = GameMatch::query()
            ->whereNull('timeline_fetched_at')
            ->where('game_duration', '>=', GameMatch::REMAKE_MAX_DURATION_SECONDS)
            ->orderByDesc('game_start_at')
            ->limit($limit)
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Semua match sudah punya timeline.');

            return self::SUCCESS;
        }

        $stored = 0;
        $unavailable = 0;
        $failed = 0;

        foreach ($pending as $match) {
            try {
                $matches->syncTimeline($match) ? $stored++ : $unavailable++;
            } catch (RiotAuthException $e) {
                // The key is rejected for every request, so stop right away.
                $this->error($e->getMessage());

                return self::FAILURE;
            } catch (RiotApiException $e) {
                $failed++;
                $this->error("{$match->match_id}: gagal ({$e->getMessage()})");
            }
        }

        $left = GameMatch::query()
            ->whereNull('timeline_fetched_at')
            ->where('game_duration', '>=', GameMatch::REMAKE_MAX_DURATION_SECONDS)
            ->count();

        $this->info("Selesai: {$stored} timeline tersimpan, {$unavailable} tidak tersedia di Riot, {$failed} gagal. Sisa antrean: {$left}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
