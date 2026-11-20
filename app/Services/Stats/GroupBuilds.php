<?php

namespace App\Services\Stats;

use App\Enums\MatchQueue;
use App\Models\GameMatch;
use App\Models\MatchParticipant;
use App\Models\Player;
use App\Services\Riot\DataDragonService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The runes, items and skill order our own players use on a champion, with how
 * often they won with them. Only games of active tracked players count (never the
 * strangers in the same matches), remakes are ignored.
 *
 * The sample is small (the games of one friend group), so every number comes with
 * its game count and the page warns when it is thin.
 */
final class GroupBuilds
{
    /** Below this many games on a champion the numbers are just a first impression. */
    public const THIN_SAMPLE = 5;

    public const RUNE_PAGES_SHOWN = 3;

    public const ITEMS_SHOWN = 8;

    public function __construct(
        private readonly DataDragonService $ddragon,
        private readonly ParticipantBuild $builds,
    ) {}

    /**
     * Champions the members played, most played first.
     *
     * @return list<array{champion_id: int, name: string, games: int, wins: int, winrate: int}>
     */
    public function champions(?MatchQueue $queue = null, ?Player $player = null): array
    {
        return $this->games(null, $queue, $player)
            ->groupBy('champion_id')
            ->map(fn (Collection $rows) => [
                'champion_id' => (int) $rows->first()->champion_id,
                'name' => (string) $rows->first()->champion_name,
                'games' => $rows->count(),
                'wins' => $rows->where('win', true)->count(),
                'winrate' => (int) round($rows->where('win', true)->count() / $rows->count() * 100),
            ])
            ->sort(fn (array $a, array $b) => [$b['games'], $a['name']] <=> [$a['games'], $b['name']])
            ->values()
            ->all();
    }

    /**
     * @return array{champion_id: int, name: string, games: int, wins: int, winrate: int, kda: float, thin: bool, rune_pages: list<array<string, mixed>>, items: list<array<string, mixed>>, skills: array{priority: string, games: int}|null, players: list<array{player: Player, games: int, winrate: int}>}|null
     */
    public function forChampion(int $championId, ?MatchQueue $queue = null, ?Player $player = null): ?array
    {
        $games = $this->games($championId, $queue, $player);

        if ($games->isEmpty()) {
            return null;
        }

        $wins = $games->where('win', true)->count();

        return [
            'champion_id' => $championId,
            'name' => (string) $games->first()->champion_name,
            'games' => $games->count(),
            'wins' => $wins,
            'winrate' => (int) round($wins / $games->count() * 100),
            'kda' => round(($games->sum('kills') + $games->sum('assists')) / max((int) $games->sum('deaths'), 1), 2),
            'thin' => $games->count() < self::THIN_SAMPLE,
            'rune_pages' => $this->runePages($games),
            'items' => $this->items($games),
            'skills' => $this->skillPriority($games),
            'players' => $games->groupBy('player_id')
                ->map(fn (Collection $rows) => [
                    'player' => $rows->first()->player,
                    'games' => $rows->count(),
                    'winrate' => (int) round($rows->where('win', true)->count() / $rows->count() * 100),
                ])
                ->sort(fn (array $a, array $b) => [$b['games'], $a['player']->name] <=> [$a['games'], $b['player']->name])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int, MatchParticipant>
     */
    private function games(?int $championId, ?MatchQueue $queue, ?Player $player): Collection
    {
        return MatchParticipant::query()
            ->join('matches', 'matches.id', '=', 'match_participants.match_id')
            ->join('players', 'players.id', '=', 'match_participants.player_id')
            ->where('players.is_active', true)
            ->where('matches.game_duration', '>=', GameMatch::REMAKE_MAX_DURATION_SECONDS)
            ->when($championId !== null, fn (Builder $q) => $q->where('match_participants.champion_id', $championId))
            ->when($queue !== null, fn (Builder $q) => $q->where('matches.queue_id', $queue->queueId()))
            ->when($player !== null, fn (Builder $q) => $q->where('match_participants.player_id', $player->id))
            ->select('match_participants.*')
            ->with('player')
            ->get();
    }

    /**
     * The most used complete rune pages (keystone and all picks, both trees, shards).
     *
     * @param  Collection<int, MatchParticipant>  $games
     * @return list<array<string, mixed>>
     */
    private function runePages(Collection $games): array
    {
        $trees = $this->ddragon->runeTrees();
        $runes = [];

        foreach ($trees as $tree) {
            foreach ($tree['slots'] as $slot) {
                foreach ($slot as $rune) {
                    $runes[$rune['id']] = ['name' => $rune['name'], 'icon' => $this->ddragon->perkImage($rune['icon'])];
                }
            }
        }

        return $games
            ->filter(fn (MatchParticipant $p) => ! empty($p->perks['styles']))
            ->groupBy(fn (MatchParticipant $p) => json_encode($this->pageKey($p->perks)))
            ->map(function (Collection $rows) use ($trees, $runes) {
                $key = $this->pageKey($rows->first()->perks);
                $icon = fn (int $id) => $runes[$id] ?? ['name' => "Rune {$id}", 'icon' => null];
                $tree = fn (int $id) => isset($trees[$id])
                    ? ['name' => $trees[$id]['name'], 'icon' => $this->ddragon->perkImage($trees[$id]['icon'])]
                    : ['name' => "Tree {$id}", 'icon' => null];

                return [
                    'games' => $rows->count(),
                    'winrate' => (int) round($rows->where('win', true)->count() / $rows->count() * 100),
                    'primary' => $tree($key['primary_style']) + ['runes' => array_map($icon, $key['primary'])],
                    'secondary' => $tree($key['secondary_style']) + ['runes' => array_map($icon, $key['secondary'])],
                    'shards' => array_map(fn (int $id) => ['name' => $this->shardName($id)], $key['shards']),
                ];
            })
            ->sort(fn (array $a, array $b) => [$b['games'], $b['winrate']] <=> [$a['games'], $a['winrate']])
            ->take(self::RUNE_PAGES_SHOWN)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $perks
     * @return array{primary_style: int, primary: list<int>, secondary_style: int, secondary: list<int>, shards: list<int>}
     */
    private function pageKey(array $perks): array
    {
        $ids = fn (array $style) => array_map(fn (array $s) => (int) $s['perk'], $style['selections'] ?? []);

        return [
            'primary_style' => (int) ($perks['styles'][0]['style'] ?? 0),
            'primary' => $ids($perks['styles'][0] ?? []),
            'secondary_style' => (int) ($perks['styles'][1]['style'] ?? 0),
            'secondary' => $ids($perks['styles'][1] ?? []),
            'shards' => array_values(array_map('intval', [$perks['statPerks']['offense'] ?? 0, $perks['statPerks']['flex'] ?? 0, $perks['statPerks']['defense'] ?? 0])),
        ];
    }

    private function shardName(int $id): string
    {
        return [
            5001 => 'Health Scaling', 5002 => 'Armor', 5003 => 'Magic Resist', 5005 => 'Attack Speed', 5007 => 'Ability Haste',
            5008 => 'Adaptive Force', 5010 => 'Move Speed', 5011 => 'Health', 5013 => 'Tenacity and Slow Resist',
        ][$id] ?? "Shard {$id}";
    }

    /**
     * The final items the players had, most common first, with the winrate of the games that had them.
     *
     * @param  Collection<int, MatchParticipant>  $games
     * @return list<array{id: int, name: string, icon: string|null, games: int, winrate: int}>
     */
    private function items(Collection $games): array
    {
        $catalogue = $this->ddragon->items();
        $stats = [];

        foreach ($games as $game) {
            foreach (array_unique(array_filter(array_map('intval', $game->items ?? []))) as $itemId) {
                $known = $catalogue[$itemId] ?? null;

                // Potions, wards and trinkets say nothing about a build.
                if ($known === null || $known['skip'] || $known['name'] === '') {
                    continue;
                }

                $stats[$itemId]['games'] = ($stats[$itemId]['games'] ?? 0) + 1;
                $stats[$itemId]['wins'] = ($stats[$itemId]['wins'] ?? 0) + ($game->win ? 1 : 0);
            }
        }

        return collect($stats)
            ->map(fn (array $s, int $id) => [
                'id' => $id,
                'name' => $catalogue[$id]['name'],
                'icon' => $this->ddragon->itemIcon($id),
                'games' => $s['games'],
                'winrate' => (int) round($s['wins'] / $s['games'] * 100),
            ])
            ->sort(fn (array $a, array $b) => [$b['games'], $a['name']] <=> [$a['games'], $b['name']])
            ->take(self::ITEMS_SHOWN)
            ->values()
            ->all();
    }

    /**
     * The skill order most players used ("Q > E > W"), from games with a fetched timeline.
     *
     * @param  Collection<int, MatchParticipant>  $games
     * @return array{priority: string, games: int}|null
     */
    private function skillPriority(Collection $games): ?array
    {
        $counts = $games
            ->filter(fn (MatchParticipant $p) => ! empty($p->skill_order))
            ->map(fn (MatchParticipant $p) => implode(' > ', $this->builds->build($p)['skills']['priority'] ?? []))
            ->filter()
            ->countBy()
            ->sortDesc();

        return $counts->isEmpty() ? null : ['priority' => (string) $counts->keys()->first(), 'games' => (int) $counts->first()];
    }
}
