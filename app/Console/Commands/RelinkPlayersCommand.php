<?php

namespace App\Console\Commands;

use App\Models\MatchParticipant;
use App\Models\Player;
use App\Services\Riot\AccountService;
use App\Services\Riot\Exceptions\RiotApiException;
use App\Services\Riot\Exceptions\RiotAuthException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('players:relink {--dry-run : Only show what would change}')]
#[Description('Look up every player\'s PUUID again from their Riot ID (needed after switching to another Riot API key)')]
class RelinkPlayersCommand extends Command
{
    public function handle(AccountService $accounts): int
    {
        $dry = (bool) $this->option('dry-run');
        $changed = 0;
        $failed = 0;

        foreach (Player::orderBy('id')->get() as $player) {
            try {
                $account = $accounts->findByRiotId($player->game_name, $player->tag_line);
            } catch (RiotAuthException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            } catch (RiotApiException $e) {
                $failed++;
                $this->error("{$player->riot_id}: gagal ({$e->getMessage()})");

                continue;
            }

            if ($account === null) {
                $failed++;
                $this->error("{$player->riot_id}: Riot ID tidak ditemukan.");

                continue;
            }

            if ($account['puuid'] === $player->puuid) {
                $this->line("{$player->riot_id}: sudah cocok.");

                continue;
            }

            $changed++;
            $this->warn("{$player->riot_id}: PUUID berubah".($dry ? ' (dry run, tidak disimpan)' : ', diperbarui.'));

            if ($dry) {
                continue;
            }

            $player->update(['puuid' => $account['puuid'], 'game_name' => $account['game_name'], 'tag_line' => $account['tag_line']]);

            // Games stored later (or earlier by someone else's sync) with the new PUUID belong to this player.
            MatchParticipant::where('puuid', $account['puuid'])->whereNull('player_id')->update(['player_id' => $player->id]);
        }

        $this->info("Selesai: {$changed} PUUID ".($dry ? 'akan berubah' : 'diperbarui').", {$failed} gagal.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
