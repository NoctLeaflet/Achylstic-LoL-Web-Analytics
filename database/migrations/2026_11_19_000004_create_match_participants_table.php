<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_participants', function (Blueprint $table) {
            $table->id();
            // References matches.id (not the Riot match id string).
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            // Filled only when the participant is one of our tracked players.
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->string('puuid', 100)->index();
            $table->string('riot_id_name', 64)->nullable();
            $table->string('riot_id_tag', 16)->nullable();

            $table->unsignedInteger('champion_id');
            $table->string('champion_name', 32);
            $table->unsignedSmallInteger('team_id');
            $table->string('team_position', 16)->nullable();
            $table->boolean('win');

            $table->unsignedSmallInteger('kills');
            $table->unsignedSmallInteger('deaths');
            $table->unsignedSmallInteger('assists');
            $table->unsignedSmallInteger('total_minions_killed');
            $table->unsignedSmallInteger('neutral_minions_killed');
            $table->unsignedInteger('gold_earned');
            $table->unsignedInteger('total_damage_dealt_to_champions');
            $table->unsignedInteger('damage_taken');
            $table->unsignedSmallInteger('vision_score');
            $table->unsignedSmallInteger('wards_placed');

            $table->json('items')->nullable();
            $table->json('summoner_spells')->nullable();
            $table->json('perks')->nullable();

            // A participant appears only once per match.
            // (player_id and match_id are already indexed by their foreign keys.)
            $table->unique(['match_id', 'puuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_participants');
    }
};
