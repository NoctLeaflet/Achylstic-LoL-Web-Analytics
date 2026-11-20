<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            // Key of the achievement in App\Services\Achievements\AchievementCatalog.
            $table->string('code', 40);
            // When the player actually earned it (the game or rank snapshot that did it).
            $table->timestamp('earned_at');
            // Set when Discord was told, or when it was too old to be news.
            $table->timestamp('announced_at')->nullable();
            $table->timestamps();

            $table->unique(['player_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements');
    }
};
