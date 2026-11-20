<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Services\Riot\Exceptions\RiotApiException;
use App\Services\Riot\Exceptions\RiotAuthException;
use App\Services\Riot\LiveGameTracker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('riot:sync-live')]
#[Description('Check which active players are in a game right now and announce new games in Discord')]
class SyncLiveCommand extends Command
{
    public function handle(LiveGameTracker $tracker): int
    {
        $playing = 0;
        $failed = 0;

        foreach (Player::active()->orderBy('id')->get() as $player) {
            try {
                $tracker->refresh($player) !== null && $playing++;
            } catch (RiotAuthException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            } catch (RiotApiException $e) {
                $failed++;
                $this->error("{$player->name}: gagal ({$e->getMessage()})");
            }
        }

        $announced = $tracker->announce();

        $this->info("Selesai: {$playing} pemain sedang main, {$announced} pesan Discord, {$failed} gagal.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
