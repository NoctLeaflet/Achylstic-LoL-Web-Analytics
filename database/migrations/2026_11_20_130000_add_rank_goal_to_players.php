<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            // The rank a player is aiming for in Solo/Duo, set by the admin. Division is "I".."IV",
            // and is kept as "I" for Master and above (they have no divisions).
            $table->string('goal_tier', 20)->nullable()->after('is_active');
            $table->string('goal_division', 4)->nullable()->after('goal_tier');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn(['goal_tier', 'goal_division']);
        });
    }
};
