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
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE car_images MODIFY `title` VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE car_images MODIFY `source_url` VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE car_images MODIFY `thumbnail_url` VARCHAR(255) NULL');
    }
};
