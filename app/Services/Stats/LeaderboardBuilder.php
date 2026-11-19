<?php

namespace App\Services\Stats;

use App\Enums\RankedQueue;
use App\Models\Player;
use App\Models\RankSnapshot;

/**
 * Leaderboard rows for one ranked queue.
 *
 * Players are ordered by tier, then division, then LP (RankScore::sortKey).
 * Players without a rank in the queue come last, alphabetically, without a position.
 * Games and winrate are the ranked wins/losses reported by league-v4 (the latest snapshot).
 */
final class LeaderboardBuilder
{
    /**
     * @return list<array{
     *     position: int|null,
     *     player: Player,
     *     snapshot: RankSnapshot|null,
     *     games: int,
     *     winrate: float|null
     * }>
     */
    public function rows(RankedQueue $queue): array
    {
        $players = Player::active()->get();

        $latest = RankSnapshot::query()
            ->whereIn('player_id', $players->modelKeys())
            ->where('queue_type', $queue->value)
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->get()
            ->unique('player_id')
            ->keyBy('player_id');

        $rows = $players->map(function (Player $player) use ($latest) {
            /** @var RankSnapshot|null $snapshot */
            $snapshot = $latest->get($player->id);
            $games = $snapshot ? $snapshot->wins + $snapshot->losses : 0;

            return [
                'position' => null,
                'player' => $player,
                'snapshot' => $snapshot,
                'games' => $games,
                'winrate' => $games > 0 ? round($snapshot->wins / $games * 100, 1) : null,
                'sort_key' => $snapshot ? RankScore::sortKey($snapshot->tier, $snapshot->rank, $snapshot->league_points) : null,
            ];
        });

        $ranked = $rows->whereNotNull('sort_key')
            ->sort(fn (array $a, array $b) => [$b['sort_key'], $b['snapshot']->wins, $a['player']->name]
                <=> [$a['sort_key'], $a['snapshot']->wins, $b['player']->name])
            ->values();

        $unranked = $rows->whereNull('sort_key')
            ->sortBy(fn (array $row) => mb_strtolower($row['player']->name))
            ->values();

        return $ranked->map(function (array $row, int $index) {
            $row['position'] = $index + 1;

            return $row;
        })->concat($unranked)
            ->map(fn (array $row) => collect($row)->except('sort_key')->all())
            ->all();
    }
}
