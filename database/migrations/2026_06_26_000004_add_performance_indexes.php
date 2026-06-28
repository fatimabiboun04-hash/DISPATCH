<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plannings', function (Blueprint $table) {
            $table->index('created_by');
        });

        Schema::table('skill_user', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('pointages', function (Blueprint $table) {
            $table->index(['user_id', 'check_in_at', 'status']);
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->index(['user_id', 'start_date', 'end_date', 'status']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'due_date']);
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('plannings', function (Blueprint $table) {
            $table->dropIndex(['created_by']);
        });

        Schema::table('skill_user', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('pointages', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'check_in_at', 'status']);
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'start_date', 'end_date', 'status']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
            $table->dropIndex(['user_id', 'due_date']);
            $table->dropIndex(['created_by']);
        });
    }
};
