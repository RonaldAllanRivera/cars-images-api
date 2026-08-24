<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enforce, in the database, that one Wikimedia file appears at most once
     * per (search, year).
     *
     * `CarImageSearchService` upserts on exactly this key, but
     * `updateOrCreate()` is a SELECT followed by an INSERT and is therefore
     * not atomic: two searches running concurrently — which is precisely what
     * the bulk-run loop does — can both miss the row and both insert it.
     * The constraint turns that race into a loud failure instead of silent
     * duplicate rows in an export.
     *
     * A shorter explicit index name is used because the generated one
     * exceeds MySQL's 64-character identifier limit.
     */
    public function up(): void
    {
        Schema::table('car_images', function (Blueprint $table) {
            $table->unique(
                ['car_search_id', 'year', 'provider', 'provider_image_id'],
                'car_images_owner_provider_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('car_images', function (Blueprint $table) {
            $table->dropUnique('car_images_owner_provider_unique');
        });
    }
};
