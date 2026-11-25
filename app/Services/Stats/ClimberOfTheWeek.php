<?php

namespace App\Services\Stats;

use App\Enums\RankedQueue;
use App\Models\Player;
use App\Models\RankSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * LP gained over the last 7 days, per active player, for one ranked queue.
 *
 * Gains use the continuous LP scale of RankScore, so a promotion counts for what
 * it is worth (Gold I 90 LP -> Platinum IV 10 LP is +20, not -80). A player is
 * measured from their last snapshot before the window; when they started being
 * tracked inside the window, from their first snapshot. Players without a
 * snapshot in the queue are not listed.
 */
final class ClimberOfTheWeek
{
    public const DAYS = 7;

    /**
     * Every player with a rank in the queue, biggest gain first.
     *
     * @return list<array{player: Player, gain: int, from: string, to: string, since: CarbonInterface}>
     */
    public function ranking(RankedQueue $queue, ?CarbonInterface $now = null, ?int $days = self::DAYS): array
    {
        // $days = null means "all time": measured from each player's first snapshot.
        $cutoff = $days === null ? Carbon::createFromTimestamp(0) : ($now ?? now())->copy()->subDays($days);
        $players = Player::active()->get();
        $playerIds = $players->modelKeys();

        $inWindow = RankSnapshot::query()
            ->whereIn('player_id', $playerIds)
            ->where('queue_type', $queue->value)
            ->where('captured_at', '>=', $cutoff)
            ->orderBy('captured_at')
            ->orderBy('id')
            ->get()
            ->groupBy('player_id');

        $before = RankSnapshot::query()
            ->whereIn('player_id', $playerIds)
            ->where('queue_type', $queue->value)
            ->where('captured_at', '<', $cutoff)
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->get()
            ->unique('player_id')
            ->keyBy('player_id');

        $rows = [];

        foreach ($players as $player) {
            $recent = $inWindow->get($player->id);
            $start = $before->get($player->id) ?? $recent?->first();
            $end = $recent?->last() ?? $before->get($player->id);

            if ($start === null || $end === null) {
                continue;
            }

            $rows[] = [
                'player' => $player,
                'gain' => $this->score($end) - $this->score($start),
                'from' => RankScore::label($start->tier, $start->rank, $start->league_points),
                'to' => RankScore::label($end->tier, $end->rank, $end->league_points),
                'since' => $start->captured_at,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['gain'], $a['player']->name] <=> [$a['gain'], $b['player']->name]);

        return $rows;
    }

    /**
     * The player who climbed the most, or null when nobody gained LP.
     *
     * @return array{player: Player, gain: int, from: string, to: string, since: CarbonInterface}|null
     */
    public function climber(RankedQueue $queue, ?CarbonInterface $now = null, ?int $days = self::DAYS): ?array
    {
        $best = $this->ranking($queue, $now, $days)[0] ?? null;

        return $best !== null && $best['gain'] > 0 ? $best : null;
    }

    private function score(RankSnapshot $snapshot): int
    {
        return RankScore::score($snapshot->tier, $snapshot->rank, $snapshot->league_points);
    }
}
