<?php

namespace Tests\Support;

use App\Enums\RankedQueue;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\RankSnapshot;
use Illuminate\Support\Carbon;

/**
 * Helpers to put players, games and rank snapshots straight into the database.
 */
trait BuildsGames
{
    private int $gameCounter = 0;

    protected function makePlayer(string $name = 'Alpha', array $overrides = []): Player
    {
        return Player::create(array_merge([
            'game_name' => $name,
            'tag_line' => 'SG2',
            'puuid' => 'puuid-'.strtolower($name),
            'platform' => 'sg2',
            'profile_icon_id' => 4567,
            'summoner_level' => 100,
        ], $overrides));
    }

    /**
     * Add one finished game with a single (tracked) participant.
     *
     * @param  array<string, mixed>  $stats  participant column overrides
     */
    protected function addGame(Player $player, array $stats = [], int $duration = 1800, int $queueId = 420, ?Carbon $startedAt = null): GameMatch
    {
        $match = $this->createMatch($duration, $queueId, $startedAt);

        $match->participants()->create(array_merge($this->participantDefaults($player), $stats));

        return $match;
    }

    /**
     * Add one finished game where several tracked players take part: $blue plays
     * on team 100 and $red on team 200.
     *
     * @param  list<Player>  $blue
     * @param  list<Player>  $red
     * @param  array<string, mixed>  $stats  participant column overrides applied to everyone
     */
    protected function addTeamGame(array $blue, array $red, bool $blueWins, int $duration = 1800, int $queueId = 420, ?Carbon $startedAt = null, array $stats = []): GameMatch
    {
        $match = $this->createMatch($duration, $queueId, $startedAt);

        foreach ([[100, $blue, $blueWins], [200, $red, ! $blueWins]] as [$team, $players, $win]) {
            foreach ($players as $player) {
                $match->participants()->create(array_merge(
                    $this->participantDefaults($player),
                    ['team_id' => $team, 'win' => $win],
                    $stats,
                ));
            }
        }

        return $match;
    }

    protected function addSnapshot(
        Player $player,
        RankedQueue $queue,
        string $tier,
        string $rank,
        int $lp,
        int $wins = 10,
        int $losses = 10,
        ?Carbon $capturedAt = null,
    ): RankSnapshot {
        return $player->rankSnapshots()->create([
            'queue_type' => $queue,
            'tier' => $tier,
            'rank' => $rank,
            'league_points' => $lp,
            'wins' => $wins,
            'losses' => $losses,
            'captured_at' => $capturedAt ?? now(),
        ]);
    }

    private function createMatch(int $duration, int $queueId, ?Carbon $startedAt): GameMatch
    {
        $this->gameCounter++;

        return GameMatch::create([
            'match_id' => 'SG2_'.(100000 + $this->gameCounter),
            'queue_id' => $queueId,
            'game_version' => '16.1.1',
            'game_mode' => 'CLASSIC',
            'game_duration' => $duration,
            'game_start_at' => $startedAt ?? now()->subMinutes(1000 - $this->gameCounter),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function participantDefaults(Player $player): array
    {
        return [
            'player_id' => $player->id,
            'puuid' => $player->puuid,
            'riot_id_name' => $player->game_name,
            'riot_id_tag' => $player->tag_line,
            'champion_id' => 103,
            'champion_name' => 'Ahri',
            'team_id' => 100,
            'team_position' => 'MIDDLE',
            'win' => true,
            'kills' => 5,
            'deaths' => 2,
            'assists' => 5,
            'total_minions_killed' => 200,
            'neutral_minions_killed' => 10,
            'gold_earned' => 10000,
            'total_damage_dealt_to_champions' => 20000,
            'damage_taken' => 15000,
            'vision_score' => 30,
            'wards_placed' => 10,
            'items' => [3020, 6655, 0, 0, 0, 0, 3340],
            'summoner_spells' => [4, 14],
            'perks' => null,
        ];
    }
}
