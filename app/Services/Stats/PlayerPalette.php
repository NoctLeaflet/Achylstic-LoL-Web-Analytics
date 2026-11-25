<?php

namespace App\Services\Stats;

use App\Models\Player;

/**
 * One fixed colour per player, used by every chart so a player is always
 * recognisable. Colours follow the order of player ids, so adding a player
 * never changes the colour of an existing one. The list is ordered so that
 * neighbours differ clearly in hue, and it is long enough that a group of up
 * to 16 friends never shares a colour (look-alike pairs such as pink / rose /
 * fuchsia or sky / cyan were removed).
 */
final class PlayerPalette
{
    /** @var list<string> bright enough for a dark background */
    public const COLORS = [
        '#38bdf8', // sky
        '#f472b6', // pink
        '#a3e635', // lime
        '#facc15', // yellow
        '#8b5cf6', // violet
        '#22c55e', // green
        '#ef4444', // red
        '#fb923c', // orange
        '#d946ef', // fuchsia
        '#2dd4bf', // teal
        '#e2e8f0', // white
        '#d6b88a', // tan
        '#3b82f6', // blue
        '#fda4af', // salmon
        '#a5b4fc', // light indigo
        '#94a3b8', // slate
    ];

    /** @var list<int>|null */
    private ?array $order = null;

    public function colorFor(int $playerId): string
    {
        $this->order ??= Player::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $index = array_search($playerId, $this->order, true);

        return self::COLORS[($index === false ? $playerId : $index) % count(self::COLORS)];
    }
}
