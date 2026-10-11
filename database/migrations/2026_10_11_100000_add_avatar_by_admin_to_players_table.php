<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// An administrator may change a player's avatar (docs/DECISIONS.md D83):
// whether the current one comes from an administrator, which the cabinet the
// player was created on must take instead of sending its own again.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->boolean('avatar_by_admin')->default(false)->after('avatar_hash');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('avatar_by_admin');
        });
    }
};
