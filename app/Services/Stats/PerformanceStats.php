<?php

namespace App\Services\Stats;

use App\Enums\MatchQueue;
use App\Models\GameMatch;
use App\Models\MatchParticipant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Averages and per-minute rates from stored matches.
 *
 * Rules (see CLAUDE.md):
 *  - remakes (shorter than 300 seconds) are ignored,
 *  - KDA = (kills + assists) / max(deaths, 1),
 *  - CS = total_minions_killed + neutral_minions_killed,
 *  - per-minute values use game_duration / 60.
 *
 * KDA and the per-minute rates are ratios of totals (e.g. all CS divided by all
 * minutes played) rather than averages of per-game ratios, so one very short or
 * deathless game cannot skew them.
 */
final class PerformanceStats
{
    public function __construct(private readonly MatchScore $scores) {}

    /** Stats of a player without any counted game. */
    private const EMPTY = [
        'games' => 0,
        'wins' => 0,
        'losses' => 0,
        'winrate' => 0.0,
        'avg_kills' => 0.0,
        'avg_deaths' => 0.0,
        'avg_assists' => 0.0,
        'kda' => 0.0,
        'cs_per_min' => 0.0,
        'damage_per_min' => 0.0,
        'gold_per_min' => 0.0,
        'vision_per_min' => 0.0,
    ];

    /**
     * @return array<string, int|float>
     */
    public function forPlayer(int $playerId, ?MatchQueue $queue = null): array
    {
        return $this->forPlayers([$playerId], $queue)[$playerId];
    }

    /**
     * Stats for several players with one query. Every requested player is in
     * the result, with zeros when there is no counted game.
     *
     * @param  list<int>  $playerIds
     * @return array<int, array<string, int|float>> keyed by player id
     */
    public function forPlayers(array $playerIds, ?MatchQueue $queue = null): array
    {
        if ($playerIds === []) {
            return [];
        }

        $stats = array_fill_keys($playerIds, self::EMPTY);

        $rows = $this->countedGames($queue)
            ->whereIn('match_participants.player_id', $playerIds)
            ->groupBy('match_participants.player_id')
            ->selectRaw('
                match_participants.player_id as player_id,
                COUNT(*) as games,
                SUM(match_participants.win) as wins,
                SUM(match_participants.kills) as kills,
                SUM(match_participants.deaths) as deaths,
                SUM(match_participants.assists) as assists,
                SUM(match_participants.total_minions_killed + match_participants.neutral_minions_killed) as cs,
                SUM(match_participants.total_damage_dealt_to_champions) as damage,
                SUM(match_participants.gold_earned) as gold,
                SUM(match_participants.vision_score) as vision,
                SUM(matches.game_duration) as seconds
            ')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $stats[(int) $row->player_id] = $this->summarize($row);
        }

        return $stats;
    }

    /**
     * Most played champions of a player.
     *
     * @return list<array{champion_id: int, champion_name: string, games: int, wins: int, winrate: float, kda: float}>
     */
    public function topChampions(int $playerId, ?MatchQueue $queue = null, int $limit = 5): array
    {
        return $this->countedGames($queue)
            ->where('match_participants.player_id', $playerId)
            ->groupBy('match_participants.champion_id', 'match_participants.champion_name')
            ->selectRaw('
                match_participants.champion_id as champion_id,
                match_participants.champion_name as champion_name,
                COUNT(*) as games,
                SUM(match_participants.win) as wins,
                SUM(match_participants.kills) as kills,
                SUM(match_participants.deaths) as deaths,
                SUM(match_participants.assists) as assists
            ')
            ->orderByDesc('games')
            ->orderByDesc('wins')
            ->orderBy('champion_name')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(fn ($row) => [
                'champion_id' => (int) $row->champion_id,
                'champion_name' => (string) $row->champion_name,
                'games' => (int) $row->games,
                'wins' => (int) $row->wins,
                'winrate' => round((int) $row->wins / (int) $row->games * 100, 1),
                'kda' => round(((int) $row->kills + (int) $row->assists) / max((int) $row->deaths, 1), 2),
            ])
            ->all();
    }

    /**
     * Latest matches of a player, newest first. Remakes are listed (flagged)
     * but never counted in the statistics above.
     *
     * @return list<array<string, mixed>>
     */
    public function recentMatches(int $playerId, ?MatchQueue $queue = null, int $limit = 20): array
    {
        $rows = MatchParticipant::query()
            ->join('matches', 'matches.id', '=', 'match_participants.match_id')
            ->where('match_participants.player_id', $playerId)
            ->when($queue !== null, fn (Builder $query) => $query->where('matches.queue_id', $queue->queueId()))
            ->orderByDesc('matches.game_start_at')
            ->orderByDesc('matches.id')
            ->limit($limit)
            ->select('match_participants.*')
            ->with('gameMatch')
            ->get();

        // Scores compare a player with the other nine of the same match, so they need every participant.
        $scores = $this->scores->forMatches($rows->pluck('match_id')->unique()->values()->all());

        return $rows
            ->map(function (MatchParticipant $p) use ($scores) {
                $match = $p->gameMatch;
                $cs = $p->total_minions_killed + $p->neutral_minions_killed;
                $minutes = $match->game_duration / 60;

                return [
                    'id' => $match->id,
                    'match_id' => $match->match_id,
                    'queue' => MatchQueue::fromQueueId($match->queue_id)?->label(),
                    'started_at' => $match->game_start_at,
                    'duration' => $match->game_duration,
                    'is_remake' => $match->isRemake(),
                    'win' => $p->win,
                    'champion_id' => $p->champion_id,
                    'champion_name' => $p->champion_name,
                    'position' => $p->team_position,
                    'kills' => $p->kills,
                    'deaths' => $p->deaths,
                    'assists' => $p->assists,
                    'kda' => round(($p->kills + $p->assists) / max($p->deaths, 1), 2),
                    'cs' => $cs,
                    'cs_per_min' => $minutes > 0 ? round($cs / $minutes, 1) : 0.0,
                    'damage' => $p->total_damage_dealt_to_champions,
                    'vision' => $p->vision_score,
                    'items' => $p->items ?? [],
                    'score' => $scores[$p->id]['score'] ?? null,
                    'badge' => $scores[$p->id]['badge'] ?? null,
                ];
            })
            ->all();
    }

    /** Participations joined with their match, remakes excluded, optionally for one queue. */
    private function countedGames(?MatchQueue $queue): Builder
    {
        return MatchParticipant::query()
            ->join('matches', 'matches.id', '=', 'match_participants.match_id')
            ->where('matches.game_duration', '>=', GameMatch::REMAKE_MAX_DURATION_SECONDS)
            ->when($queue !== null, fn (Builder $query) => $query->where('matches.queue_id', $queue->queueId()));
    }

    /**
     * @return array<string, int|float>
     */
    private function summarize(object $row): array
    {
        $games = (int) $row->games;
        $minutes = (int) $row->seconds / 60;

        if ($games === 0 || $minutes <= 0) {
            return self::EMPTY;
        }

        $wins = (int) $row->wins;
        $kills = (int) $row->kills;
        $deaths = (int) $row->deaths;
        $assists = (int) $row->assists;

        return [
            'games' => $games,
            'wins' => $wins,
            'losses' => $games - $wins,
            'winrate' => round($wins / $games * 100, 1),
            'avg_kills' => round($kills / $games, 1),
            'avg_deaths' => round($deaths / $games, 1),
            'avg_assists' => round($assists / $games, 1),
            'kda' => round(($kills + $assists) / max($deaths, 1), 2),
            'cs_per_min' => round((int) $row->cs / $minutes, 2),
            'damage_per_min' => round((int) $row->damage / $minutes, 1),
            'gold_per_min' => round((int) $row->gold / $minutes, 1),
            'vision_per_min' => round((int) $row->vision / $minutes, 2),
        ];
    }
}
