<?php

namespace App\Services\Stats;

use App\Models\Player;

/**
 * One fixed colour per player, used by every chart so a player is always
 * recognisable. Colours follow the order of player ids, so adding a player
 * never changes the colour of an existing one.
 */
final class PlayerPalette
{
    /** @var list<string> bright enough for a dark background */
    public const COLORS = [
        '#38bdf8', // sky
        '#f472b6', // pink
        '#a3e635', // lime
        '#fbbf24', // amber
        '#a78bfa', // violet
        '#34d399', // emerald
        '#fb7185', // rose
        '#22d3ee', // cyan
        '#fb923c', // orange
        '#e879f9', // fuchsia
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
