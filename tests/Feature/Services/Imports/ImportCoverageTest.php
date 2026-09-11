<?php

namespace Tests\Feature\Services\Imports;

use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use App\Services\Imports\ImportCoverage;
use Tests\Feature\Api\ApiTestCase;

/**
 * Extends ApiTestCase for its search()/image()/csvImport() helpers, not for
 * anything HTTP - there are no model factories in this project, and these are
 * the only builders that supply the non-null columns.
 */
class ImportCoverageTest extends ApiTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function csvSearch(CsvImport $import, string $status, bool $withImage = false): CarSearch
    {
        $search = $this->search($this->user, [
            'csv_import_id' => $import->id,
            'status' => $status,
        ]);

        if ($withImage) {
            $this->image($search);
        }

        return $search;
    }

    public function test_it_separates_ran_and_found_nothing_from_never_ran(): void
    {
        // The whole reason this exists. `status` cannot tell them apart: a
        // search that ran and found nothing is `completed`, exactly like one
        // that found five images.
        $import = $this->csvImport();
        $this->csvSearch($import, 'completed', withImage: true);
        $this->csvSearch($import, 'completed');
        $this->csvSearch($import, 'pending');

        $coverage = app(ImportCoverage::class)->for($import->id);

        $this->assertSame(3, $coverage['total']);
        $this->assertSame(2, $coverage['searched']);
        $this->assertSame(1, $coverage['not_run']);
        $this->assertSame(1, $coverage['with_images']);
        $this->assertSame(1, $coverage['no_images']);
    }

    public function test_it_counts_failures_separately(): void
    {
        $import = $this->csvImport();
        $this->csvSearch($import, 'failed');
        $this->csvSearch($import, 'completed', withImage: true);

        $coverage = app(ImportCoverage::class)->for($import->id);

        $this->assertSame(1, $coverage['failed']);
        // A failed search still counts as searched: it ran, it just did not
        // finish. Treating it as not-run would tell the operator to run it
        // again with no hint that it already broke once.
        $this->assertSame(2, $coverage['searched']);
    }

    public function test_running_counts_as_not_run(): void
    {
        // `running` on this host means "a request died mid-search": there is
        // no worker, so nothing is advancing it. It is work still to do.
        $import = $this->csvImport();
        $this->csvSearch($import, 'running');

        $this->assertSame(1, app(ImportCoverage::class)->for($import->id)['not_run']);
    }

    public function test_it_ignores_searches_from_other_imports(): void
    {
        $mine = $this->csvImport();
        $theirs = $this->csvImport();
        $this->csvSearch($mine, 'completed', withImage: true);
        $this->csvSearch($theirs, 'completed', withImage: true);

        $this->assertSame(1, app(ImportCoverage::class)->for($mine->id)['total']);
    }

    public function test_a_null_import_covers_every_csv_derived_search(): void
    {
        $import = $this->csvImport();
        $this->csvSearch($import, 'completed', withImage: true);
        // Ad-hoc: no csv_import_id, and never part of coverage.
        $this->search($this->user, ['csv_import_id' => null, 'status' => 'completed']);

        $this->assertSame(1, app(ImportCoverage::class)->for(null)['total']);
    }

    public function test_it_returns_null_when_there_is_nothing_to_describe(): void
    {
        // Null hides the panel rather than rendering six zeroes, which read as
        // a broken import rather than an empty one.
        $this->assertNull(app(ImportCoverage::class)->for($this->csvImport()->id));
    }
}
