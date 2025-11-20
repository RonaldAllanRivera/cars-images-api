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
        Schema::create('car_searches', function (Blueprint $table) {
            $table->id();
            $table->string('make');
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('from_year');
            $table->unsignedSmallInteger('to_year');
            $table->string('color')->nullable();
            $table->boolean('transparent_background')->default(false);
            $table->unsignedTinyInteger('images_per_year')->default(10);
            $table->string('status', 32)->default('pending')->index();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('car_searches');
    }
};
