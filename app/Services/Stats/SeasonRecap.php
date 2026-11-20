<?php

namespace App\Services\Stats;

use App\Enums\RankedQueue;
use App\Models\GameMatch;
use App\Models\MatchParticipant;
use App\Models\Player;
use App\Models\RankSnapshot;
use App\Services\Achievements\AchievementCatalog;
use App\Services\Achievements\AchievementTracker;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * A "wrapped"-style summary of one player over a period (the season, the last 90
 * days...): games, hours, KDA, favourite champions and lane, MVP/ACE count, best
 * game, longest win streak, Solo/Duo rank at the start and the end, and the
 * achievements earned in the period. Remakes are ignored.
 */
final class SeasonRecap
{
    public function __construct(
        private readonly MatchScore $scores,
        private readonly AchievementTracker $achievements,
    ) {}

    /** First day of the current season: SEASON_START, or 1 January of this year. */
    public static function seasonStart(?CarbonInterface $now = null): CarbonInterface
    {
        $now ??= now();
        $configured = config('services.recap.season_start');

        if ($configured) {
            try {
                return Carbon::parse($configured)->startOfDay();
            } catch (\Throwable) {
                // fall through to the default
            }
        }

        return $now->copy()->startOfYear();
    }

    /**
     * @return array<string, mixed>|null null when the player has no counted game in the period
     */
    public function forPlayer(Player $player, CarbonInterface $since): ?array
    {
        $games = MatchParticipant::query()
            ->join('matches', 'matches.id', '=', 'match_participants.match_id')
            ->where('match_participants.player_id', $player->id)
            ->where('matches.game_duration', '>=', GameMatch::REMAKE_MAX_DURATION_SECONDS)
            ->where('matches.game_start_at', '>=', $since)
            ->orderBy('matches.game_start_at')
            ->orderBy('matches.id')
            ->get([
                'match_participants.id', 'match_participants.match_id', 'match_participants.win', 'match_participants.kills',
                'match_participants.deaths', 'match_participants.assists', 'match_participants.champion_id',
                'match_participants.champion_name', 'match_participants.team_position',
                'matches.game_duration', 'matches.game_start_at', 'matches.match_id as riot_match_id',
            ]);

        if ($games->isEmpty()) {
            return null;
        }

        $wins = $games->where('win', true)->count();
        $deaths = max((int) $games->sum('deaths'), 1);
        $scores = $this->scores->forMatches($games->pluck('match_id')->unique()->values()->all());

        $best = $games->sortByDesc(fn ($g) => [$scores[$g->id]['score'] ?? 0, $g->win])->first();

        return [
            'games' => $games->count(),
            'wins' => $wins,
            'losses' => $games->count() - $wins,
            'winrate' => (int) round($wins / $games->count() * 100),
            'hours' => round($games->sum('game_duration') / 3600, 1),
            'kills' => (int) $games->sum('kills'),
            'assists' => (int) $games->sum('assists'),
            'kda' => round(($games->sum('kills') + $games->sum('assists')) / $deaths, 2),
            'champions' => $this->champions($games),
            'role' => $this->favouriteRole($games),
            'mvp' => $games->filter(fn ($g) => ($scores[$g->id]['badge'] ?? null) === MatchScore::MVP)->count(),
            'ace' => $games->filter(fn ($g) => ($scores[$g->id]['badge'] ?? null) === MatchScore::ACE)->count(),
            'longest_streak' => $this->longestStreak($games),
            'best_game' => [
                'match_id' => $best->riot_match_id,
                'champion' => $best->champion_name,
                'champion_id' => $best->champion_id,
                'score' => $scores[$best->id]['score'] ?? 0,
                'kda' => sprintf('%d/%d/%d', $best->kills, $best->deaths, $best->assists),
            ],
            'rank' => $this->rank($player, $since),
            'achievements' => $this->earnedSince($player, $since),
        ];
    }

    /**
     * @return list<array{champion_id: int, name: string, games: int, winrate: int}>
     */
    private function champions($games): array
    {
        return $games->groupBy('champion_id')
            ->map(fn ($rows) => [
                'champion_id' => (int) $rows->first()->champion_id,
                'name' => (string) $rows->first()->champion_name,
                'games' => $rows->count(),
                'winrate' => (int) round($rows->where('win', true)->count() / $rows->count() * 100),
            ])
            ->sort(fn ($a, $b) => [$b['games'], $a['name']] <=> [$a['games'], $b['name']])
            ->take(3)
            ->values()
            ->all();
    }

    /** @return array{label: string, games: int}|null */
    private function favouriteRole($games): ?array
    {
        $top = $games->whereIn('team_position', array_keys(RoleCoverage::ROLES))
            ->countBy('team_position')
            ->sortDesc()
            ->keys()
            ->first();

        return $top === null ? null : [
            'label' => RoleCoverage::ROLES[$top],
            'games' => $games->where('team_position', $top)->count(),
        ];
    }

    private function longestStreak($games): int
    {
        $best = $current = 0;

        foreach ($games as $game) {
            $current = $game->win ? $current + 1 : 0;
            $best = max($best, $current);
        }

        return $best;
    }

    /**
     * Solo/Duo rank at the start of the period (last snapshot before it, or the first one) and now.
     *
     * @return array{from: string, to: string, change: int}|null
     */
    private function rank(Player $player, CarbonInterface $since): ?array
    {
        $snapshots = RankSnapshot::where('player_id', $player->id)
            ->where('queue_type', RankedQueue::Solo->value)
            ->orderBy('captured_at')->orderBy('id')
            ->get();

        if ($snapshots->isEmpty()) {
            return null;
        }

        $start = $snapshots->filter(fn ($s) => $s->captured_at->lt($since))->last() ?? $snapshots->first();
        $end = $snapshots->last();
        $score = fn (RankSnapshot $s) => RankScore::score($s->tier, $s->rank, $s->league_points);

        return [
            'from' => RankScore::label($start->tier, $start->rank, $start->league_points),
            'to' => RankScore::label($end->tier, $end->rank, $end->league_points),
            'change' => $score($end) - $score($start),
        ];
    }

    /**
     * @return list<array{name: string, icon: string}>
     */
    private function earnedSince(Player $player, CarbonInterface $since): array
    {
        $catalog = AchievementCatalog::all();

        return collect($this->achievements->evaluate($player))
            ->filter(fn (CarbonInterface $at) => $at->gte($since))
            ->map(fn ($at, string $code) => ['name' => $catalog[$code]['name'], 'icon' => $catalog[$code]['icon']])
            ->values()
            ->all();
    }
}
