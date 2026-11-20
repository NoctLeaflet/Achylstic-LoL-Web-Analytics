<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'game_name',
    'tag_line',
    'puuid',
    'platform',
    'display_name',
    'profile_icon_id',
    'summoner_level',
    'is_active',
    'goal_tier',
    'goal_division',
])]
class Player extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'profile_icon_id' => 'integer',
            'summoner_level' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function rankSnapshots(): HasMany
    {
        return $this->hasMany(RankSnapshot::class);
    }

    public function participations(): HasMany
    {
        return $this->hasMany(MatchParticipant::class);
    }

    /** Nickname within the group, falling back to the Riot game name. */
    protected function name(): Attribute
    {
        return Attribute::get(fn () => $this->display_name ?: $this->game_name);
    }

    /** Full Riot ID, e.g. "Faker#KR1". */
    protected function riotId(): Attribute
    {
        return Attribute::get(fn () => "{$this->game_name}#{$this->tag_line}");
    }

    public function masteries(): HasMany
    {
        return $this->hasMany(ChampionMastery::class);
    }

    public function liveGame(): HasOne
    {
        return $this->hasOne(LiveGame::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
