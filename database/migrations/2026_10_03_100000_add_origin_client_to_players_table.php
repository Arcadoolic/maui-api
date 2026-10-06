<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The cabinet a player was created on (docs/DECISIONS.md D54): the only one
// that may issue a new PIN. Null when that cabinet is gone, and then only
// admins can. Existing players: see the next migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->foreignId('origin_client_id')->nullable()->after('status')->constrained('clients')->nullOnDelete();
        });

    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_client_id');
        });
    }
};
