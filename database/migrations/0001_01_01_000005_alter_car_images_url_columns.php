<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Widen external string fields to handle long Wikimedia URLs and titles
        DB::statement('ALTER TABLE car_images MODIFY `title` TEXT NOT NULL');
        DB::statement('ALTER TABLE car_images MODIFY `source_url` TEXT NOT NULL');
        DB::statement('ALTER TABLE car_images MODIFY `thumbnail_url` TEXT NULL');
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
