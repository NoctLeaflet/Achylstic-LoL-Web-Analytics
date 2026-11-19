<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'match_id',
    'player_id',
    'puuid',
    'riot_id_name',
    'riot_id_tag',
    'champion_id',
    'champion_name',
    'team_id',
    'team_position',
    'win',
    'kills',
    'deaths',
    'assists',
    'total_minions_killed',
    'neutral_minions_killed',
    'gold_earned',
    'total_damage_dealt_to_champions',
    'damage_taken',
    'vision_score',
    'wards_placed',
    'items',
    'summoner_spells',
    'perks',
])]
#[WithoutTimestamps]
class MatchParticipant extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'champion_id' => 'integer',
            'team_id' => 'integer',
            'win' => 'boolean',
            'kills' => 'integer',
            'deaths' => 'integer',
            'assists' => 'integer',
            'total_minions_killed' => 'integer',
            'neutral_minions_killed' => 'integer',
            'gold_earned' => 'integer',
            'total_damage_dealt_to_champions' => 'integer',
            'damage_taken' => 'integer',
            'vision_score' => 'integer',
            'wards_placed' => 'integer',
            'items' => 'array',
            'summoner_spells' => 'array',
            'perks' => 'array',
        ];
    }

    public function gameMatch(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    /** Only set when this participant is one of our tracked players. */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
