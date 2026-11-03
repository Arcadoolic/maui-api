<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the pictures of a game are chosen, set by hand in the back office (docs/DECISIONS.md D99):
 * another ScreenScraper sheet for the types the game has none of, and a region put first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_details', function (Blueprint $table) {
            $table->unsignedInteger('media_donor_id')->nullable()->after('screenscraper_id');
            $table->string('media_region', 8)->nullable()->after('media_donor_id');
        });
        Schema::table('game_media', function (Blueprint $table) {
            $table->boolean('donor')->default(false)->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('game_media', function (Blueprint $table) {
            $table->dropColumn('donor');
        });
        Schema::table('game_details', function (Blueprint $table) {
            $table->dropColumn(['media_donor_id', 'media_region']);
        });
    }
};
