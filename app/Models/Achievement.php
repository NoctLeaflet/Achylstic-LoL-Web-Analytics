<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['player_id', 'code', 'earned_at', 'announced_at'])]
class Achievement extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['earned_at' => 'datetime', 'announced_at' => 'datetime'];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
