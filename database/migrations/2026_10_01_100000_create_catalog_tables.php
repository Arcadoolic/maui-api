<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Game catalog fed by service accounts (docs/DECISIONS.md D47).
return new class extends Migration
{
    public function up(): void
    {
        // genre.ini genres, and catver.ini genres with their subgenres as children.
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('source', 16);
            $table->string('name', 128);
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->timestampsTz();

            // A top-level category has no parent: NULL must count as a value here.
            $table->unique(['source', 'parent_id', 'name'])->nullsNotDistinct();
        });

        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('romname', 32)->unique();
            $table->string('description');
            $table->string('manufacturer')->nullable();
            // MAME years are not always numbers: "198?", "19??".
            $table->string('year', 4)->nullable();
            // Not a foreign key: a clone may be catalogued without its parent.
            $table->string('parent_romname', 32)->nullable()->index();
            $table->unsignedSmallInteger('player_sim')->nullable();
            $table->unsignedSmallInteger('player_alt')->nullable();
            $table->foreignId('genre_category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->foreignId('catver_category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->boolean('mature')->default(false);
            // Null for a game only known from a score, not catalogued yet.
            $table->timestampTz('catalogued_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
        Schema::dropIfExists('categories');
    }
};
