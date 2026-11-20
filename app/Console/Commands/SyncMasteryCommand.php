<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Services\Riot\Exceptions\RiotApiException;
use App\Services\Riot\Exceptions\RiotAuthException;
use App\Services\Riot\MasteryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('riot:sync-mastery')]
#[Description('Fetch the top champion masteries of every active player')]
class SyncMasteryCommand extends Command
{
    public function handle(MasteryService $mastery): int
    {
        $stored = 0;
        $failed = 0;

        foreach (Player::active()->orderBy('id')->get() as $player) {
            try {
                $stored += $mastery->sync($player);
            } catch (RiotAuthException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            } catch (RiotApiException $e) {
                $failed++;
                $this->error("{$player->name}: gagal ({$e->getMessage()})");
            }
        }

        $this->info("Selesai: {$stored} champion mastery tersimpan, {$failed} pemain gagal.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
