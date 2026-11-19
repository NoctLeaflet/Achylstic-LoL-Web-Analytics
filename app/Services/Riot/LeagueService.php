<?php

namespace App\Services\Riot;

use App\Enums\RankedQueue;
use App\Events\RankChanged;
use App\Models\Player;
use App\Models\RankSnapshot;

/**
 * summoner-v4 (profile) and league-v4 (rank / LP).
 *
 * Riot only exposes the current rank, so every sync compares it with the latest
 * stored snapshot and records a new one only when something changed. Those
 * snapshots are the source of the LP-over-time charts.
 */
class LeagueService
{
    public function __construct(private readonly RiotClient $riot) {}

    /**
     * @return array{profile_icon_id: int, summoner_level: int}|null
     */
    public function fetchProfile(string $puuid, ?string $platform = null): ?array
    {
        $data = $this->riot->platform('/lol/summoner/v4/summoners/by-puuid/'.rawurlencode($puuid), platform: $platform);

        if ($data === null) {
            return null;
        }

        return [
            'profile_icon_id' => (int) ($data['profileIconId'] ?? 0),
            'summoner_level' => (int) ($data['summonerLevel'] ?? 0),
        ];
    }

    /**
     * Current entries for the queues we track. Unranked queues (and other
     * modes such as TFT) are not returned.
     *
     * @return list<array{queue_type: RankedQueue, tier: string, rank: string, league_points: int, wins: int, losses: int}>
     */
    public function fetchRankEntries(string $puuid, ?string $platform = null): array
    {
        $entries = $this->riot->platform('/lol/league/v4/entries/by-puuid/'.rawurlencode($puuid), platform: $platform) ?? [];

        $result = [];

        foreach ($entries as $entry) {
            $queue = RankedQueue::tryFrom((string) ($entry['queueType'] ?? ''));

            if ($queue === null) {
                continue;
            }

            $result[] = [
                'queue_type' => $queue,
                'tier' => (string) ($entry['tier'] ?? ''),
                'rank' => (string) ($entry['rank'] ?? ''),
                'league_points' => (int) ($entry['leaguePoints'] ?? 0),
                'wins' => (int) ($entry['wins'] ?? 0),
                'losses' => (int) ($entry['losses'] ?? 0),
            ];
        }

        return $result;
    }

    /** Update the profile icon and level when they changed. */
    public function refreshProfile(Player $player): bool
    {
        $profile = $this->fetchProfile($player->puuid, $player->platform);

        if ($profile === null) {
            return false;
        }

        $player->fill($profile);

        if (! $player->isDirty()) {
            return false;
        }

        $player->save();

        return true;
    }

    /**
     * Store a snapshot per queue when LP, wins, losses, tier or division changed.
     *
     * @return int number of snapshots created
     */
    public function syncRanks(Player $player): int
    {
        $created = 0;

        foreach ($this->fetchRankEntries($player->puuid, $player->platform) as $entry) {
            $latest = $player->rankSnapshots()
                ->where('queue_type', $entry['queue_type']->value)
                ->orderByDesc('captured_at')
                ->orderByDesc('id')
                ->first();

            if ($latest !== null && ! $this->hasChanged($latest, $entry)) {
                continue;
            }

            $snapshot = $player->rankSnapshots()->create($entry + ['captured_at' => now()]);
            $created++;

            // A promotion or demotion is news; the first snapshot of a player and LP-only changes are not.
            if ($latest !== null && RankChanged::position($latest) !== RankChanged::position($snapshot)) {
                RankChanged::dispatch($player, $entry['queue_type'], $latest, $snapshot);
            }
        }

        return $created;
    }

    /**
     * @param  array{tier: string, rank: string, league_points: int, wins: int, losses: int}  $entry
     */
    private function hasChanged(RankSnapshot $latest, array $entry): bool
    {
        return $latest->tier !== $entry['tier']
            || $latest->rank !== $entry['rank']
            || $latest->league_points !== $entry['league_points']
            || $latest->wins !== $entry['wins']
            || $latest->losses !== $entry['losses'];
    }
}
