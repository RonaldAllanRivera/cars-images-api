<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The human verdict on an image.
     *
     * Kept apart from make_confirmed / year_confirmed, which
     * MakeRelevanceChecker writes at harvest time. Overwriting the machine's
     * guess with the reviewer's decision would destroy the one measure of how
     * often the checker is right - and the disagreements are exactly the rows
     * worth reviewing first.
     */
    public function up(): void
    {
        Schema::table('car_images', function (Blueprint $table) {
            $table->string('review_status', 16)->default('pending')->index()->after('year_confirmed');
            // nullOnDelete: a reviewer leaving must not un-review their work.
            $table->foreignId('reviewed_by')->nullable()->after('review_status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('car_images', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            // SQLite will not drop a column an index still references; MySQL
            // would drop the index implicitly. Explicit either way.
            $table->dropIndex(['review_status']);
            $table->dropColumn(['review_status', 'reviewed_at']);
        });
    }
};
