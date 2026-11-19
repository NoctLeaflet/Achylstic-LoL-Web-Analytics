<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            // Set once the match-v5 timeline was fetched (or Riot no longer has it),
            // so a match is never asked for twice.
            $table->timestamp('timeline_fetched_at')->nullable()->index()->after('game_start_at');
        });

        Schema::table('match_participants', function (Blueprint $table) {
            // [[seconds, itemId], ...] in purchase order, undone purchases removed.
            $table->json('item_purchases')->nullable()->after('perks');
            // Skill slots in the order they were levelled: 1 = Q, 2 = W, 3 = E, 4 = R.
            $table->json('skill_order')->nullable()->after('item_purchases');
        });
    }

    public function down(): void
    {
        Schema::table('match_participants', function (Blueprint $table) {
            $table->dropColumn(['item_purchases', 'skill_order']);
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('timeline_fetched_at');
        });
    }
};
