<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A member of the hiscores front has one player at most (docs/DECISIONS.md
// D71, which supersedes that part of D66): a Discord account is a person,
// and a person plays under one set of initials.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_player', function (Blueprint $table) {
            $table->unique('member_id');
        });
    }

    public function down(): void
    {
        Schema::table('member_player', function (Blueprint $table) {
            $table->dropUnique(['member_id']);
        });
    }
};
