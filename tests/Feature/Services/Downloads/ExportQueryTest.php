<?php

namespace Tests\Feature\Services\Downloads;

use App\Models\User;
use App\Services\Downloads\ExportQuery;
use Tests\Feature\Api\ApiTestCase;

class ExportQueryTest extends ApiTestCase
{
    public function test_it_counts_and_loads_the_same_set(): void
    {
        // The count a user is shown and the file they receive must come from
        // one query, or the button promises something the export does not do.
        $user = User::factory()->create();
        $search = $this->search($user);
        $this->image($search, ['review_status' => 'approved']);
        $this->image($search, ['review_status' => 'rejected']);

        $query = app(ExportQuery::class);
        $filters = ['review_status' => 'approved'];

        $this->assertSame(1, $query->count($filters));
        $this->assertCount(1, $query->images($filters));
    }

    public function test_images_arrive_with_their_search_loaded(): void
    {
        // BatchCsvExporter reads $image->search for the transmission column;
        // without eager loading that is one query per row.
        $user = User::factory()->create();
        $this->image($this->search($user));

        $image = app(ExportQuery::class)->images([])->first();

        $this->assertTrue($image->relationLoaded('search'));
    }

    public function test_it_filters_by_import_through_the_search(): void
    {
        $user = User::factory()->create();
        $mine = $this->csvImport($user);
        $theirs = $this->csvImport($user);
        $this->image($this->search($user, ['csv_import_id' => $mine->id]));
        $this->image($this->search($user, ['csv_import_id' => $theirs->id]));

        $this->assertSame(1, app(ExportQuery::class)->count(['csv_import_id' => $mine->id]));
    }

    public function test_a_verdict_filter_matches_its_value_and_never_unknown(): void
    {
        // The nullable "unknown" is neither true nor false. The same rule the
        // grid applies - otherwise exporting "matched" would sweep in images the
        // checker never reached.
        $user = User::factory()->create();
        $search = $this->search($user);
        $this->image($search, ['make_confirmed' => true]);
        $this->image($search, ['make_confirmed' => false]);
        $this->image($search, ['make_confirmed' => null]);

        $query = app(ExportQuery::class);

        $this->assertSame(1, $query->count(['make_confirmed' => '1']));
        $this->assertSame(1, $query->count(['make_confirmed' => '0']));
    }

    public function test_the_zip_cap_comes_from_config(): void
    {
        config()->set('cars-images.bulk_download_max_images', 7);

        $this->assertSame(7, app(ExportQuery::class)->zipCap());
    }
}
