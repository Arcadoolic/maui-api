<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// How the cabinet knew whose score it was (docs/DECISIONS.md D61): `initials`
// read from the game's file, or `declared` on the cabinet for the games that
// write no name.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            $table->string('attribution', 16)->default('initials');
        });
    }

    public function down(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            $table->dropColumn('attribution');
        });
    }
};
