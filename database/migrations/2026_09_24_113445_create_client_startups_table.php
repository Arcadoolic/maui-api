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
        Schema::create('client_startups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('mame_version');
            $table->string('maui_version');
            $table->string('os');
            $table->string('os_version');
            $table->timestamp('client_datetime');
            $table->timestamp('received_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_startups');
    }
};
