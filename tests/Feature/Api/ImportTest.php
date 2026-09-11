<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;
use App\Models\User;

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
}
