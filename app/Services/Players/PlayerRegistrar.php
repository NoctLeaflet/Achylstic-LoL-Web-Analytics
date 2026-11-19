<?php

namespace App\Services\Players;

use App\Models\MatchParticipant;
use App\Models\Player;
use App\Services\Riot\AccountService;
use App\Services\Riot\Exceptions\RiotApiException;
use App\Services\Riot\LeagueService;

/**
 * Adds a player by Riot ID. Used by `players:add` and by the admin page, so both
 * behave the same.
 *
 * It resolves the PUUID (account-v1) and the profile (summoner-v4), so it calls
 * the Riot API through RiotClient (rate limited). Riot problems surface as
 * RiotApiException; problems the person can fix surface as PlayerRegistrationException.
 */
final class PlayerRegistrar
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly LeagueService $league,
    ) {}

    /**
     * "created" is false when the account was already registered: that player is then
     * re-activated and updated ("changed" says whether anything differed). "linked_matches"
     * counts stored matches that were already played with this account.
     *
     * @return array{player: Player, created: bool, changed: bool, linked_matches: int}
     *
     * @throws PlayerRegistrationException
     * @throws RiotApiException
     */
    public function register(string $gameName, string $tagLine, ?string $platform = null, ?string $nickname = null): array
    {
        $gameName = trim($gameName);
        $tagLine = ltrim(trim($tagLine), '#');
        $platform = strtolower((string) ($platform ?: config('services.riot.platform')));
        $nickname = $nickname !== null && trim($nickname) !== '' ? trim($nickname) : null;

        if (! preg_match('/^[a-z0-9]+$/', $platform)) {
            throw new PlayerRegistrationException("Platform \"{$platform}\" tidak valid. Contoh: sg2, ph2, th2, tw2, vn2.");
        }

        $account = $this->accounts->findByRiotId($gameName, $tagLine);

        if ($account === null) {
            throw new PlayerRegistrationException("Riot ID {$gameName}#{$tagLine} tidak ditemukan.");
        }

        $existing = Player::where('puuid', $account['puuid'])->first();

        if ($existing !== null) {
            return $this->updateExisting($existing, $account, $nickname);
        }

        $profile = $this->league->fetchProfile($account['puuid'], $platform);

        if ($profile === null) {
            throw new PlayerRegistrationException(
                "{$account['game_name']}#{$account['tag_line']} tidak punya profil LoL di platform {$platform}. Cek server (platform) yang dipilih."
            );
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

        return ['player' => $player, 'created' => true, 'changed' => false, 'linked_matches' => $linked];
    }

    /**
     * @param  array{puuid: string, game_name: string, tag_line: string}  $account
     * @return array{player: Player, created: bool, changed: bool, linked_matches: int}
     */
    private function updateExisting(Player $player, array $account, ?string $nickname): array
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

        return ['player' => $player, 'created' => false, 'changed' => $changed, 'linked_matches' => 0];
    }
}
