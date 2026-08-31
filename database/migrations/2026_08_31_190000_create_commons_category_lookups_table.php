<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persistent cache of "which Commons category holds this model".
     *
     * Resolution costs roughly five API calls per make/model, and the source
     * CSV holds 5,136 distinct pairs. Without a durable cache every year of
     * every model re-probes Commons — and there is no queue worker here to
     * absorb that, since all searching runs synchronously in a request.
     *
     * Kept separate from `car_models`, which holds the curated catalogue keyed
     * on catalogue names. This table is keyed on the raw CSV string exactly as
     * imported, qualifiers and all, because that is what a search arrives with.
     */
    public function up(): void
    {
        Schema::create('commons_category_lookups', function (Blueprint $table) {
            $table->id();
            $table->string('make');
            $table->string('model');
            // Null records a known miss, so a model with no category is
            // probed once rather than on every run.
            $table->string('category')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->unique(['make', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commons_category_lookups');
    }
};
