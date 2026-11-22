<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_games', function (Blueprint $table) {
            // All ten players of the running game, as the spectator API lists them:
            // [{"puuid": "...", "riot_id": "Name#TAG", "champion_id": 103, "team_id": 100}, ...]
            $table->json('participants')->nullable()->after('champion_id');
            // Banned champions: [{"champion_id": 238, "team_id": 100}, ...]
            $table->json('bans')->nullable()->after('participants');
        });
    }

    public function down(): void
    {
        Schema::table('live_games', function (Blueprint $table) {
            $table->dropColumn(['participants', 'bans']);
        });
    }
};
