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
        // Widen external string fields to handle long Wikimedia URLs and titles
        Schema::table('car_images', function (Blueprint $table) {
            $table->text('title')->nullable(false)->change();
            $table->text('source_url')->nullable(false)->change();
            $table->text('thumbnail_url')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * We intentionally keep these columns as TEXT when rolling back to avoid
     * truncation errors if existing data exceeds 255 characters.
     */
    public function down(): void
    {
        // no-op: do not shrink long URL/title columns back to VARCHAR(255)
    }
};
