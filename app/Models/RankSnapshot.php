<?php

namespace App\Models;

use App\Enums\RankedQueue;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'player_id',
    'queue_type',
    'tier',
    'rank',
    'league_points',
    'wins',
    'losses',
    'captured_at',
])]
#[WithoutTimestamps]
class RankSnapshot extends Model
{
    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'queue_type' => RankedQueue::class,
            'league_points' => 'integer',
            'wins' => 'integer',
            'losses' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
