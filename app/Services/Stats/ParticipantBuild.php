<?php

namespace App\Services\Stats;

use App\Models\MatchParticipant;
use App\Services\Riot\DataDragonService;

/**
 * What the build panel of a player shows: the items they bought over time, the
 * order they levelled their skills, and their runes.
 *
 * Items and skills come from the match timeline (null until it was fetched);
 * runes come from the match itself. Names and images come from Data Dragon;
 * when that is unreachable the panel degrades instead of failing.
 */
final class ParticipantBuild
{
    /** Skill slot (as stored) => key. */
    private const SKILL_KEYS = [1 => 'Q', 2 => 'W', 3 => 'E', 4 => 'R'];

    /** A champion's ultimate is levelled at 6, 11 and 16 only; a basic skill has at most 5 points. */
    private const MAX_BASIC_SKILL_LEVEL = 5;

    /** Rune shard id => [name, image file]. */
    private const SHARDS = [
        5001 => ['Health Scaling', 'StatModsHealthScalingIcon'],
        5002 => ['Armor', 'StatModsArmorIcon'],
        5003 => ['Magic Resist', 'StatModsMagicResIcon'],
        5005 => ['Attack Speed', 'StatModsAttackSpeedIcon'],
        5007 => ['Ability Haste', 'StatModsCDRScalingIcon'],
        5008 => ['Adaptive Force', 'StatModsAdaptiveForceIcon'],
        5010 => ['Move Speed', 'StatModsMovementSpeedIcon'],
        5011 => ['Health', 'StatModsHealthPlusIcon'],
        5013 => ['Tenacity and Slow Resist', 'StatModsTenacityIcon'],
    ];

    /** Used when the item list is unavailable: health potions, control ward, trinkets. */
    private const FALLBACK_SKIPPED_ITEMS = [2003, 2031, 2033, 2055, 3330, 3340, 3363, 3364];

    public function __construct(private readonly DataDragonService $ddragon) {}

    /**
     * @return array{
     *     has_timeline: bool,
     *     items: list<array{minute: int, items: list<array{id: int, name: string|null}>}>,
     *     skills: array{grid: array<string, list<int>>, priority: list<string>, levels: int}|null,
     *     runes: array{primary: array<string, mixed>|null, secondary: array<string, mixed>|null, shards: list<array{name: string, icon: string|null}>}|null
     * }
     */
    public function build(MatchParticipant $participant): array
    {
        return [
            'has_timeline' => $participant->item_purchases !== null,
            'items' => $this->items($participant->item_purchases ?? []),
            'skills' => $this->skills($participant->skill_order ?? []),
            'runes' => $this->runes($participant->perks),
        ];
    }

    /**
     * Purchases grouped by the minute they happened in ("1m" covers the first minute),
     * without potions, wards and trinkets.
     *
     * @param  list<array{0: int, 1: int}>  $purchases
     * @return list<array{minute: int, items: list<array{id: int, name: string|null}>}>
     */
    private function items(array $purchases): array
    {
        $catalogue = $this->ddragon->items();
        $groups = [];

        foreach ($purchases as [$seconds, $itemId]) {
            $known = $catalogue[$itemId] ?? null;
            $skip = $known !== null ? $known['skip'] : in_array($itemId, self::FALLBACK_SKIPPED_ITEMS, true);

            if ($skip) {
                continue;
            }

            $minute = max(1, (int) ceil($seconds / 60));
            $groups[$minute][] = ['id' => $itemId, 'name' => $known['name'] ?? null];
        }

        ksort($groups);

        return array_map(fn (int $minute, array $items) => ['minute' => $minute, 'items' => $items], array_keys($groups), $groups);
    }

    /**
     * The levels at which each skill was taken, and which basic skills were
     * maxed first ("Q > W > E").
     *
     * @param  list<int>  $order  skill slots in levelling order
     * @return array{grid: array<string, list<int>>, priority: list<string>, levels: int}|null
     */
    private function skills(array $order): ?array
    {
        if ($order === []) {
            return null;
        }

        $grid = array_fill_keys(self::SKILL_KEYS, []);

        foreach ($order as $index => $slot) {
            $grid[self::SKILL_KEYS[$slot] ?? 'Q'][] = $index + 1;
        }

        // Maxing order: the basic skill that reaches 5 points first comes first;
        // skills that never got there are ordered by how many points they have.
        $basic = array_filter($grid, fn (string $key) => $key !== 'R', ARRAY_FILTER_USE_KEY);
        $priority = array_keys($basic);
        usort($priority, function (string $a, string $b) use ($basic) {
            $maxedA = $basic[$a][self::MAX_BASIC_SKILL_LEVEL - 1] ?? PHP_INT_MAX;
            $maxedB = $basic[$b][self::MAX_BASIC_SKILL_LEVEL - 1] ?? PHP_INT_MAX;

            return [$maxedA, -count($basic[$a])] <=> [$maxedB, -count($basic[$b])];
        });

        return ['grid' => $grid, 'priority' => $priority, 'levels' => max(18, count($order))];
    }

    /**
     * @param  array<string, mixed>|null  $perks  match-v5 perks of the participant
     * @return array{primary: array<string, mixed>|null, secondary: array<string, mixed>|null, shards: list<array{name: string, icon: string|null}>}|null
     */
    private function runes(?array $perks): ?array
    {
        if (empty($perks['styles'])) {
            return null;
        }

        $trees = $this->ddragon->runeTrees();

        return [
            'primary' => $this->tree($perks['styles'][0] ?? null, $trees, skipKeystoneRow: false),
            'secondary' => $this->tree($perks['styles'][1] ?? null, $trees, skipKeystoneRow: true),
            'shards' => $this->shards($perks['statPerks'] ?? []),
        ];
    }

    /**
     * One rune tree with the runes this player picked marked as selected.
     *
     * @param  array<string, mixed>|null  $style  one entry of perks.styles
     * @param  array<int, array<string, mixed>>  $trees
     * @return array<string, mixed>|null null when the tree is unknown (e.g. Data Dragon is down)
     */
    private function tree(?array $style, array $trees, bool $skipKeystoneRow): ?array
    {
        $tree = $trees[(int) ($style['style'] ?? 0)] ?? null;

        if ($tree === null) {
            return null;
        }

        $picked = array_map(fn (array $selection) => (int) $selection['perk'], $style['selections'] ?? []);
        $slots = $skipKeystoneRow ? array_slice($tree['slots'], 1) : $tree['slots'];

        return [
            'name' => $tree['name'],
            'icon' => $this->ddragon->perkImage($tree['icon']),
            'rows' => array_map(
                fn (array $slot) => array_map(fn (array $rune) => [
                    'id' => $rune['id'],
                    'name' => $rune['name'],
                    'icon' => $this->ddragon->perkImage($rune['icon']),
                    'selected' => in_array($rune['id'], $picked, true),
                ], $slot),
                $slots,
            ),
        ];
    }

    /**
     * @param  array<string, int>  $statPerks  offense, flex and defense shard ids
     * @return list<array{name: string, icon: string|null}>
     */
    private function shards(array $statPerks): array
    {
        $shards = [];

        foreach (['offense', 'flex', 'defense'] as $row) {
            $id = (int) ($statPerks[$row] ?? 0);

            if ($id === 0) {
                continue;
            }

            [$name, $file] = self::SHARDS[$id] ?? ["Rune shard {$id}", null];

            $shards[] = ['name' => $name, 'icon' => $file ? $this->ddragon->perkImage("perk-images/StatMods/{$file}.png") : null];
        }

        return $shards;
    }
}
