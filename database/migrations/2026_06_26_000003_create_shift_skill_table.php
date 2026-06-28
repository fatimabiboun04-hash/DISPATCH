<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('shift_skill', function (Blueprint $table) {
            $table->foreignId('shift_id')->constrained()->onDelete('cascade');
            $table->foreignId('skill_id')->constrained()->onDelete('cascade');
            $table->primary(['shift_id', 'skill_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('shift_skill');
    }
};
