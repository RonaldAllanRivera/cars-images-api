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
        Schema::create('car_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_search_id')->nullable()->constrained('car_searches')->nullOnDelete();
            $table->string('make')->index();
            $table->string('model')->nullable()->index();
            $table->unsignedSmallInteger('year')->index();
            $table->string('color')->nullable();
            $table->boolean('transparent_background')->default(false);
            $table->string('provider', 32)->default('wikimedia');
            $table->string('provider_image_id')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source_url');
            $table->string('thumbnail_url')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('license')->nullable();
            $table->text('attribution')->nullable();
            $table->string('download_status', 32)->default('not_downloaded')->index();
            $table->string('download_path')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['make', 'model', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('car_images');
    }
};
