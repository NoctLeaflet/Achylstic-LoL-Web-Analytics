<?php

namespace App\Services\Stats;

use App\Enums\RankedQueue;
use App\Models\GameMatch;
use App\Models\MatchParticipant;
use App\Models\Player;
use App\Models\RankSnapshot;
use Illuminate\Support\Carbon;

/**
 * Extra per-player columns of the leaderboard table: recent form with the current
 * streak, the LP trend line and the favourite champions of the climber.
 */
final class LeaderboardExtras
{
    /** Results shown as coloured dots. */
    public const FORM_GAMES = 10;

    /** Looked at to count a streak that is longer than the dots. */
    private const STREAK_LOOKBACK = 30;

    /**
     * Last results of every player in one ranked queue, oldest first, with the streak.
     *
     * @param  list<int>  $playerIds
     * @return array<int, array{results: list<bool>, streak: int, streak_type: 'W'|'L'|null}>
     */
    public function form(array $playerIds, RankedQueue $queue): array
    {
        $form = [];

        foreach ($playerIds as $playerId) {
            // Newest first.
            $results = MatchParticipant::query()
                ->join('matches', 'matches.id', '=', 'match_participants.match_id')
                ->where('match_participants.player_id', $playerId)
                ->where('matches.queue_id', $queue->queueId())
                ->where('matches.game_duration', '>=', GameMatch::REMAKE_MAX_DURATION_SECONDS)
                ->orderByDesc('matches.game_start_at')
                ->orderByDesc('matches.id')
                ->limit(self::STREAK_LOOKBACK)
                ->pluck('match_participants.win')
                ->map(fn ($win) => (bool) $win)
                ->all();

            $streak = 0;

            foreach ($results as $win) {
                if ($win !== $results[0]) {
                    break;
                }

                $streak++;
            }

            $form[$playerId] = [
                'results' => array_reverse(array_slice($results, 0, self::FORM_GAMES)),
                'streak' => $streak,
                'streak_type' => $results === [] ? null : ($results[0] ? 'W' : 'L'),
            ];
        }

        return $form;
    }

    /**
     * The LP score (RankScore scale) of every player at each snapshot inside the
     * window, plus the last one before it so the line starts where the window began.
     * Players with fewer than two points have no line.
     *
     * @param  list<int>  $playerIds
     * @return array<int, list<int>>
     */
    public function trend(array $playerIds, RankedQueue $queue, LeaderboardPeriod $period, ?Carbon $now = null): array
    {
        $now ??= now();
        $days = $period->days();
        $cutoff = $days === null ? Carbon::createFromTimestamp(0) : $now->copy()->subDays($days);

        $snapshots = RankSnapshot::query()
            ->whereIn('player_id', $playerIds)
            ->where('queue_type', $queue->value)
            ->orderBy('captured_at')
            ->orderBy('id')
            ->get()
            ->groupBy('player_id');

        $trend = [];

        foreach ($playerIds as $playerId) {
            $all = $snapshots->get($playerId) ?? collect();
            $before = $all->filter(fn (RankSnapshot $s) => $s->captured_at->lt($cutoff))->last();
            $inside = $all->filter(fn (RankSnapshot $s) => $s->captured_at->gte($cutoff));

            $trend[$playerId] = collect([$before])->filter()->concat($inside)
                ->map(fn (RankSnapshot $s) => RankScore::score($s->tier, $s->rank, $s->league_points))
                ->values()
                ->all();
        }

        return $trend;
    }

    /**
     * The champions a player used most in the window (ranked games of the queue),
     * each with the lane they were mostly played in and the number of games.
     *
     * @return list<array{champion_id: int, champion_name: string, role: string|null, games: int}>
     */
    public function topChampions(Player $player, RankedQueue $queue, LeaderboardPeriod $period, int $limit = 3, ?Carbon $now = null): array
    {
        $days = $period->days();

        $rows = MatchParticipant::query()
            ->join('matches', 'matches.id', '=', 'match_participants.match_id')
            ->where('match_participants.player_id', $player->id)
            ->where('matches.queue_id', $queue->queueId())
            ->where('matches.game_duration', '>=', GameMatch::REMAKE_MAX_DURATION_SECONDS)
            ->when($days !== null, fn ($q) => $q->where('matches.game_start_at', '>=', ($now ?? now())->copy()->subDays($days)))
            ->get(['match_participants.champion_id', 'match_participants.champion_name', 'match_participants.team_position']);

        return $rows->groupBy('champion_id')
            ->map(function ($games) {
                $role = $games->pluck('team_position')->filter()->countBy()->sortDesc()->keys()->first();

                return [
                    'champion_id' => (int) $games->first()->champion_id,
                    'champion_name' => (string) $games->first()->champion_name,
                    'role' => $role !== null ? (RoleCoverage::ROLES[$role] ?? null) : null,
                    'games' => $games->count(),
                ];
            })
            ->sort(fn (array $a, array $b) => [$b['games'], $a['champion_name']] <=> [$a['games'], $b['champion_name']])
            ->take($limit)
            ->values()
            ->all();
    }
}
