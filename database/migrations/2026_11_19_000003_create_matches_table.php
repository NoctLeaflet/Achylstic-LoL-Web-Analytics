<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            // Riot match id, e.g. SG2_123456789
            $table->string('match_id', 32)->unique();
            $table->unsignedSmallInteger('queue_id')->index();
            $table->string('game_version', 32)->nullable();
            $table->string('game_mode', 32)->nullable();
            // Duration in seconds.
            $table->unsignedInteger('game_duration');
            $table->timestamp('game_start_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
