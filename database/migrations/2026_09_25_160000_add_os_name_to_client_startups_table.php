<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Readable OS name sent by MAUI (e.g. "Ubuntu 24.04.5 LTS"), optional: older
// MAUI versions do not send it (docs/DECISIONS.md D42).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_startups', function (Blueprint $table) {
            $table->string('os_name', 64)->nullable()->after('os_version');
        });
    }

    public function down(): void
    {
        Schema::table('client_startups', function (Blueprint $table) {
            $table->dropColumn('os_name');
        });
    }
};
