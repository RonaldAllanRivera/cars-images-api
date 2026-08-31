<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which Commons category this search actually read.
     *
     * Under exact-year matching most rows store no image, for two different
     * reasons: no category could be resolved from the model string, or the
     * category exists but holds no photograph naming that year. Those call
     * for different follow-up — a better model string versus nothing to be
     * done — and are otherwise indistinguishable from an empty result.
     */
    public function up(): void
    {
        Schema::table('car_searches', function (Blueprint $table) {
            $table->string('commons_category')->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('car_searches', function (Blueprint $table) {
            $table->dropColumn('commons_category');
        });
    }
};
