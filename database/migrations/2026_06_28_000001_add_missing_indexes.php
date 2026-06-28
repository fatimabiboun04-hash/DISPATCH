<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Missing FK indexes ─────────────────────────────────
        Schema::table('pointages', function (Blueprint $table) {
            $table->index('planning_id');
            $table->index('verified_by');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->index('approved_by');
            $table->index(['type', 'status']);
        });

        Schema::table('ratings', function (Blueprint $table) {
            $table->index('rated_by');
            $table->index(['user_id', 'type']);
            $table->index(['week_number', 'year']);
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->index('generated_by');
            $table->index(['generated_by', 'status']);
            $table->index(['type', 'status', 'created_at']);
        });

        Schema::table('pauses', function (Blueprint $table) {
            $table->index('team_id');
            $table->index(['user_id', 'team_id']);
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->index(['user_id', 'is_trusted']);
        });

        Schema::table('gps_logs', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['action', 'created_at']);
            $table->index(['user_id', 'action']);
            $table->index(['entity_type', 'entity_id', 'action']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        // ── shift_skill only has composite PK (shift_id, skill_id) ─
        // skill_id cannot use that index when queried alone
        Schema::table('shift_skill', function (Blueprint $table) {
            $table->index('skill_id');
        });

        // ── Password reset token column (queried on every reset) ──
        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->index('token');
        });

        // ── Remove redundant index (covered by [week_number, year, is_locked]) ──
        Schema::table('plannings', function (Blueprint $table) {
            $table->dropIndex(['week_number', 'year']);
        });
    }

    public function down(): void
    {
        Schema::table('pointages', function (Blueprint $table) {
            $table->dropIndex(['planning_id']);
            $table->dropIndex(['verified_by']);
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropIndex(['approved_by']);
            $table->dropIndex(['type', 'status']);
        });

        Schema::table('ratings', function (Blueprint $table) {
            $table->dropIndex(['rated_by']);
            $table->dropIndex(['user_id', 'type']);
            $table->dropIndex(['week_number', 'year']);
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex(['generated_by']);
            $table->dropIndex(['generated_by', 'status']);
            $table->dropIndex(['type', 'status', 'created_at']);
        });

        Schema::table('pauses', function (Blueprint $table) {
            $table->dropIndex(['team_id']);
            $table->dropIndex(['user_id', 'team_id']);
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_trusted']);
        });

        Schema::table('gps_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['action', 'created_at']);
            $table->dropIndex(['user_id', 'action']);
            $table->dropIndex(['entity_type', 'entity_id', 'action']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        Schema::table('shift_skill', function (Blueprint $table) {
            $table->dropIndex(['skill_id']);
        });

        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->dropIndex(['token']);
        });

        // Re-add the removed index
        Schema::table('plannings', function (Blueprint $table) {
            $table->index(['week_number', 'year']);
        });
    }
};
