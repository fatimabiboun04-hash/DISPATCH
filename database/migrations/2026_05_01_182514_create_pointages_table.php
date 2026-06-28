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
        Schema::create('pointages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Link to expected planning (nullable for unplanned check-ins)
            $table->foreignId('planning_id')->nullable()->constrained()->nullOnDelete();

            // Actual timestamps
            $table->timestamp('check_in_at');
            $table->timestamp('check_out_at')->nullable();

            // Expected times from planning (copied at creation for immutability)
            $table->timestamp('scheduled_start')->nullable();
            $table->timestamp('scheduled_end')->nullable();

            // Calculated status
            $table->enum('status', ['on_time', 'late', 'early_leave', 'no_show', 'flagged'])->default('on_time');

            // Calculated metrics (in minutes)
            $table->integer('worked_minutes')->nullable();
            $table->integer('delay_minutes')->nullable();
            $table->integer('early_leave_minutes')->nullable();

            // Anti-cheat verification data (JSON for flexibility)
            $table->json('verification_data')->nullable();

            // Flagging system
            $table->boolean('is_flagged')->default(false);
            $table->text('flag_reason')->nullable();

            // Admin review
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'check_in_at']);
            $table->index('is_flagged');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pointages');
    }
};
