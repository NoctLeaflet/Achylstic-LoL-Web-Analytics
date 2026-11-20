<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_participants', function (Blueprint $table) {
            // Running totals after every minute of the game, from the match timeline:
            // {"gold": [...], "damage": [...], "xp": [...], "cs": [...]}; index = minute.
            $table->json('metrics')->nullable()->after('skill_order');
        });

        // Timelines fetched before this column existed did not keep the per-minute numbers.
        // Mark those matches as not fetched, so riot:sync-timelines gets them again.
        DB::table('matches')
            ->whereNotNull('timeline_fetched_at')
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('match_participants')
                ->whereColumn('match_participants.match_id', 'matches.id')
                ->whereNotNull('match_participants.item_purchases'))
            ->update(['timeline_fetched_at' => null]);
    }

    public function down(): void
    {
        Schema::table('match_participants', function (Blueprint $table) {
            $table->dropColumn('metrics');
        });
    }
};
