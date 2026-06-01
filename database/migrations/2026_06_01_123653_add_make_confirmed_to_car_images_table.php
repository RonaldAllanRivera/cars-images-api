<?php

use App\Services\Images\MakeRelevanceChecker;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_images', function (Blueprint $table) {
            // Whether the searched make actually appears in the image's
            // title/description/categories. Null = not yet evaluated.
            $table->boolean('make_confirmed')->nullable()->after('attribution');
        });

        // Backfill existing rows from the metadata already stored on each image.
        $checker = new MakeRelevanceChecker();

        DB::table('car_images')->orderBy('id')->chunkById(200, function ($rows) use ($checker) {
            foreach ($rows as $row) {
                $categories = null;
                if (! empty($row->metadata)) {
                    $metadata = json_decode($row->metadata, true);
                    $categories = $metadata['imageinfo'][0]['extmetadata']['Categories']['value'] ?? null;
                }

                DB::table('car_images')->where('id', $row->id)->update([
                    'make_confirmed' => $checker->isConfirmed(
                        $row->make,
                        $row->title,
                        $row->description,
                        $categories,
                    ),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('car_images', function (Blueprint $table) {
            $table->dropColumn('make_confirmed');
        });
    }
};
