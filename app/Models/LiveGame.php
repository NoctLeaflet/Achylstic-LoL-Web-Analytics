<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A game one of our players is playing right now (from Riot's spectator API).
 */
#[Fillable(['player_id', 'game_id', 'queue_id', 'champion_id', 'participants', 'bans', 'started_at', 'last_seen_at', 'announced_at'])]
class LiveGame extends Model
{
    /** A row not refreshed for this long is stale (the sync stopped, or the game ended unnoticed). */
    public const STALE_AFTER_MINUTES = 10;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'game_id' => 'integer',
            'queue_id' => 'integer',
            'champion_id' => 'integer',
            'participants' => 'array',
            'bans' => 'array',
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'announced_at' => 'datetime',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** Games the sync saw recently. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('last_seen_at', '>=', now()->subMinutes(self::STALE_AFTER_MINUTES));
    }
}
