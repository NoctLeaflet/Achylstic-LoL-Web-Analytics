<?php

namespace App\Services\Stats;

use App\Enums\MatchQueue;
use App\Models\MatchParticipant;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * "Best this week" and "This week": what the tracked players did in the last 7 days,
 * over every stored game mode (remakes excluded).
 *
 * Both are built from one list of games, each with the score of MatchScore.
 *
 *  - best(): four single games worth showing (best-rated win, most assists in a win,
 *    most takedowns in one game, lowest-rated loss).
 *  - awards(): fun titles for players with at least MIN_GAMES games this week
 *    (best average rating, most loyal to one champion, most different champions,
 *    best average vision score).
 */
final class WeeklyHighlights
{
    public const DAYS = 7;

    public const MIN_GAMES = 3;

    public function __construct(private readonly GameRows $rows) {}

    /**
     * @return array{best: list<array<string, mixed>>, awards: list<array<string, mixed>>, games: int}
     */
    public function build(?CarbonInterface $now = null, ?MatchQueue $bestMode = null): array
    {
        $games = $this->games($now ?? now());

        // "Best this week" can be limited to one game mode; the awards always cover every mode.
        $bestGames = $bestMode === null ? $games : $games->where('queue_id', $bestMode->queueId());

        return [
            'best' => $this->best($bestGames),
            'awards' => $this->awards($games),
            'games' => $games->count(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $games
     * @return list<array<string, mixed>>
     */
    private function best(Collection $games): array
    {
        $wins = $games->where('win', true);
        $losses = $games->where('win', false);
        $byScore = fn (Collection $c, string $dir) => $c->sort(fn ($a, $b) => $dir === 'desc'
            ? [$b['score'], $b['kda'], $a['id']] <=> [$a['score'], $a['kda'], $b['id']]
            : [$a['score'], $a['kda'], $a['id']] <=> [$b['score'], $b['kda'], $b['id']])->first();

        $picks = [
            ['carry', 'CARRY', 'Win dengan rating tertinggi', $byScore($wins, 'desc')],
            ['comeback', 'COMEBACK', null, $wins->sortByDesc(fn ($g) => [$g['assists'], $g['score']])->first()],
            ['chaos', 'CHAOS', null, $games->sortByDesc(fn ($g) => [$g['kills'] + $g['assists'], $g['score']])->first()],
            ['tilt', 'TILT', 'Kekalahan dengan rating terendah', $byScore($losses, 'asc')],
        ];

        $best = [];

        foreach ($picks as [$key, $label, $blurb, $game]) {
            if ($game === null) {
                continue;
            }

            $best[] = [
                'key' => $key,
                'label' => $label,
                'blurb' => $blurb ?? match ($key) {
                    'comeback' => "{$game['assists']} assist dalam satu kemenangan",
                    'chaos' => ($game['kills'] + $game['assists']).' takedown dalam satu game',
                },
                'game' => $game,
            ];
        }

        return $best;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $games
     * @return list<array<string, mixed>>
     */
    private function awards(Collection $games): array
    {
        $perPlayer = $games->groupBy(fn ($g) => $g['player']->id)->filter(fn ($g) => $g->count() >= self::MIN_GAMES);

        if ($perPlayer->isEmpty()) {
            return [];
        }

        $stats = $perPlayer->map(function (Collection $g) {
            $champions = $g->countBy('champion_name')->sortDesc();

            return [
                'player' => $g->first()['player'],
                'avg_score' => $g->avg('score'),
                'avg_vision' => $g->avg('vision'),
                'distinct' => $champions->count(),
                'top_champion' => $champions->keys()->first(),
                'top_share' => $champions->first() / $g->count(),
                'top_champion_id' => $g->firstWhere('champion_name', $champions->keys()->first())['champion_id'],
            ];
        });

        $pick = fn (string $metric) => $stats->sort(fn ($a, $b) => [$b[$metric], $a['player']->name] <=> [$a[$metric], $b['player']->name])->first();

        $carry = $pick('avg_score');
        $pony = $pick('top_share');
        $jack = $pick('distinct');
        $vision = $pick('avg_vision');

        return [
            ['key' => 'carry', 'title' => 'The Carry', 'value' => number_format($carry['avg_score'], 1, '.', '').' avg rating', 'blurb' => 'Paling konsisten bermain bagus', 'player' => $carry['player']],
            ['key' => 'pony', 'title' => 'One-Trick Pony', 'value' => round($pony['top_share'] * 100).'% '.$pony['top_champion'], 'blurb' => 'Setia pada satu champion', 'player' => $pony['player']],
            ['key' => 'jack', 'title' => 'Jack of All Trades', 'value' => $jack['distinct'].' champs', 'blurb' => 'Suka mencoba champion berbeda', 'player' => $jack['player']],
            ['key' => 'vision', 'title' => 'Vision Enthusiast', 'value' => number_format($vision['avg_vision'], 1, '.', '').' avg', 'blurb' => 'Vision score tertinggi per game', 'player' => $vision['player']],
        ];
    }

    /**
     * Every game of an active tracked player in the window, with derived numbers.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function games(CarbonInterface $now): Collection
    {
        $since = $now->copy()->subDays(self::DAYS);

        $rows = MatchParticipant::query()
            ->whereNotNull('player_id')
            ->whereHas('player', fn ($q) => $q->where('is_active', true))
            ->whereHas('gameMatch', fn ($q) => $q->withoutRemakes()->where('game_start_at', '>=', $since))
            ->with(['gameMatch', 'player'])
            ->get();

        return $this->rows->build($rows);
    }
}
