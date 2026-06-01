<?php

// database/migrations/xxxx_xx_xx_create_pauses_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pauses', function (Blueprint $table) {
            $table->id();
            
            // Who is on pause
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            
            // Optional team context (for team-wide pause assignments)
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            
            // Linked to planning (shift context)
            $table->foreignId('planning_id')->constrained()->cascadeOnDelete();
            
            // Pause time window (time only, within shift hours)
            $table->dateTime('pause_start');
            $table->dateTime('pause_end');
            
            $table->timestamps();
            
            // Index for quick lookups by planning and user
            $table->index(['planning_id', 'user_id']);
            $table->index(['user_id', 'pause_start', 'pause_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pauses');
    }
};