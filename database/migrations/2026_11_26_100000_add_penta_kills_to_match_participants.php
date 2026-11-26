<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_participants', function (Blueprint $table) {
            // Matches stored before this column existed stay at 0.
            $table->unsignedTinyInteger('penta_kills')->default(0)->after('assists');
        });
    }

    public function down(): void
    {
        Schema::table('match_participants', function (Blueprint $table) {
            $table->dropColumn('penta_kills');
        });
    }
};
