<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A game can have several flyers (two sides, several regions): `position` orders the pictures of
 * one type, 0 being the one the pages showed alone until now (docs/DECISIONS.md D73).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_media', function (Blueprint $table) {
            $table->unsignedSmallInteger('position')->default(0)->after('type');
            $table->dropUnique(['game_id', 'type']);
            $table->unique(['game_id', 'type', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('game_media', function (Blueprint $table) {
            $table->dropUnique(['game_id', 'type', 'position']);
        });
        DB::table('game_media')->where('position', '>', 0)->delete();
        Schema::table('game_media', function (Blueprint $table) {
            $table->dropColumn('position');
            $table->unique(['game_id', 'type']);
        });
    }
};
