<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            // RANKED_SOLO_5x5 or RANKED_FLEX_SR
            $table->string('queue_type', 32);
            $table->string('tier', 16);
            // Division (I - IV). Master and above are always reported as "I".
            $table->string('rank', 4);
            $table->unsignedSmallInteger('league_points');
            $table->unsignedSmallInteger('wins');
            $table->unsignedSmallInteger('losses');
            $table->timestamp('captured_at')->index();

            // Latest snapshot lookups and LP-over-time charts per player/queue.
            $table->index(['player_id', 'queue_type', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_snapshots');
    }
};
