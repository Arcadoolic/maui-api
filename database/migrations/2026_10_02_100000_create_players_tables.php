<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Global players, linked to cabinets with a PIN (docs/DECISIONS.md D48).
// No name or email: personal data stays on the cabinet.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            // The id cabinets know: the audit log needs integer keys (activity_log.subject_id).
            $table->uuid('uuid')->unique();
            $table->string('pseudo_3', 3)->unique();
            $table->boolean('is_public')->default(false);
            $table->string('status', 16)->default('active');
            // Encrypted with APP_KEY, not hashed: admins can read it back (D49).
            $table->text('pin');
            $table->unsignedSmallInteger('pin_failed_attempts')->default(0);
            $table->timestampTz('pin_locked_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('client_player', function (Blueprint $table) {
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('linked_at');

            $table->primary(['client_id', 'player_id']);
            $table->index('player_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_player');
        Schema::dropIfExists('players');
    }
};
