<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A finished match. Named GameMatch because `Match` is a reserved word in PHP 8.
 */
#[Table('matches')]
#[Fillable([
    'match_id',
    'queue_id',
    'game_version',
    'game_mode',
    'game_duration',
    'game_start_at',
])]
class GameMatch extends Model
{
    /** Matches shorter than this (in seconds) are remakes and ignored in statistics. */
    public const REMAKE_MAX_DURATION_SECONDS = 300;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'queue_id' => 'integer',
            'game_duration' => 'integer',
            'game_start_at' => 'datetime',
        ];
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MatchParticipant::class, 'match_id');
    }

    public function isRemake(): bool
    {
        return $this->game_duration < self::REMAKE_MAX_DURATION_SECONDS;
    }

    /** Exclude remakes (used by all statistics). */
    public function scopeWithoutRemakes(Builder $query): Builder
    {
        return $query->where('game_duration', '>=', self::REMAKE_MAX_DURATION_SECONDS);
    }
}
