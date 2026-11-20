<?php

namespace App\Services\Achievements;

/**
 * Every achievement a player can earn. Each is computed from data we already
 * store (games and rank snapshots), see AchievementTracker.
 */
final class AchievementCatalog
{
    /** Tiers that give an achievement, lowest first. */
    public const TIERS = ['GOLD' => 'Gold', 'PLATINUM' => 'Platinum', 'EMERALD' => 'Emerald', 'DIAMOND' => 'Diamond'];

    /**
     * @return array<string, array{name: string, description: string, icon: string}> keyed by code
     */
    public static function all(): array
    {
        return [
            'games_100' => ['name' => 'Veteran', 'description' => 'Main 100 game', 'icon' => '🎖️'],
            'games_500' => ['name' => 'Legenda', 'description' => 'Main 500 game', 'icon' => '🏛️'],
            'win_streak_10' => ['name' => 'Tak Terhentikan', 'description' => 'Menang 10 game berturut-turut', 'icon' => '🔥'],
            'deathless' => ['name' => 'Tak Tersentuh', 'description' => 'Menang tanpa mati dengan minimal 10 takedown', 'icon' => '🛡️'],
            'kills_20' => ['name' => 'Pembantai', 'description' => '20 kill dalam satu game', 'icon' => '⚔️'],
            'assists_25' => ['name' => 'Rekan Setia', 'description' => '25 assist dalam satu game', 'icon' => '🤝'],
            'vision_100' => ['name' => 'Mata Elang', 'description' => 'Vision score 100 dalam satu game', 'icon' => '👁️'],
            'one_trick_50' => ['name' => 'Spesialis', 'description' => '50 game dengan satu champion', 'icon' => '🎯'],
            'champions_20' => ['name' => 'Serba Bisa', 'description' => 'Memainkan 20 champion berbeda', 'icon' => '🎭'],
            'tier_gold' => ['name' => 'Gold', 'description' => 'Mencapai tier Gold', 'icon' => '🥇'],
            'tier_platinum' => ['name' => 'Platinum', 'description' => 'Mencapai tier Platinum', 'icon' => '💠'],
            'tier_emerald' => ['name' => 'Emerald', 'description' => 'Mencapai tier Emerald', 'icon' => '💚'],
            'tier_diamond' => ['name' => 'Diamond', 'description' => 'Mencapai tier Diamond atau lebih tinggi', 'icon' => '💎'],
        ];
    }
}
