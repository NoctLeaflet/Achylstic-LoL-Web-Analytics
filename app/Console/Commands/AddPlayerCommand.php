<?php

namespace App\Console\Commands;

use App\Models\MatchParticipant;
use App\Models\Player;
use App\Services\Riot\AccountService;
use App\Services\Riot\Exceptions\RiotApiException;
use App\Services\Riot\LeagueService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('players:add
    {gameName : Riot game name (the part before the #)}
    {tagLine : Riot tag line (the part after the #)}
    {--name= : Nickname shown within the group}
    {--platform= : Platform routing of the account (sg2, ph2, th2, tw2, vn2, ...); defaults to RIOT_PLATFORM}')]
#[Description('Add a player by Riot ID (resolves the PUUID and profile)')]
class AddPlayerCommand extends Command
{
    public function handle(AccountService $accounts, LeagueService $league): int
    {
        $gameName = trim((string) $this->argument('gameName'));
        $tagLine = ltrim(trim((string) $this->argument('tagLine')), '#');
        $platform = strtolower((string) ($this->option('platform') ?: config('services.riot.platform')));
        $nickname = $this->option('name') ?: null;

        if (! preg_match('/^[a-z0-9]+$/', $platform)) {
            $this->error("Platform \"{$platform}\" tidak valid. Contoh: sg2, ph2, th2, tw2, vn2.");

            return self::FAILURE;
        }

        try {
            $account = $accounts->findByRiotId($gameName, $tagLine);

            if ($account === null) {
                $this->error("Riot ID {$gameName}#{$tagLine} tidak ditemukan.");

                return self::FAILURE;
            }

            $existing = Player::where('puuid', $account['puuid'])->first();

            if ($existing !== null) {
                return $this->updateExisting($existing, $account, $nickname);
            }

            $profile = $league->fetchProfile($account['puuid'], $platform);

            if ($profile === null) {
                $this->error("{$account['game_name']}#{$account['tag_line']} tidak punya profil LoL di platform {$platform}. Cek opsi --platform.");

                return self::FAILURE;
            }
        } catch (RiotApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $player = Player::create([
            'game_name' => $account['game_name'],
            'tag_line' => $account['tag_line'],
            'puuid' => $account['puuid'],
            'platform' => $platform,
            'display_name' => $nickname,
            'profile_icon_id' => $profile['profile_icon_id'],
            'summoner_level' => $profile['summoner_level'],
            'is_active' => true,
        ]);

        // Matches stored earlier (through other tracked players) may already contain this account.
        $linked = MatchParticipant::where('puuid', $player->puuid)
            ->whereNull('player_id')
            ->update(['player_id' => $player->id]);

        $this->info("Pemain {$player->riot_id} ditambahkan (id {$player->id}, platform {$platform}).");

        if ($linked > 0) {
            $this->line("{$linked} match yang sudah tersimpan dihubungkan ke pemain ini.");
        }

        $this->line('Langkah berikutnya: php artisan riot:sync-ranks, lalu php artisan riot:sync-matches');

        return self::SUCCESS;
    }

    /**
     * @param  array{puuid: string, game_name: string, tag_line: string}  $account
     */
    private function updateExisting(Player $player, array $account, ?string $nickname): int
    {
        $player->fill([
            'game_name' => $account['game_name'],
            'tag_line' => $account['tag_line'],
            'is_active' => true,
        ]);

        if ($nickname !== null) {
            $player->display_name = $nickname;
        }

        $changed = $player->isDirty();
        $player->save();

        $this->warn("Pemain {$player->riot_id} sudah terdaftar (id {$player->id}).");

        if ($changed) {
            $this->line('Data pemain diperbarui.');
        }

        return self::SUCCESS;
    }
}
