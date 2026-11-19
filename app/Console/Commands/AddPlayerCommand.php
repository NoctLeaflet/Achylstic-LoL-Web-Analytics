<?php

namespace App\Console\Commands;

use App\Services\Players\PlayerRegistrar;
use App\Services\Players\PlayerRegistrationException;
use App\Services\Riot\Exceptions\RiotApiException;
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
    public function handle(PlayerRegistrar $registrar): int
    {
        try {
            $result = $registrar->register(
                (string) $this->argument('gameName'),
                (string) $this->argument('tagLine'),
                $this->option('platform'),
                $this->option('name'),
            );
        } catch (PlayerRegistrationException|RiotApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $player = $result['player'];

        if (! $result['created']) {
            $this->warn("Pemain {$player->riot_id} sudah terdaftar (id {$player->id}).");

            if ($result['changed']) {
                $this->line('Data pemain diperbarui.');
            }

            return self::SUCCESS;
        }

        $this->info("Pemain {$player->riot_id} ditambahkan (id {$player->id}, platform {$player->platform}).");

        if ($result['linked_matches'] > 0) {
            $this->line("{$result['linked_matches']} match yang sudah tersimpan dihubungkan ke pemain ini.");
        }

        $this->line('Langkah berikutnya: php artisan riot:sync-ranks, lalu php artisan riot:sync-matches');

        return self::SUCCESS;
    }
}
