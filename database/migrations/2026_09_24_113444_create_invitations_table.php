<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Get the connection name for the migration.
     */
    public function getConnection(): string
    {
        return config('database.default');
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('token')->unique();
            $table->enum('purpose', ['initial', 'renewal'])->default('initial');
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->ipAddress('claimed_ip')->nullable();
            $table->foreignId('created_by')->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
