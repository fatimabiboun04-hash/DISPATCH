<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_sandbox_items', function (Blueprint $table) {
            $table->id();
            $table->string('session_id'); // UUID grouping sandbox items into a preview session
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->unsignedInteger('week_number');
            $table->unsignedInteger('year');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['session_id']);
            $table->index(['week_number', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_sandbox_items');
    }
};
