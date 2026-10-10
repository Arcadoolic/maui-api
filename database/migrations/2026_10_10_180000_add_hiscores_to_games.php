<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whether a cabinet can read the game's hiscores (docs/DECISIONS.md D74): false until a
        // catalog push says otherwise.
        Schema::table('games', function (Blueprint $table) {
            $table->boolean('hiscores')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('hiscores');
        });
    }
};
