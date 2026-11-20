<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('champion_masteries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('champion_id');
            $table->unsignedSmallInteger('level');
            $table->unsignedInteger('points');
            $table->timestamp('last_played_at')->nullable();
            $table->timestamps();

            $table->unique(['player_id', 'champion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('champion_masteries');
    }
};
