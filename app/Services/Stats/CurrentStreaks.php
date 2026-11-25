<?php

namespace App\Services\Stats;

use App\Enums\MatchQueue;
use App\Models\GameMatch;
use App\Models\MatchParticipant;
use App\Models\Player;
use Illuminate\Support\Collection;

/**
 * Who is on a win or lose streak right now: the run of equal results at the end
 * of a player's games (remakes ignored, optionally for one game mode). Only
 * streaks of MIN_STREAK games or more are listed, longest first.
 */
final class CurrentStreaks
{
    public const MIN_STREAK = 2;

    /** Looked at per player; more than any streak worth showing. */
    private const LOOKBACK = 40;

    /**
     * @param  Collection<int, Player>  $players
     * @return array{wins: list<array{player: Player, games: int}>, losses: list<array{player: Player, games: int}>}
     */
    public function build(Collection $players, ?MatchQueue $mode = null): array
    {
        $wins = [];
        $losses = [];

        foreach ($players as $player) {
            $results = MatchParticipant::query()
                ->join('matches', 'matches.id', '=', 'match_participants.match_id')
                ->where('match_participants.player_id', $player->id)
                ->where('matches.game_duration', '>=', GameMatch::REMAKE_MAX_DURATION_SECONDS)
                ->when($mode !== null, fn ($query) => $query->where('matches.queue_id', $mode->queueId()))
                ->orderByDesc('matches.game_start_at')
                ->orderByDesc('matches.id')
                ->limit(self::LOOKBACK)
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

            if ($streak >= self::MIN_STREAK) {
                $entry = ['player' => $player, 'games' => $streak];
                $results[0] ? $wins[] = $entry : $losses[] = $entry;
            }
        }

        $longest = fn (array $a, array $b) => [$b['games'], $a['player']->name] <=> [$a['games'], $b['player']->name];
        usort($wins, $longest);
        usort($losses, $longest);

        return ['wins' => $wins, 'losses' => $losses];
    }
}
