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
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // "Day Shift", "Night Shift"

            // Visual type for UI coloring
            $table->enum('type', ['day', 'night', 'conge', 'absence', 'emergency']);

            $table->time('start_time');
            $table->time('end_time');

            // Break duration in minutes (for hour calculation)
            $table->integer('break_minutes')->default(0);

            // UI color hex code
            $table->string('color')->nullable();

            // Soft-disable without deleting
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('type');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
