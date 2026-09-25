<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Get the connection name for the migration.
     */
    public function getConnection(): string
    {
        return config('database.default');
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('public_key')->unique();
            $table->string('name')->unique();
            $table->string('email')->nullable()->unique();
            $table->text('note')->nullable();
            $table->enum('type', ['machine', 'human', 'service'])->default('machine');
            $table->enum('status', ['active', 'disabled', 'pending'])->default('active');
            $table->string('machine_fingerprint_hash')->nullable();
            $table->timestamp('bound_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
