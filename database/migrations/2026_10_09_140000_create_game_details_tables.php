<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What ScreenScraper knows of a game, for the game pages of the hiscores
// front (docs/DECISIONS.md D68). Apart from `games`, which the catalog push
// rewrites completely (D47).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->unique()->constrained()->cascadeOnDelete();
            // False: asked, and ScreenScraper does not know the game.
            $table->boolean('found');
            $table->unsignedBigInteger('screenscraper_id')->nullable();
            $table->text('synopsis_fr')->nullable();
            $table->text('synopsis_en')->nullable();
            $table->string('developer')->nullable();
            $table->string('publisher')->nullable();
            // Out of 20, as ScreenScraper rates.
            $table->unsignedTinyInteger('rating')->nullable();
            // As written by ScreenScraper: "1-2", "2"...
            $table->string('players', 32)->nullable();
            // Degrees: 0 for a horizontal screen, 90 or 270 for a vertical one.
            $table->unsignedSmallInteger('rotation')->nullable();
            $table->string('resolution', 32)->nullable();
            // Of the first player's panel, as ScreenScraper lists its controls.
            $table->boolean('joystick')->nullable();
            $table->unsignedTinyInteger('buttons')->nullable();
            $table->json('genres')->nullable();
            $table->timestampTz('scraped_at');
            $table->timestampsTz();
        });

        Schema::create('game_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            // screenshot, title, logo, marquee, flyer.
            $table->string('type', 16);
            $table->string('path');
            $table->string('mime', 32);
            // SHA-256 of the file: its ETag.
            $table->char('hash', 64);
            $table->timestampsTz();

            $table->unique(['game_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_media');
        Schema::dropIfExists('game_details');
    }
};
