<?php

namespace App\Http\Controllers;

use App\Enums\MatchQueue;
use App\Enums\RankedQueue;
use App\Models\Player;
use App\Services\Achievements\AchievementCatalog;
use App\Services\Achievements\AchievementTracker;
use App\Services\Riot\DataDragonService;
use App\Services\Riot\MasteryService;
use App\Services\Stats\LpHistory;
use App\Services\Stats\PairStats;
use App\Services\Stats\PerformanceStats;
use App\Services\Stats\PerformanceTrend;
use App\Services\Stats\RankGoal;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlayerController extends Controller
{
    private const RECENT_MATCHES = 20;

    private const TOP_CHAMPIONS = 5;

    public function __invoke(Request $request, Player $player, PerformanceStats $stats, LpHistory $lpHistory, PairStats $pairStats, PerformanceTrend $trend, AchievementTracker $achievementTracker, RankGoal $rankGoal): View
    {
        // Null = every tracked game mode together.
        $queue = MatchQueue::fromSlug($request->query('queue'));

        // Only Solo/Duo and Flex have LP. Other views still show the Solo/Duo rank in the header.
        $rankQueue = $queue?->ranked();
        $headerQueue = $rankQueue ?? RankedQueue::Solo;

        $partners = $pairStats->forPlayer($player->id, $queue);

        return view('players.show', [
            'partners' => $partners,
            'partnerPlayers' => Player::query()->whereIn('id', array_column($partners, 'player_id'))->get()->keyBy('id'),
            'player' => $player,
            'queue' => $queue,
            'queueLabel' => $queue?->label() ?? 'Semua mode',
            'queueParam' => $queue?->slug() ?? 'all',
            'queueOptions' => MatchQueue::tracked(),
            'rankQueue' => $rankQueue,
            'headerQueue' => $headerQueue,
            'current' => $player->rankSnapshots()
                ->where('queue_type', $headerQueue->value)
                ->orderByDesc('captured_at')
                ->orderByDesc('id')
                ->first(),
            'stats' => $stats->forPlayer($player->id, $queue),
            'trend' => $trend->forPlayer($player->id, $queue),
            'achievements' => $this->achievements($player, $achievementTracker),
            'goal' => $rankGoal->progress($player),
            'masteries' => $masteries = $player->masteries()->orderByDesc('points')->limit(MasteryService::TOP)->get(),
            // Only needed (and only fetched from Data Dragon) when there is something to name.
            'championNames' => $masteries->isEmpty() ? [] : app(DataDragonService::class)->championNames(),
            'liveGame' => $player->liveGame()->current()->first(),
            'topChampions' => $stats->topChampions($player->id, $queue, self::TOP_CHAMPIONS),
            'roles' => $stats->roles($player->id, $queue),
            'recentMatches' => $stats->recentMatches($player->id, $queue, self::RECENT_MATCHES),
            'lpChart' => $rankQueue ? $lpHistory->chartData(collect([$player]), $rankQueue) : null,
        ]);
    }

    /**
     * Every achievement with the moment it was earned (null = still locked), earned ones first.
     *
     * @return list<array{code: string, name: string, description: string, icon: string, earned_at: CarbonInterface|null}>
     */
    private function achievements(Player $player, AchievementTracker $tracker): array
    {
        $earned = $tracker->evaluate($player);

        return collect(AchievementCatalog::all())
            ->map(fn (array $achievement, string $code) => ['code' => $code, 'earned_at' => $earned[$code] ?? null] + $achievement)
            ->sortBy(fn (array $a) => $a['earned_at']?->getTimestamp() === null ? PHP_INT_MAX : -$a['earned_at']->getTimestamp())
            ->values()
            ->all();
    }
}
