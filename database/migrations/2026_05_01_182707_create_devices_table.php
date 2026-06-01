<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('devices', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        
        // Hashed device fingerprint
        $table->string('fingerprint')->index();
        
        // Human-readable device name
        $table->string('name')->nullable();
        
        // Trust status
        $table->boolean('is_trusted')->default(false);
        
        // Tracking
        $table->timestamp('trusted_at')->nullable();
        $table->timestamp('last_used_at')->nullable();
        
        $table->timestamps();
        
        // Prevent duplicate fingerprints per user
        $table->unique(['user_id', 'fingerprint']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
