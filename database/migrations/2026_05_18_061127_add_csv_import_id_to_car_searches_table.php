<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('car_searches', function (Blueprint $table) {
            $table->foreignId('csv_import_id')
                ->nullable()
                ->after('requested_by')
                ->constrained('csv_imports')
                ->nullOnDelete();

            $table->index('csv_import_id');
        });
    }

    public function down(): void
    {
        Schema::table('car_searches', function (Blueprint $table) {
            $table->dropForeign(['csv_import_id']);
            $table->dropIndex(['csv_import_id']);
            $table->dropColumn('csv_import_id');
        });
    }
};
