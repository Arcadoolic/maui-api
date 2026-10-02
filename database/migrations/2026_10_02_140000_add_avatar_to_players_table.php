<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Player avatars (docs/DECISIONS.md D53): the PNG is stored on the `local`
// disk, avatars/<uuid>.png; its SHA-256 is its ETag and tells cabinets
// when to download it again.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->char('avatar_hash', 64)->nullable()->after('pin_locked_at');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('avatar_hash');
        });
    }
};
