<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_games', function (Blueprint $table) {
            $table->id();
            // One row per player who is in a game right now; deleted when the game is over.
            $table->foreignId('player_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('game_id');
            $table->unsignedInteger('queue_id')->nullable();
            $table->unsignedInteger('champion_id')->nullable();
            $table->timestamp('started_at');
            // Updated at every poll; a row nobody refreshed for a while is ignored by the pages.
            $table->timestamp('last_seen_at');
            // Set when Discord was told (or the game was already running for too long to be news).
            $table->timestamp('announced_at')->nullable();
            $table->timestamps();

            $table->index('game_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_games');
    }
};
