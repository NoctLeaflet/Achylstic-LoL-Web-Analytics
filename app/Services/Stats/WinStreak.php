<?php

namespace App\Services\Stats;

use App\Models\GameMatch;
use App\Models\MatchParticipant;

/**
 * Consecutive wins (or losses) of a player, counted backwards from a given match.
 *
 * Remakes are skipped like in every other statistic. Matches are looked at in
 * the order they were played, so this must be asked for matches that are
 * already stored (MatchService stores new matches oldest first).
 */
final class WinStreak
{
    /** More than any milestone, so the query stays small. */
    private const LOOKBACK = 25;

    /** Wins in a row that end exactly at $match (0 when the player lost it). */
    public function endingAt(int $playerId, GameMatch $match): int
    {
        return $this->runEndingAt($playerId, $match, true);
    }

    /** Losses in a row that end exactly at $match (0 when the player won it). */
    public function lossesEndingAt(int $playerId, GameMatch $match): int
    {
        return $this->runEndingAt($playerId, $match, false);
    }

    private function runEndingAt(int $playerId, GameMatch $match, bool $wins): int
    {
        $results = MatchParticipant::query()
            ->join('matches', 'matches.id', '=', 'match_participants.match_id')
            ->where('match_participants.player_id', $playerId)
            ->where('matches.game_duration', '>=', GameMatch::REMAKE_MAX_DURATION_SECONDS)
            ->where(function ($query) use ($match) {
                $query->where('matches.game_start_at', '<', $match->game_start_at)
                    ->orWhere(fn ($same) => $same
                        ->where('matches.game_start_at', $match->game_start_at)
                        ->where('matches.id', '<=', $match->id));
            })
            ->orderByDesc('matches.game_start_at')
            ->orderByDesc('matches.id')
            ->limit(self::LOOKBACK)
            ->pluck('match_participants.win');

        $streak = 0;

        foreach ($results as $won) {
            if ((bool) $won !== $wins) {
                break;
            }

            $streak++;
        }

        return $streak;
    }

    /** Whether a streak of this length is worth announcing. */
    public function isMilestone(int $streak): bool
    {
        return in_array($streak, (array) config('services.discord.win_streak_milestones'), true);
    }
}
