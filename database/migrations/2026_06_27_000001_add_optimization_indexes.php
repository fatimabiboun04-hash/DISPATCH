<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pointages', function (Blueprint $table) {
            $table->index('check_in_at');
            $table->index(['is_flagged', 'verified_by']);
        });

        Schema::table('plannings', function (Blueprint $table) {
            $table->index(['week_number', 'year', 'is_locked']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->index('planning_id');
            $table->index(['status', 'priority']);
        });

        Schema::table('pauses', function (Blueprint $table) {
            $table->index(['planning_id', 'pause_end']);
        });
    }

    public function down(): void
    {
        Schema::table('pointages', function (Blueprint $table) {
            $table->dropIndex(['check_in_at']);
            $table->dropIndex(['is_flagged', 'verified_by']);
        });

        Schema::table('plannings', function (Blueprint $table) {
            $table->dropIndex(['week_number', 'year', 'is_locked']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['planning_id']);
            $table->dropIndex(['status', 'priority']);
        });

        Schema::table('pauses', function (Blueprint $table) {
            $table->dropIndex(['planning_id', 'pause_end']);
        });
    }
};
