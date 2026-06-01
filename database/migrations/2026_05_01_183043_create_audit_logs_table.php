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
    Schema::create('audit_logs', function (Blueprint $table) {
        $table->id();
        
        // Who acted (nullable for system actions)
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        
        // Action type
        $table->string('action'); // created, updated, deleted, approved, checked_in, etc.
        
        // Target entity
        $table->string('entity_type'); // App\Models\Planning
        $table->unsignedBigInteger('entity_id');
        
        // Before/after state snapshots
        $table->json('old_values')->nullable();
        $table->json('new_values')->nullable();
        
        // Request metadata
        $table->string('ip_address')->nullable();
        $table->text('user_agent')->nullable();
        
        $table->timestamp('created_at'); // No updated_at — immutable
        
        $table->index(['entity_type', 'entity_id']);
        $table->index('action');
        $table->index('user_id');
        $table->index('created_at');
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
