<?php

namespace App\Services\Stats;

use App\Enums\RankedQueue;
use App\Models\GameMatch;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How two tracked players do together and against each other.
 *
 *  - together (duo):  matches where both were on the same team, with the shared result.
 *  - versus (H2H):    matches where they were on opposing teams, seen from the first player.
 *
 * Only matches in which both players took part count, and remakes are ignored
 * like everywhere else. A record looks like
 * ['games' => 4, 'wins' => 3, 'losses' => 1, 'winrate' => 75.0] and is always
 * from the point of view of the first player of the pair; the winrate is null
 * when there is no game.
 */
final class PairStats
{
    /**
     * Records for every pair among the given players, including pairs that never
     * met. Each pair appears once, the player with the lower id first.
     *
     * @param  list<int>  $playerIds
     * @return list<array{a: int, b: int, together: array<string, int|float|null>, versus: array<string, int|float|null>}>
     */
    public function among(array $playerIds, ?RankedQueue $queue = null): array
    {
        $ids = collect($playerIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        if (count($ids) < 2) {
            return [];
        }

        $scope = fn (Builder $query) => $query
            ->whereIn('a.player_id', $ids)
            ->whereIn('b.player_id', $ids)
            ->whereColumn('a.player_id', '<', 'b.player_id');

        $together = $this->aggregate(true, $scope, $queue);
        $versus = $this->aggregate(false, $scope, $queue);

        $pairs = [];

        foreach ($ids as $i => $a) {
            foreach (array_slice($ids, $i + 1) as $b) {
                $pairs[] = [
                    'a' => $a,
                    'b' => $b,
                    'together' => $this->record($together->get("{$a}-{$b}")),
                    'versus' => $this->record($versus->get("{$a}-{$b}")),
                ];
            }
        }

        return $pairs;
    }

    /**
     * Records of one player against every other tracked player they have shared
     * a match with, most played first. Seen from $playerId.
     *
     * @return list<array{player_id: int, together: array<string, int|float|null>, versus: array<string, int|float|null>}>
     */
    public function forPlayer(int $playerId, ?RankedQueue $queue = null): array
    {
        $scope = fn (Builder $query) => $query
            ->where('a.player_id', $playerId)
            ->whereNotNull('b.player_id')
            ->where('b.player_id', '<>', $playerId);

        $together = $this->aggregate(true, $scope, $queue);
        $versus = $this->aggregate(false, $scope, $queue);

        $partnerIds = $together->keys()->merge($versus->keys())
            ->map(fn (string $key) => (int) explode('-', $key)[1])
            ->unique();

        return $partnerIds
            ->map(fn (int $partnerId) => [
                'player_id' => $partnerId,
                'together' => $this->record($together->get("{$playerId}-{$partnerId}")),
                'versus' => $this->record($versus->get("{$playerId}-{$partnerId}")),
            ])
            ->sortBy([
                fn (array $a, array $b) => ($b['together']['games'] + $b['versus']['games']) <=> ($a['together']['games'] + $a['versus']['games']),
                fn (array $a, array $b) => $a['player_id'] <=> $b['player_id'],
            ])
            ->values()
            ->all();
    }

    /**
     * Games and wins (of player "a") per "a-b" pair.
     *
     * @return Collection<string, object{games: int, wins: int}>
     */
    private function aggregate(bool $sameTeam, callable $scope, ?RankedQueue $queue): Collection
    {
        $query = DB::table('match_participants as a')
            ->join('match_participants as b', 'a.match_id', '=', 'b.match_id')
            ->join('matches as m', 'm.id', '=', 'a.match_id')
            ->where('m.game_duration', '>=', GameMatch::REMAKE_MAX_DURATION_SECONDS)
            ->whereColumn('a.team_id', $sameTeam ? '=' : '<>', 'b.team_id');

        if ($queue !== null) {
            $query->where('m.queue_id', $queue->queueId());
        }

        $scope($query);

        return $query
            ->groupBy('a.player_id', 'b.player_id')
            ->selectRaw('a.player_id as a_id, b.player_id as b_id, COUNT(*) as games, SUM(a.win) as wins')
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->a_id}-{$row->b_id}" => (object) ['games' => (int) $row->games, 'wins' => (int) $row->wins]]);
    }

    /**
     * @return array<string, int|float|null>
     */
    private function record(?object $row): array
    {
        $games = $row->games ?? 0;
        $wins = $row->wins ?? 0;

        return [
            'games' => $games,
            'wins' => $wins,
            'losses' => $games - $wins,
            'winrate' => $games > 0 ? round($wins / $games * 100, 1) : null,
        ];
    }
}
