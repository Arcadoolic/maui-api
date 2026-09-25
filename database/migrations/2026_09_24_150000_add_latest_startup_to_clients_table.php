<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Direct reference to the latest startup: Eloquent latestOfMany() adds a
// MAX(id) tie-breaker, which PostgreSQL does not support on UUIDs
// (docs/DECISIONS.md D32).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignUuid('latest_startup_id')->nullable()->constrained('client_startups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('latest_startup_id');
        });
    }
};
