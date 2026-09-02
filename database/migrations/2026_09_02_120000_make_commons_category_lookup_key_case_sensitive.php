<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make the lookup key case-sensitive, and discard every answer resolved
     * before the resolution fixes landed.
     *
     * Two problems, one migration, because the second makes the first testable.
     *
     * 1. The unique key sat under MySQL's default utf8mb4_unicode_ci collation,
     *    so CSV rows differing only in case shared one cache row. The EPA list
     *    really does contain both: "Nissan,370z" for 2009 sits directly above
     *    "Nissan,370Z" for 2010-2021. Searching 2009 first cached a miss for
     *    "Nissan 370z", and every later 370Z search matched that row
     *    case-insensitively and returned null without probing — 12 model years
     *    permanently empty while Category:Nissan 370Z held 265 files. The hit
     *    direction is worse, because hits never expire: "Volvo S60 PoleStar"
     *    resolving to the broad Category:Volvo S60 then permanently answered
     *    "Volvo S60 Polestar" too.
     *
     * 2. Every row already in this table was resolved by code that accepted
     *    empty {{category redirect}} stubs, accepted MediaWiki-invalid titles,
     *    and could not reach a category whose model string began with a
     *    qualifier. Those answers are wrong and — being hits — would never
     *    expire on their own. The table is a pure cache: dropping it costs
     *    only the re-probe.
     */
    public function up(): void
    {
        DB::table('commons_category_lookups')->truncate();

        // SQLite, which the test suite runs on, is already case-sensitive for
        // the BINARY-affinity comparisons Eloquent issues here, and has no
        // utf8mb4 collations to apply. Only MySQL needs the change.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('commons_category_lookups', function (Blueprint $table) {
            $table->dropUnique(['make', 'model']);
        });

        Schema::table('commons_category_lookups', function (Blueprint $table) {
            $table->string('make')->collation('utf8mb4_bin')->change();
            $table->string('model')->collation('utf8mb4_bin')->change();
        });

        Schema::table('commons_category_lookups', function (Blueprint $table) {
            $table->unique(['make', 'model']);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('commons_category_lookups', function (Blueprint $table) {
            $table->dropUnique(['make', 'model']);
        });

        Schema::table('commons_category_lookups', function (Blueprint $table) {
            $table->string('make')->change();
            $table->string('model')->change();
        });

        Schema::table('commons_category_lookups', function (Blueprint $table) {
            $table->unique(['make', 'model']);
        });
    }
};
