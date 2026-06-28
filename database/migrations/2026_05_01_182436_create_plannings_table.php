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
        Schema::create('plannings', function (Blueprint $table) {
            $table->id();

            // Assigned employee
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Optional team context
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();

            // Shift definition
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();

            // The scheduled date
            $table->date('date');

            // ISO week grouping for weekly queries
            $table->integer('week_number');
            $table->integer('year');

            // Admin notes
            $table->text('notes')->nullable();

            // Who created this assignment
            $table->foreignId('created_by')->constrained('users');

            // Friday lock: prevents editing after generation
            $table->boolean('is_locked')->default(false);

            $table->timestamps();

            // Critical indexes for performance
            $table->index(['user_id', 'date']); // Conflict detection
            $table->index(['week_number', 'year']); // Weekly queries
            $table->index(['team_id', 'date']); // Team coverage
            $table->index('shift_id');
            $table->index('is_locked');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plannings');
    }
};
