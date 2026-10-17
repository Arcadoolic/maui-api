<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The cabinet's report on the game a score was made in, and what it says of
// the score (docs/DECISIONS.md D87). Null for the cabinets sending no report.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            $table->jsonb('integrity')->nullable();
            $table->string('integrity_verdict', 16)->nullable()->index();
            $table->jsonb('integrity_reasons')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            $table->dropColumn(['integrity', 'integrity_verdict', 'integrity_reasons']);
        });
    }
};
