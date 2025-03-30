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
        Schema::create('octopus_usages', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->decimal('kwh', 8, 3);
            $table->decimal('estimated_cost', 8, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('octopus_usages');
    }
};
