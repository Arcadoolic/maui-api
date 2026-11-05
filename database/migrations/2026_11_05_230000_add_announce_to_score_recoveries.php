<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The administrator may ask for a recovered score to be announced like a new
// one (docs/DECISIONS.md D93).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('score_recoveries', function (Blueprint $table) {
            $table->boolean('announce')->default(false)->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('score_recoveries', function (Blueprint $table) {
            $table->dropColumn('announce');
        });
    }
};
