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
    Schema::create('gps_logs', function (Blueprint $table) {
        $table->id();
        $table->foreignId('pointage_id')->constrained()->cascadeOnDelete();
        
        // Precise coordinates
        $table->decimal('latitude', 10, 8);
        $table->decimal('longitude', 11, 8);
        
        // GPS accuracy from device
        $table->decimal('accuracy_meters', 8, 2)->nullable();
        
        // Calculated distance from office
        $table->decimal('distance_from_office', 8, 2);
        
        // Validation result
        $table->boolean('is_valid');
        
        $table->timestamps();
        
        $table->index(['pointage_id', 'is_valid']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gps_logs');
    }
};
