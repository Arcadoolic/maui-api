<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// TV apps showing the hiscores, and the players linked to them with their PIN
// (docs/DECISIONS.md D102). Apart from client_player: a TV is not a cabinet
// of the player, and sends no score.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE clients DROP CONSTRAINT clients_type_check');
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_type_check CHECK (type IN ('maui', 'service', 'bot', 'tv'))");

        Schema::create('tv_players', function (Blueprint $table) {
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('linked_at');

            $table->primary(['client_id', 'player_id']);
            $table->index('player_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_players');
        DB::statement("DELETE FROM clients WHERE type = 'tv'");
        DB::statement('ALTER TABLE clients DROP CONSTRAINT clients_type_check');
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_type_check CHECK (type IN ('maui', 'service', 'bot'))");
    }
};
