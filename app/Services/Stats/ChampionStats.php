<?php

namespace App\Services\Stats;

use App\Enums\MatchQueue;
use App\Models\GameMatch;
use App\Models\MatchParticipant;
use App\Models\Player;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which champions the group plays: games, winrate and KDA per champion over every
 * active tracked player, and who plays each champion most. Remakes are ignored.
 *
 * KDA is (kills + assists) / max(deaths, 1) over the summed games of the champion.
 */
final class ChampionStats
{
    /** Winrate and KDA highlights need at least this many games, or one lucky game would win. */
    public const MIN_GAMES_FOR_HIGHLIGHT = 5;

    public const PLAYERS_SHOWN = 3;

    /**
     * @return list<array{champion_id: int, champion_name: string, games: int, wins: int, winrate: int, kda: float, players: list<array{player: Player, games: int, winrate: int}>}>
     */
    public function forGroup(?MatchQueue $queue = null): array
    {
        $rows = MatchParticipant::query()
            ->join('matches', 'matches.id', '=', 'match_participants.match_id')
            ->join('players', 'players.id', '=', 'match_participants.player_id')
            ->where('players.is_active', true)
            ->where('matches.game_duration', '>=', GameMatch::REMAKE_MAX_DURATION_SECONDS)
            ->when($queue !== null, fn (Builder $query) => $query->where('matches.queue_id', $queue->queueId()))
            ->groupBy('match_participants.champion_id', 'match_participants.champion_name', 'match_participants.player_id')
            ->selectRaw('
                match_participants.champion_id as champion_id,
                match_participants.champion_name as champion_name,
                match_participants.player_id as player_id,
                COUNT(*) as games,
                SUM(match_participants.win) as wins,
                SUM(match_participants.kills) as kills,
                SUM(match_participants.deaths) as deaths,
                SUM(match_participants.assists) as assists
            ')
            ->toBase()
            ->get();

        $players = Player::query()->whereIn('id', $rows->pluck('player_id')->unique())->get()->keyBy('id');

        return $rows->groupBy('champion_id')
            ->map(function (Collection $perPlayer) use ($players) {
                $games = (int) $perPlayer->sum('games');
                $wins = (int) $perPlayer->sum('wins');
                $deaths = max((int) $perPlayer->sum('deaths'), 1);

                return [
                    'champion_id' => (int) $perPlayer->first()->champion_id,
                    'champion_name' => (string) $perPlayer->first()->champion_name,
                    'games' => $games,
                    'wins' => $wins,
                    'winrate' => (int) round($wins / $games * 100),
                    'kda' => round(((int) $perPlayer->sum('kills') + (int) $perPlayer->sum('assists')) / $deaths, 2),
                    'players' => $perPlayer
                        ->sort(fn ($a, $b) => [(int) $b->games, $players[$a->player_id]->name] <=> [(int) $a->games, $players[$b->player_id]->name])
                        ->take(self::PLAYERS_SHOWN)
                        ->map(fn ($row) => [
                            'player' => $players[$row->player_id],
                            'games' => (int) $row->games,
                            'winrate' => (int) round((int) $row->wins / (int) $row->games * 100),
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }
}
