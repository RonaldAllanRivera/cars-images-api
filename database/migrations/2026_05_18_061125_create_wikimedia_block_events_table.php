<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wikimedia_block_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_search_id')->nullable()->constrained('car_searches')->nullOnDelete();
            $table->foreignId('csv_import_id')->nullable()->constrained('csv_imports')->nullOnDelete();
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('retry_after_seconds')->nullable();
            $table->text('response_excerpt');
            $table->timestamp('occurred_at')->useCurrent();
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wikimedia_block_events');
    }
};
