<?php

namespace App\Services\Achievements;

use App\Models\Achievement;
use App\Models\GameMatch;
use App\Models\MatchParticipant;
use App\Models\Player;
use App\Models\RankSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Works out which achievements a player has earned from their stored games and
 * rank snapshots, remembers them in the achievements table, and says which are
 * new.
 *
 * evaluate() is pure: code => the moment it was earned. sync() stores what is
 * missing. A new achievement is "news" only when it was earned recently
 * (services.discord.max_match_age_minutes); older ones, such as everything the
 * history already contained the first time this runs, are stored as announced so
 * Discord is never flooded.
 */
final class AchievementTracker
{
    private const APEX = ['MASTER', 'GRANDMASTER', 'CHALLENGER'];

    /**
     * @return array<string, CarbonInterface> code => when it was earned
     */
    public function evaluate(Player $player): array
    {
        $earned = [];

        $games = MatchParticipant::query()
            ->join('matches', 'matches.id', '=', 'match_participants.match_id')
            ->where('match_participants.player_id', $player->id)
            ->where('matches.game_duration', '>=', GameMatch::REMAKE_MAX_DURATION_SECONDS)
            ->orderBy('matches.game_start_at')
            ->orderBy('matches.id')
            ->get(['match_participants.win', 'match_participants.kills', 'match_participants.deaths', 'match_participants.assists',
                'match_participants.vision_score', 'match_participants.champion_id', 'matches.game_start_at', 'matches.game_duration']);

        $streak = 0;
        $perChampion = [];
        $count = 0;

        foreach ($games as $game) {
            $count++;
            $at = Carbon::parse($game->game_start_at)->addSeconds((int) $game->game_duration);
            $mark = function (bool $condition, string $code) use (&$earned, $at) {
                if ($condition) {
                    $earned[$code] ??= $at;
                }
            };

            $mark($count >= 100, 'games_100');
            $mark($count >= 500, 'games_500');

            $streak = $game->win ? $streak + 1 : 0;
            $mark($streak >= 10, 'win_streak_10');

            $mark($game->win && (int) $game->deaths === 0 && $game->kills + $game->assists >= 10, 'deathless');
            $mark($game->kills >= 20, 'kills_20');
            $mark($game->assists >= 25, 'assists_25');
            $mark($game->vision_score >= 100, 'vision_100');

            $perChampion[$game->champion_id] = ($perChampion[$game->champion_id] ?? 0) + 1;
            $mark($perChampion[$game->champion_id] >= 50, 'one_trick_50');
            $mark(count($perChampion) >= 20, 'champions_20');
        }

        $this->tiers($player, $earned);

        return $earned;
    }

    /**
     * Store the achievements the player has earned but we do not know yet.
     *
     * @return list<Achievement> the new ones that are news (to announce); the rest are stored as already announced
     */
    public function sync(Player $player): array
    {
        $known = Achievement::where('player_id', $player->id)->pluck('code')->all();
        $news = [];
        $freshAfter = now()->subMinutes((int) config('services.discord.max_match_age_minutes'));

        foreach ($this->evaluate($player) as $code => $at) {
            if (in_array($code, $known, true)) {
                continue;
            }

            $fresh = $at->gte($freshAfter);
            $achievement = Achievement::create([
                'player_id' => $player->id,
                'code' => $code,
                'earned_at' => $at,
                'announced_at' => $fresh ? null : now(),
            ]);

            if ($fresh) {
                $news[] = $achievement;
            }
        }

        return $news;
    }

    /**
     * @param  list<Achievement>  $achievements
     */
    public function markAnnounced(array $achievements): void
    {
        foreach ($achievements as $achievement) {
            $achievement->update(['announced_at' => now()]);
        }
    }

    /**
     * @param  array<string, CarbonInterface>  $earned
     */
    private function tiers(Player $player, array &$earned): void
    {
        $order = array_merge(array_keys(AchievementCatalog::TIERS), self::APEX);

        $snapshots = RankSnapshot::where('player_id', $player->id)->orderBy('captured_at')->orderBy('id')->get(['tier', 'captured_at']);

        foreach ($snapshots as $snapshot) {
            $index = array_search($snapshot->tier, $order, true);

            if ($index === false) {
                continue;   // below Gold
            }

            foreach (array_keys(AchievementCatalog::TIERS) as $i => $tier) {
                if ($index >= $i) {
                    $earned['tier_'.strtolower($tier)] ??= Carbon::parse($snapshot->captured_at);
                }
            }
        }
    }
}
