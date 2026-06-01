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
    Schema::create('reports', function (Blueprint $table) {
        $table->id();
        $table->enum('type', ['weekly', 'monthly', 'custom']);
        
        $table->integer('week_number')->nullable();
        $table->integer('year')->nullable();
        
        $table->date('start_date');
        $table->date('end_date');
        
        // Generated file
        $table->string('file_path')->nullable();
        $table->enum('file_type', ['pdf', 'excel']);
        
        $table->foreignId('generated_by')->constrained('users');
        $table->enum('status', ['queued', 'processing', 'completed', 'failed'])
                  ->default('queued');
                  
        
        $table->timestamps();
        
        $table->index(['type', 'status']);
        $table->index(['week_number', 'year']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
