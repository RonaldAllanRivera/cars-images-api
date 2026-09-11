<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class ImportTest extends ApiTestCase
{
    public function test_it_lists_imports_newest_first(): void
    {
        $this->actingAsApiUser();
        $older = $this->csvImport(null, ['original_filename' => 'older.csv']);
        $newer = $this->csvImport(null, ['original_filename' => 'newer.csv']);

        $this->getJson('/api/v1/imports')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_it_names_the_importer_without_leaking_their_email(): void
    {
        $this->actingAsApiUser();
        $importer = User::factory()->create(['name' => 'Allan', 'email' => 'allan@example.com']);
        $this->csvImport($importer);

        $response = $this->getJson('/api/v1/imports')->assertOk();

        $response->assertJsonPath('data.0.importer_name', 'Allan');
        $this->assertStringNotContainsString('allan@example.com', $response->getContent());
    }

    public function test_the_list_carries_no_coverage(): void
    {
        // Six aggregate counts per row is a per-row fan-out on a screen that
        // only needs to identify the import.
        $this->actingAsApiUser();
        $this->csvImport();

        $this->getJson('/api/v1/imports')
            ->assertOk()
            ->assertJsonMissingPath('data.0.coverage');
    }

    public function test_the_detail_carries_coverage(): void
    {
        $user = $this->actingAsApiUser();
        $import = $this->csvImport();
        $ran = $this->search($user, ['csv_import_id' => $import->id, 'status' => 'completed']);
        $this->image($ran);
        $this->search($user, ['csv_import_id' => $import->id, 'status' => 'pending']);

        $this->getJson("/api/v1/imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.coverage.total', 2)
            ->assertJsonPath('data.coverage.with_images', 1)
            ->assertJsonPath('data.coverage.not_run', 1);
    }

    public function test_coverage_is_null_for_an_import_with_no_searches(): void
    {
        $this->actingAsApiUser();
        $import = $this->csvImport();

        $this->getJson("/api/v1/imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.coverage', null);
    }

    public function test_it_counts_the_import_searches(): void
    {
        $user = $this->actingAsApiUser();
        $import = $this->csvImport();
        foreach (range(1, 3) as $i) {
            $this->search($user, ['csv_import_id' => $import->id]);
        }

        $this->getJson('/api/v1/imports')
            ->assertOk()
            ->assertJsonPath('data.0.searches_count', 3);
    }

    public function test_reading_imports_requires_the_imports_read_ability(): void
    {
        // The token ability gate, distinct from the policy: this token may not
        // even attempt it.
        $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $this->csvImport();

        $this->getJson('/api/v1/imports')->assertForbidden();
    }

    public function test_imports_require_authentication(): void
    {
        $this->getJson('/api/v1/imports')->assertUnauthorized();
    }

    public function test_it_imports_an_uploaded_csv(): void
    {
        $this->actingAsApiUser();
        $csv = UploadedFile::fake()->createWithContent(
            'queries.csv',
            "Make,Model,Year\nToyota,Corolla,1998\nHonda,Civic,1999\n",
        );

        $this->postJson('/api/v1/imports', ['csv_file' => $csv])
            ->assertCreated()
            ->assertJsonPath('data.original_filename', 'queries.csv')
            ->assertJsonPath('data.unique_combos', 2);

        $this->assertSame(2, CarSearch::whereNotNull('csv_import_id')->count());
    }

    public function test_it_records_who_uploaded(): void
    {
        $user = $this->actingAsApiUser();
        $csv = UploadedFile::fake()->createWithContent('q.csv', "Make,Model,Year\nKia,Rio,2010\n");

        $this->postJson('/api/v1/imports', ['csv_file' => $csv])->assertCreated();

        $this->assertSame($user->id, CsvImport::sole()->imported_by);
    }

    public function test_it_rejects_a_csv_missing_required_columns(): void
    {
        // CsvQueryImporter throws CsvImportException; the endpoint must turn
        // that into a 422 the client can show under the file picker, not a 500.
        $this->actingAsApiUser();
        $csv = UploadedFile::fake()->createWithContent('bad.csv', "Make,Year\nToyota,1998\n");

        $this->postJson('/api/v1/imports', ['csv_file' => $csv])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['csv_file']);

        $this->assertSame(0, CsvImport::count());
    }

    public function test_it_requires_a_file(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/v1/imports', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['csv_file']);
    }

    public function test_uploading_requires_the_imports_write_ability(): void
    {
        // imports:read is not enough - this is the verb that seeds hundreds of
        // queries, and it is deliberately absent from the web build's scope.
        $this->actingAsApiUser([TokenAbilities::IMPORTS_READ]);
        $csv = UploadedFile::fake()->createWithContent('q.csv', "Make,Model,Year\nKia,Rio,2010\n");

        $this->postJson('/api/v1/imports', ['csv_file' => $csv])->assertForbidden();

        $this->assertSame(0, CsvImport::count());
    }
}
