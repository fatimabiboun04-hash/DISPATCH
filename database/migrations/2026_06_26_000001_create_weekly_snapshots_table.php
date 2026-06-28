<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_snapshots', function (Blueprint $table) {
            $table->id();
            $table->integer('week_number');
            $table->integer('year');
            $table->integer('total_employees')->default(0);
            $table->integer('total_planned')->default(0);
            $table->integer('total_checked_in')->default(0);
            $table->integer('total_absences')->default(0);
            $table->decimal('avg_coverage', 5, 1)->default(0);
            $table->decimal('total_overtime_hours', 8, 1)->default(0);
            $table->integer('overtime_employee_count')->default(0);
            $table->integer('under_hours_employee_count')->default(0);
            $table->timestamp('generated_at')->nullable();

            $table->unique(['week_number', 'year']);
            $table->index('generated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_snapshots');
    }
};
