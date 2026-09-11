<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;
use App\Models\CsvImport;
use App\Models\User;
use App\Policies\CsvImportPolicy;
use Illuminate\Support\Facades\Gate;
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

    public function test_any_authenticated_user_may_run_any_import(): void
    {
        /*
         * Asserted, not assumed. A client naming someone else's import is the
         * shape of an IDOR, and an automated review flagged it as one - so the
         * current answer is written down rather than left to inference.
         *
         * There is no ownership model in this app. `imported_by` records who
         * uploaded, not who owns; every policy is permissive by an explicit
         * decision carried from the original spec ("match Filament: all
         * authenticated users see everything"); and GET /imports,
         * GET /imports/{id} and GET /searches?csv_import_id= already return
         * every import to every authenticated user.
         *
         * If ownership ever arrives, this test fails, which is the point. It
         * has to be changed deliberately rather than quietly still passing
         * while three read endpoints leak what this one started guarding.
         */
        Http::fake(['*' => Http::response(['query' => ['pages' => []]], 200)]);
        $uploader = User::factory()->create();
        $import = $this->csvImport($uploader);
        $search = $this->search($uploader, ['csv_import_id' => $import->id, 'status' => 'pending']);

        // A different user entirely, with a full-ability token.
        $this->actingAsApiUser();

        $this->postJson('/api/v1/searches/run-chunk', ['csv_import_id' => $import->id])
            ->assertOk();

        $this->assertSame('completed', $search->refresh()->status);
    }

    public function test_the_policy_receives_the_import_being_run(): void
    {
        // The authorization seam takes the object the client named, so an
        // ownership rule has somewhere to live. A class-level gate could not
        // express "not that one" however it was written inside.
        Http::fake(['*' => Http::response(['query' => ['pages' => []]], 200)]);
        $user = $this->actingAsApiUser();
        $import = $this->csvImport($user);

        $seen = null;
        Gate::policy(CsvImport::class, CsvImportPolicy::class);
        Gate::before(function ($actor, $ability, $arguments) use (&$seen) {
            if ($ability === 'run') {
                $seen = $arguments[0] ?? null;
            }

            return null;
        });

        $this->postJson('/api/v1/searches/run-chunk', ['csv_import_id' => $import->id])
            ->assertOk();

        $this->assertInstanceOf(CsvImport::class, $seen);
        $this->assertSame($import->id, $seen->id);
    }
}
