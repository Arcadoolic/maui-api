<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Members of the hiscores front: a Discord account, let in by an invitation,
// linked to its players (docs/DECISIONS.md D64, D65, D66).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_invitations', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            // What the admin wrote to remember who the link was given to.
            $table->string('label');
            // Null: no limit, e.g. a link posted on the Discord server.
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('members', function (Blueprint $table) {
            $table->id();
            // The id the front knows.
            $table->uuid('uuid')->unique();
            // A Discord snowflake: too big for a JavaScript number, kept as text.
            $table->string('discord_id', 32)->unique();
            $table->string('username');
            $table->string('display_name')->nullable();
            // Hash of the Discord avatar, null without one.
            $table->string('discord_avatar')->nullable();
            $table->string('status', 16)->default('active');
            $table->foreignId('member_invitation_id')->nullable()->constrained()->nullOnDelete();
            $table->rememberToken();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('member_player', function (Blueprint $table) {
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            // A player belongs to one member at most.
            $table->foreignId('player_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestampTz('linked_at');

            $table->primary(['member_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_player');
        Schema::dropIfExists('members');
        Schema::dropIfExists('member_invitations');
    }
};
