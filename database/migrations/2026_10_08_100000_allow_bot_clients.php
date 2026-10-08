<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Bot accounts reading the shared leaderboards (docs/DECISIONS.md D57).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE clients DROP CONSTRAINT clients_type_check');
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_type_check CHECK (type IN ('maui', 'service', 'bot'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE clients DROP CONSTRAINT clients_type_check');
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_type_check CHECK (type IN ('maui', 'service'))");
    }
};
