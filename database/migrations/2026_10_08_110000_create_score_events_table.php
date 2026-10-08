<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What a stored score changed on its leaderboard, with the sentence that
// tells it (docs/DECISIONS.md D60). Read by the bots, later by other fronts.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_events', function (Blueprint $table) {
            // Also the cursor of the readers: events are read in id order.
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('score_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('table', 32)->default('default');
            $table->string('movement', 32);
            $table->json('flavors');
            $table->unsignedBigInteger('score');
            // Null on the player's first score on the game.
            $table->unsignedInteger('rank_before')->nullable();
            $table->unsignedInteger('rank_after');
            // The player who held `rank_after` before this score.
            $table->foreignId('displaced_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->json('facts');
            $table->text('message');
            $table->unsignedTinyInteger('importance');
            // False for a score sent long after it was made: stored, not announced.
            $table->boolean('announceable')->default(true);
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            // Set while the score is hidden by the moderation.
            $table->timestampTz('retracted_at')->nullable();

            $table->index(['game_id', 'table', 'player_id']);
            $table->index(['game_id', 'table', 'displaced_player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_events');
    }
};
