<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pauses', function (Blueprint $table) {
            $table->string('type', 50)->default('break')->after('planning_id');
            $table->text('reason')->nullable()->after('type');
            $table->string('status', 20)->default('scheduled')->after('reason');
            $table->integer('duration_minutes')->nullable()->after('status');
            $table->timestamp('cancelled_at')->nullable()->after('updated_at');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('pauses', function (Blueprint $table) {
            $table->dropColumn(['type', 'reason', 'status', 'duration_minutes', 'cancelled_at', 'cancelled_by']);
        });
    }
};
