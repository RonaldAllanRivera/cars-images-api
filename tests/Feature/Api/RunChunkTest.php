<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;
use Illuminate\Support\Facades\Http;

class RunChunkTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cars-images.bulk_run_sleep_seconds_between_queries', 0);
    }

    public function test_it_runs_a_chunk_and_reports_outcomes(): void
    {
        Http::fake(['*' => Http::response(['query' => ['pages' => []]], 200)]);
        $user = $this->actingAsApiUser();
        $import = $this->csvImport($user);
        $this->search($user, ['csv_import_id' => $import->id, 'status' => 'pending']);

        $this->postJson('/api/v1/searches/run-chunk', ['csv_import_id' => $import->id])
            ->assertOk()
            ->assertJsonPath('remaining', 0)
            ->assertJsonPath('blocked', null)
            ->assertJsonCount(1, 'outcomes')
            ->assertJsonPath('outcomes.0.outcome', 'completed');
    }

    public function test_it_reports_a_block_with_its_retry_window(): void
    {
        Http::fake(['*' => Http::response('Too Many Requests', 429, ['Retry-After' => '600'])]);
        $user = $this->actingAsApiUser();
        $import = $this->csvImport($user);
        $this->search($user, ['csv_import_id' => $import->id, 'status' => 'pending']);

        $this->postJson('/api/v1/searches/run-chunk', ['csv_import_id' => $import->id])
            ->assertOk()
            ->assertJsonPath('blocked.status', 429)
            ->assertJsonPath('blocked.retry_after_seconds', 600);
    }

    public function test_a_finished_import_returns_an_empty_success(): void
    {
        // This is how the client's loop terminates. A 404 would make a normal
        // ending look like a crash.
        $user = $this->actingAsApiUser();
        $import = $this->csvImport($user);

        $this->postJson('/api/v1/searches/run-chunk', ['csv_import_id' => $import->id])
            ->assertOk()
            ->assertJsonPath('remaining', 0)
            ->assertJsonCount(0, 'outcomes');
    }

    public function test_running_requires_the_search_run_ability(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $import = $this->csvImport($user);
        $search = $this->search($user, ['csv_import_id' => $import->id, 'status' => 'pending']);

        $this->postJson('/api/v1/searches/run-chunk', ['csv_import_id' => $import->id])
            ->assertForbidden();

        $this->assertSame('pending', $search->refresh()->status);
    }

    public function test_it_rejects_an_unknown_import(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/v1/searches/run-chunk', ['csv_import_id' => 9999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['csv_import_id']);
    }

    public function test_it_requires_an_import(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/v1/searches/run-chunk', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['csv_import_id']);
    }

    public function test_running_requires_authentication(): void
    {
        $this->postJson('/api/v1/searches/run-chunk', ['csv_import_id' => 1])
            ->assertUnauthorized();
    }
}
