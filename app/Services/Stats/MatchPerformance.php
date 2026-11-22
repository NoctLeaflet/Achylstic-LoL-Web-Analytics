<?php

namespace App\Services\Stats;

use App\Models\GameMatch;
use App\Models\MatchParticipant;
use Illuminate\Support\Collection;

/**
 * The "Performance" pop-up of a match: for each tracked player how they did against
 * the player of the same lane on the other team.
 *
 *  - a headline on the lane ("won lane, +660 gold at 14 minutes") and the gold
 *    difference over time (needs the match timeline),
 *  - CS at 10 minutes, vision and damage against the lane opponent,
 *  - the game score and the place in the lobby.
 *
 * Modes without lanes (ARAM) and remakes have no lane opponent: then only the
 * score and the player's own numbers are shown. Kill and objective timelines are
 * not stored, so the chart only shows the gold difference.
 */
final class MatchPerformance
{
    /** The minute the lane is judged at, as long as the game lasted that long. */
    public const LANE_MINUTE = 14;

    public const CS_MINUTE = 10;

    /** A gold difference smaller than this is called an even lane. */
    public const EVEN_BELOW = 300;

    /**
     * @param  list<array<string, mixed>>  $teams  teams as built by MatchScoreboard
     * @param  Collection<int, MatchParticipant>  $participants
     * @return list<array<string, mixed>> one entry per tracked player of the match
     */
    public function build(array $teams, Collection $participants, GameMatch $match): array
    {
        if ($match->isRemake()) {
            return [];
        }

        // Every participant, tagged with the team they are on.
        $all = collect($teams)->flatMap(fn (array $team) => array_map(fn (array $p) => $p + ['team_id' => $team['team_id']], $team['participants']));
        $byId = $participants->keyBy('id');
        $ranked = $all->sort(fn ($a, $b) => [$b['score'], $a['id']] <=> [$a['score'], $b['id']])->pluck('id')->values();

        $entries = [];

        foreach ($all as $me) {
            if ($me['player'] === null) {
                continue;
            }

            $opponent = $this->opponent($all, $me);
            $entries[] = [
                'id' => $me['id'],
                'name' => $me['name'],
                'champion_id' => $me['champion_id'],
                'champion_name' => $me['champion_name'],
                'win' => $me['win'],
                'score' => $me['score'],
                'badge' => $me['badge'],
                'place' => $ranked->search($me['id']) + 1,
                'opponent' => $opponent === null ? null : [
                    'name' => $opponent['name'],
                    'champion_id' => $opponent['champion_id'],
                    'champion_name' => $opponent['champion_name'],
                ],
                'lane' => $opponent === null ? null : $this->lane($byId[$me['id']]->metrics ?? null, $byId[$opponent['id']]->metrics ?? null),
                'cards' => $this->cards($me, $opponent, $byId[$me['id']]->metrics ?? null, $byId[$opponent['id'] ?? 0]->metrics ?? null),
            ];
        }

        return $entries;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $all
     * @param  array<string, mixed>  $me
     * @return array<string, mixed>|null
     */
    private function opponent(Collection $all, array $me): ?array
    {
        if (empty($me['position'])) {
            return null;
        }

        $same = $all->filter(fn ($p) => $p['team_id'] !== $me['team_id'] && $p['position'] === $me['position'])->values();
        $mates = $all->filter(fn ($p) => $p['team_id'] === $me['team_id'] && $p['position'] === $me['position'])->count();

        // A role that is filled twice on a team has no clear opponent.
        return $same->count() === 1 && $mates === 1 ? $same->first() : null;
    }

    /**
     * Gold difference over time and the headline built from it.
     *
     * @param  array<string, list<int>>|null  $mine
     * @param  array<string, list<int>>|null  $theirs
     * @return array{headline: string, outcome: string, swing: string|null, minutes: list<int>, diff: list<int>}|null
     */
    private function lane(?array $mine, ?array $theirs): ?array
    {
        $a = $mine['gold'] ?? [];
        $b = $theirs['gold'] ?? [];
        $length = min(count($a), count($b));

        if ($length < 2) {
            return null;
        }

        $diff = [];

        for ($i = 0; $i < $length; $i++) {
            $diff[] = $a[$i] - $b[$i];
        }

        $minute = min(self::LANE_MINUTE, $length - 1);
        $atLane = $diff[$minute];
        $outcome = abs($atLane) < self::EVEN_BELOW ? 'even' : ($atLane > 0 ? 'won' : 'lost');

        $swingMinute = 1;
        $swing = 0;

        for ($i = 1; $i < $length; $i++) {
            if (abs($diff[$i] - $diff[$i - 1]) > abs($swing)) {
                $swing = $diff[$i] - $diff[$i - 1];
                $swingMinute = $i;
            }
        }

        return [
            'outcome' => $outcome,
            'minute' => $minute,
            'gold' => $atLane,
            'swing' => $swing === 0 ? null : sprintf('%s%s at %d minutes', $swing > 0 ? '+' : '-', number_format(abs($swing), 0, '.', ','), $swingMinute),
            'minutes' => range(0, $length - 1),
            'diff' => $diff,
        ];
    }

    /**
     * Three "me against the lane opponent" numbers. Without an opponent only my own side is filled.
     *
     * @param  array<string, mixed>  $me
     * @param  array<string, mixed>|null  $opponent
     * @param  array<string, list<int>>|null  $mine
     * @param  array<string, list<int>>|null  $theirs
     * @return list<array{label: string, me: int, opponent: int|null}>
     */
    private function cards(array $me, ?array $opponent, ?array $mine, ?array $theirs): array
    {
        $cs10 = fn (?array $series, ?array $player) => isset($series['cs'][self::CS_MINUTE]) ? (int) $series['cs'][self::CS_MINUTE] : null;

        $cards = [];

        if ($cs10($mine, $me) !== null && $opponent !== null && $cs10($theirs, $opponent) !== null) {
            $cards[] = ['label' => 'CS at '.self::CS_MINUTE, 'me' => $cs10($mine, $me), 'opponent' => $cs10($theirs, $opponent)];
        } else {
            $cards[] = ['label' => 'CS', 'me' => (int) $me['cs'], 'opponent' => $opponent['cs'] ?? null];
        }

        $cards[] = ['label' => 'Vision score', 'me' => (int) $me['vision'], 'opponent' => $opponent['vision'] ?? null];
        $cards[] = ['label' => 'Damage to champions', 'me' => (int) $me['damage'], 'opponent' => $opponent['damage'] ?? null];

        return $cards;
    }
}
