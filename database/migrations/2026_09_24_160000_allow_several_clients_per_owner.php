<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// One owner, several cabinets: the email is a contact, not an identifier
// (docs/DECISIONS.md D38).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->index('email');
            // Temporary default only to fill existing rows; the form requires it.
            $table->string('owner_name')->default('')->after('name');
        });

        DB::statement('ALTER TABLE clients ALTER COLUMN owner_name DROP DEFAULT');
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('owner_name');
            $table->dropIndex(['email']);
            $table->unique('email');
        });
    }
};
