<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What each cabinet thinks of a game and how much it is played there
// (docs/DECISIONS.md D75): one row per cabinet and game, replaced by each
// report of the cabinet.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_opinions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->restrictOnDelete();
            // The cabinet's vote: 1 thumbs up, 0 neutral or not voted, -1 thumbs down.
            $table->smallInteger('vote')->default(0);
            // Games started on this cabinet since it counts them: a total, not an increment.
            $table->unsignedInteger('play_count')->default(0);
            $table->timestampTz('last_played_at')->nullable();
            // When this server first saw the current vote; null while it is neutral.
            $table->timestampTz('voted_at')->nullable();
            $table->timestampsTz();

            $table->unique(['client_id', 'game_id']);
            $table->index(['game_id', 'vote']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_opinions');
    }
};
