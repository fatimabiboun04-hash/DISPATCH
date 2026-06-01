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
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        
        // Role system: admin or employee
        $table->enum('role', ['admin', 'employee'])->default('employee');
        
        // Lockout system: active or suspended
        $table->enum('status', ['active', 'suspended'])->default('active');
        $table->text('suspension_reason')->nullable();
        
        // Profile data
        $table->string('phone')->nullable();
        $table->text('description')->nullable();
        $table->string('avatar')->nullable(); // Storage path
        
        // Weekly hours limit (override per employee if needed)
        $table->decimal('weekly_hours_limit', 5, 2)->default(44.00);
        
        $table->rememberToken();
        $table->timestamps();
        
        // Indexes for common queries
        $table->index(['role', 'status']);
        $table->index('status');
    });
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
