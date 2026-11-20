<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's champion mastery (level and points) for one champion, from champion-mastery-v4.
 */
#[Fillable(['player_id', 'champion_id', 'level', 'points', 'last_played_at'])]
class ChampionMastery extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'champion_id' => 'integer',
            'level' => 'integer',
            'points' => 'integer',
            'last_played_at' => 'datetime',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
