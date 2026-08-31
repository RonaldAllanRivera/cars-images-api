<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record whether the stored `year` is evidence-backed.
     *
     * `WikimediaClient` retries without the year when a year-specific query
     * comes back with nothing usable, because a hard year term over-constrains
     * Commons for sparsely photographed models. That fallback query is
     * year-independent — "Acura CL car" is the same string for 1997, 1998 and
     * 1999 — so every year in a range draws the identical result set, and
     * `fetchAndStoreForYear()` then stamps each copy with the year it happened
     * to be looping over. The rows asserted a model year the image was never
     * matched on, and two adjacent years showed the same photograph with no
     * indication why.
     *
     * The flag mirrors `make_confirmed`: it does not suppress the result, it
     * says how far to trust it.
     *
     * Existing rows stay NULL — "not evaluated". Unlike `make_confirmed`,
     * this cannot be backfilled from the stored metadata: which of the two
     * queries produced a row was never recorded, and the image's own text
     * mentioning a year is not evidence that the search matched on it.
     */
    public function up(): void
    {
        Schema::table('car_images', function (Blueprint $table) {
            $table->boolean('year_confirmed')->nullable()->after('make_confirmed');
        });
    }

    public function down(): void
    {
        Schema::table('car_images', function (Blueprint $table) {
            $table->dropColumn('year_confirmed');
        });
    }
};
