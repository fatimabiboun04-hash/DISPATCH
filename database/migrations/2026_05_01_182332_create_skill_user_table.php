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
    Schema::create('skill_user', function (Blueprint $table) {
        $table->id();
        $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        
        // Skill proficiency level
        $table->enum('level', ['beginner', 'intermediate', 'expert'])->nullable();
        $table->date('certified_at')->nullable();
        
        $table->timestamps();
        
        $table->unique(['skill_id', 'user_id']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('skill_user');
    }
};
