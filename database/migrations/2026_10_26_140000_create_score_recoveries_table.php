<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Requests to recover a score the cabinet's file holds but MAUI-API never
// got, created by an administrator and answered by the cabinet
// (docs/DECISIONS.md D93).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_recoveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('romname', 32);
            $table->string('table', 32)->default('default');
            $table->unsignedBigInteger('score');
            // When the game was played, as the administrator found out: the score's date.
            $table->timestampTz('achieved_at');
            $table->text('reason');
            $table->foreignId('diagnostic_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('pending');
            // What the cabinet answered: the table it read, a rejection code.
            $table->jsonb('result')->nullable();
            $table->foreignId('score_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_recoveries');
    }
};
