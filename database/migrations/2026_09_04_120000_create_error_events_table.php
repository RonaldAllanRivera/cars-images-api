<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_events', function (Blueprint $table) {
            $table->id();
            $table->string('context', 32);
            $table->string('severity', 16)->default('error');
            $table->text('message');
            $table->string('exception_class')->nullable();
            $table->text('exception_message')->nullable();
            $table->text('trace_excerpt')->nullable();
            $table->json('details')->nullable();

            // nullOnDelete, not cascadeOnDelete: deleting a CSV import must not
            // cascade away the record of why it failed. A log outlives the
            // thing it describes — that is what makes it a log.
            $table->foreignId('car_search_id')->nullable()->constrained('car_searches')->nullOnDelete();
            $table->foreignId('csv_import_id')->nullable()->constrained('csv_imports')->nullOnDelete();
            $table->foreignId('car_image_id')->nullable()->constrained('car_images')->nullOnDelete();

            $table->timestamp('occurred_at')->useCurrent();

            $table->index('occurred_at');
            $table->index(['context', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_events');
    }
};
