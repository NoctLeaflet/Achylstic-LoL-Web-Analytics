<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Services\Riot\Exceptions\RiotApiException;
use App\Services\Riot\Exceptions\RiotAuthException;
use App\Services\Riot\LeagueService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('riot:sync-ranks')]
#[Description('Fetch the current rank of every active player and store a snapshot when it changed')]
class SyncRanksCommand extends Command
{
    public function handle(LeagueService $league): int
    {
        $players = Player::active()->orderBy('id')->get();

        if ($players->isEmpty()) {
            $this->warn('Belum ada pemain aktif. Tambahkan dengan: php artisan players:add {gameName} {tagLine}');

            return self::SUCCESS;
        }

        $snapshots = 0;
        $failed = 0;

        foreach ($players as $player) {
            try {
                $league->refreshProfile($player);
                $created = $league->syncRanks($player);
            } catch (RiotAuthException $e) {
                // The key is rejected for every player, so stop right away.
                $this->error($e->getMessage());

                return self::FAILURE;
            } catch (RiotApiException $e) {
                $failed++;
                $this->error("{$player->name}: gagal ({$e->getMessage()})");

                continue;
            }

            $snapshots += $created;
            $this->line("{$player->name}: {$created} snapshot baru");
        }

        $this->info("Selesai: {$snapshots} snapshot baru, {$failed} pemain gagal.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
