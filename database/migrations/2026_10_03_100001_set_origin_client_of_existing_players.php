<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Players created before `origin_client_id` existed get the cabinet of their
// oldest link (docs/DECISIONS.md D54): the one they were created on, unless
// it was unlinked since.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('players')->whereNull('origin_client_id')->orderBy('id')->each(function (object $player): void {
            $origin = DB::table('client_player')
                ->where('player_id', $player->id)
                ->orderBy('linked_at')
                ->orderBy('client_id')
                ->value('client_id');

            if ($origin !== null) {
                DB::table('players')->where('id', $player->id)->update(['origin_client_id' => $origin]);
            }
        });
    }

    public function down(): void
    {
        // Nothing to undo: the column goes with the previous migration.
    }
};
