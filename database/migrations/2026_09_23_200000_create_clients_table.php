<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('public_key', 27)->unique();
            $table->string('name')->unique();
            $table->string('email')->unique();
            $table->text('notes')->nullable();
            $table->string('type', 16);
            $table->string('status', 16)->default('active');
            $table->char('machine_fingerprint_hash', 64)->nullable();
            $table->timestampTz('bound_at')->nullable();
            $table->timestampTz('last_heartbeat_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_type_check CHECK (type IN ('maui', 'service'))");
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_status_check CHECK (status IN ('active', 'disabled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
