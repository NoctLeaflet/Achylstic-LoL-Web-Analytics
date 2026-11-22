<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Services\Riot\Exceptions\RiotApiException;
use App\Services\Riot\Exceptions\RiotAuthException;
use App\Services\Riot\MatchService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('riot:sync-matches
    {--count=20 : Number of recent match ids to request per player and game mode (1-100)}
    {--quick : First ask for the 5 newest ids only and skip a player when nothing is new (for the frequent runs)}')]
#[Description('Fetch new matches (ranked, normal, ARAM...) of every active player; details are only fetched for matches not stored yet')]
class SyncMatchesCommand extends Command
{
    public function handle(MatchService $matches): int
    {
        $count = filter_var($this->option('count'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);

        if ($count === false) {
            $this->error('Opsi --count harus berupa angka 1 sampai 100.');

            return self::FAILURE;
        }

        $players = Player::active()->orderBy('id')->get();

        if ($players->isEmpty()) {
            $this->warn('Belum ada pemain aktif. Tambahkan dengan: php artisan players:add {gameName} {tagLine}');

            return self::SUCCESS;
        }

        $stored = 0;
        $failed = 0;

        foreach ($players as $player) {
            try {
                $new = $matches->syncPlayer($player, $count, (bool) $this->option('quick'));
            } catch (RiotAuthException $e) {
                // The key is rejected for every player, so stop right away.
                $this->error($e->getMessage());

                return self::FAILURE;
            } catch (RiotApiException $e) {
                $failed++;
                $this->error("{$player->name}: gagal ({$e->getMessage()})");

                continue;
            }

            $stored += $new;
            $this->line("{$player->name}: {$new} match baru");
        }

        $this->info("Selesai: {$stored} match baru, {$failed} pemain gagal.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
