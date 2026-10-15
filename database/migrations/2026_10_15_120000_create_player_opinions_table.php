<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What a player thinks of a game, given on the hiscores front by its member
// (docs/DECISIONS.md D86): one row per player and game, none while neutral.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_opinions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->restrictOnDelete();
            // 1 thumbs up, -1 thumbs down: a neutral vote removes the row.
            $table->smallInteger('vote');
            // When the current vote was given.
            $table->timestampTz('voted_at');
            $table->timestampsTz();

            $table->unique(['player_id', 'game_id']);
            $table->index(['game_id', 'vote']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_opinions');
    }
};
