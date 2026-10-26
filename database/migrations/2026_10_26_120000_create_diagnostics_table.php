<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The files of a game a cabinet sends from its back office when a score is
// missing, for an administrator to look into (docs/DECISIONS.md D92). The
// files themselves are on the `local` disk, under diagnostics/<uuid>/.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostics', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('romname', 32);
            $table->text('comment')->nullable();
            // What the cabinet says about the game: versions, its decoding, files left out.
            $table->jsonb('report');
            // area, name, size, modified, sha256 of each file stored.
            $table->jsonb('files');
            $table->unsignedInteger('size');
            $table->boolean('has_logs');
            $table->timestampTz('received_at')->index();

            $table->index(['client_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostics');
    }
};
