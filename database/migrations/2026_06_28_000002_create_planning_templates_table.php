<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('week_number');
            $table->unsignedInteger('year');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['week_number', 'year']);
        });

        Schema::create('planning_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planning_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('day_of_week'); // 'monday', 'tuesday', etc.
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['planning_template_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_template_items');
        Schema::dropIfExists('planning_templates');
    }
};
