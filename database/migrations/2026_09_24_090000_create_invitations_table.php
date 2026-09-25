<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('purpose', 16);
            $table->timestampTz('expires_at');
            $table->timestampTz('claimed_at')->nullable();
            $table->string('claimed_ip', 45)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['client_id', 'claimed_at']);
        });

        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_purpose_check CHECK (purpose IN ('initial', 'renewal'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
