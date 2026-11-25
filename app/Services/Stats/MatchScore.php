<?php

namespace App\Services\Stats;

use App\Models\GameMatch;
use App\Models\MatchParticipant;
use Illuminate\Support\Collection;

/**
 * A performance score (usually 0-100, up to 130 for an outstanding game) for every participant of a match, plus the MVP and
 * ACE badges.
 *
 * This is our own formula, not an official Riot number and not the proprietary
 * score of other sites. Each of the 10 players is compared with the other 9 of
 * the same match on six things (min-max scaled to 0..1 inside the match):
 *
 *   KDA 25% · kill participation 20% · damage share of the team 20%
 *   vision score per minute 15% · gold per minute 10% · CS per minute 10%
 *
 * Supports are judged on what supports do: they play for kill participation and
 * vision instead of CS, gold and damage (KDA 25% · kill participation 30% ·
 * vision 25% · damage 10% · gold 5% · CS 5%). Without that, a good support would
 * always score far below the carries of their team.
 *
 * The weighted sum is scaled to 94 points, winners get 6 extra and an outstanding game earns up to 30 more (excellenceBonus), so a loss
 * can still score high but a win always counts for something. Because the
 * comparison is inside one match, a score says "how well did this player do in
 * this game", and the badges follow from it:
 *
 *   MVP = highest score of the winning team, ACE = highest score of the losing team
 *
 * Remakes get scores but no badges.
 */
final class MatchScore
{
    private const WEIGHTS = [
        'kda' => 0.25,
        'kill_participation' => 0.20,
        'damage_share' => 0.20,
        'vision_per_min' => 0.15,
        'gold_per_min' => 0.10,
        'cs_per_min' => 0.10,
    ];

    private const SUPPORT_WEIGHTS = [
        'kda' => 0.25,
        'kill_participation' => 0.30,
        'damage_share' => 0.10,
        'vision_per_min' => 0.25,
        'gold_per_min' => 0.05,
        'cs_per_min' => 0.05,
    ];

    private const SUPPORT_POSITION = 'UTILITY';

    private const SCALE = 94;

    private const WIN_BONUS = 6;

    public const MVP = 'MVP';

    public const ACE = 'ACE';

    /**
     * Scores for one match.
     *
     * @param  Collection<int, MatchParticipant>  $participants  every participant of the match
     * @return array<int, array{score: int, badge: string|null, rank: int}> keyed by participant row id
     */
    public function forMatch(Collection $participants, int $durationSeconds): array
    {
        if ($participants->isEmpty()) {
            return [];
        }

        $minutes = max($durationSeconds, 1) / 60;
        $teamKills = $participants->groupBy('team_id')->map(fn (Collection $team) => max((int) $team->sum('kills'), 1));
        $teamDamage = $participants->groupBy('team_id')->map(fn (Collection $team) => max((int) $team->sum('total_damage_dealt_to_champions'), 1));

        $metrics = $participants->mapWithKeys(fn (MatchParticipant $p) => [$p->id => [
            'kda' => ($p->kills + $p->assists) / max($p->deaths, 1),
            'kill_participation' => ($p->kills + $p->assists) / $teamKills[$p->team_id],
            'damage_share' => $p->total_damage_dealt_to_champions / $teamDamage[$p->team_id],
            'vision_per_min' => $p->vision_score / $minutes,
            'gold_per_min' => $p->gold_earned / $minutes,
            'cs_per_min' => ($p->total_minions_killed + $p->neutral_minions_killed) / $minutes,
        ]]);

        $ranges = [];
        foreach (array_keys(self::WEIGHTS) as $metric) {
            // Every player is scaled against all ten, whatever their role.
            $values = $metrics->pluck($metric);
            $ranges[$metric] = [$values->min(), $values->max()];
        }

        $scores = [];
        $kda = [];

        foreach ($participants as $p) {
            $base = 0.0;
            $weights = $p->team_position === self::SUPPORT_POSITION ? self::SUPPORT_WEIGHTS : self::WEIGHTS;

            foreach ($weights as $metric => $weight) {
                [$min, $max] = $ranges[$metric];
                $base += $weight * ($max > $min ? ($metrics[$p->id][$metric] - $min) / ($max - $min) : 0.5);
            }

            // No upper clamp: an outstanding game can go past 100 (see excellenceBonus()).
            $scores[$p->id] = max(0, (int) round(
                $base * self::SCALE + ($p->win ? self::WIN_BONUS : 0) + $this->excellenceBonus($p, $metrics[$p->id]),
            ));
            $kda[$p->id] = $metrics[$p->id]['kda'];
        }

        $badges = $durationSeconds >= GameMatch::REMAKE_MAX_DURATION_SECONDS
            ? $this->badges($participants, $scores, $kda)
            : [];

        // Place within the match by score (1 = best of all ten); equal scores go to the better KDA.
        $order = $participants->sort(fn (MatchParticipant $a, MatchParticipant $b) => [$scores[$b->id], $kda[$b->id], $a->id] <=> [$scores[$a->id], $kda[$a->id], $b->id])
            ->pluck('id')
            ->flip();

        return $participants->mapWithKeys(fn (MatchParticipant $p) => [$p->id => [
            'score' => $scores[$p->id],
            'badge' => $badges[$p->id] ?? null,
            'rank' => $order[$p->id] + 1,
        ]])->all();
    }

    /**
     * Extra points for a game that is good in absolute terms, not only better than the other nine
     * players. Up to 30 in total, so the best possible score is 130:
     *
     *   KDA above 5 ............ up to 10 (reaches 10 at a KDA of 10)
     *   kill participation > 70% up to 8  (reaches 8 at 100%)
     *   damage share > 30% ..... up to 6  (reaches 6 at 60%)
     *   deathless, 8+ takedowns   6
     *
     * @param  array<string, float>  $metrics
     */
    private function excellenceBonus(MatchParticipant $p, array $metrics): float
    {
        $kda = min(max(($metrics['kda'] - 5) * 2, 0), 10);
        $participation = min(max(($metrics['kill_participation'] - 0.7) / 0.3 * 8, 0), 8);
        $damage = min(max(($metrics['damage_share'] - 0.3) / 0.3 * 6, 0), 6);
        $deathless = $p->deaths === 0 && $p->kills + $p->assists >= 8 ? 6 : 0;

        return $kda + $participation + $damage + $deathless;
    }

    /**
     * Scores for several stored matches at once (two queries).
     *
     * @param  list<int>  $matchIds  ids of the matches table
     * @return array<int, array{score: int, badge: string|null}> keyed by participant row id
     */
    public function forMatches(array $matchIds): array
    {
        if ($matchIds === []) {
            return [];
        }

        $durations = GameMatch::query()->whereIn('id', $matchIds)->pluck('game_duration', 'id');
        $result = [];

        foreach (MatchParticipant::query()->whereIn('match_id', $matchIds)->get()->groupBy('match_id') as $matchId => $participants) {
            $result += $this->forMatch($participants, (int) ($durations[$matchId] ?? 0));
        }

        return $result;
    }

    /**
     * @param  Collection<int, MatchParticipant>  $participants
     * @param  array<int, int>  $scores
     * @param  array<int, float>  $kda
     * @return array<int, string> badge per participant row id
     */
    private function badges(Collection $participants, array $scores, array $kda): array
    {
        $badges = [];

        foreach ([true => self::MVP, false => self::ACE] as $won => $badge) {
            $best = $participants
                ->filter(fn (MatchParticipant $p) => (bool) $p->win === (bool) $won)
                ->sort(fn (MatchParticipant $a, MatchParticipant $b) => [$scores[$b->id], $kda[$b->id], $a->id] <=> [$scores[$a->id], $kda[$a->id], $b->id])
                ->first();

            if ($best !== null) {
                $badges[$best->id] = $badge;
            }
        }

        return $badges;
    }
}
