<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->string('game_name', 64);
            $table->string('tag_line', 16);
            $table->string('puuid', 100)->unique();
            // Platform routing of this player's account (sg2, ph2, th2, tw2, vn2, ...).
            $table->string('platform', 8);
            $table->string('display_name', 64)->nullable();
            $table->unsignedInteger('profile_icon_id')->nullable();
            $table->unsignedInteger('summoner_level')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
