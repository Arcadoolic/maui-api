<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_startups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('mame_version', 32);
            $table->string('maui_version', 32);
            $table->string('os', 16);
            $table->string('os_version', 64);
            $table->timestampTz('client_datetime');
            $table->timestampTz('received_at');

            $table->index(['client_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_startups');
    }
};
