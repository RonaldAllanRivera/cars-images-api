<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;

class SearchesTest extends ApiTestCase
{
    public function test_listing_requires_the_search_read_ability(): void
    {
        $this->getJson('/api/v1/searches')->assertUnauthorized();

        $this->actingAsApiUser([TokenAbilities::REVIEW_WRITE]);
        $this->getJson('/api/v1/searches')->assertForbidden();
    }

    public function test_the_list_is_newest_first_with_image_counts(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $older = $this->search($user);
        $newer = $this->search($user, ['make' => 'Honda', 'model' => 'Civic']);
        $this->image($older);
        $this->image($older);

        $response = $this->getJson('/api/v1/searches')->assertOk();

        $this->assertSame([$newer->id, $older->id], $response->json('data.*.id'));
        $this->assertSame([0, 2], $response->json('data.*.images_count'));
        $response->assertJsonStructure(['meta' => ['next_cursor', 'prev_cursor', 'per_page']]);
    }

    public function test_the_list_filters_by_status_and_rejects_unknown_statuses(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $failed = $this->search($user, ['status' => 'failed']);
        $this->search($user, ['status' => 'completed']);

        $ids = $this->getJson('/api/v1/searches?status=failed')->assertOk()->json('data.*.id');
        $this->assertSame([$failed->id], $ids);

        $this->getJson('/api/v1/searches?status=exploded')->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_show_returns_the_search_with_its_image_count(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $search = $this->search($user, ['commons_category' => 'Toyota RAV4 (XA10)']);
        $this->image($search);

        $this->getJson("/api/v1/searches/{$search->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $search->id)
            ->assertJsonPath('data.commons_category', 'Toyota RAV4 (XA10)')
            ->assertJsonPath('data.images_count', 1)
            ->assertJsonStructure(['data' => [
                'id', 'make', 'model', 'commons_category', 'from_year', 'to_year', 'color', 'transmission',
                'transparent_background', 'images_per_year', 'status', 'csv_import_id', 'requested_by',
                'images_count', 'created_at', 'updated_at',
            ]]);

        $this->getJson('/api/v1/searches/999999')->assertNotFound();
    }

    public function test_a_searchs_images_are_scoped_to_it_and_filterable(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $mine = $this->search($user);
        $other = $this->search($user, ['make' => 'Honda', 'model' => 'Civic']);
        $approved = $this->image($mine, ['review_status' => 'approved']);
        $pending = $this->image($mine);
        $this->image($other, ['review_status' => 'approved']);

        $all = $this->getJson("/api/v1/searches/{$mine->id}/images")->assertOk()->json('data.*.id');
        $this->assertEqualsCanonicalizing([$approved->id, $pending->id], $all);

        $only = $this->getJson("/api/v1/searches/{$mine->id}/images?review_status=approved")->assertOk()->json('data.*.id');
        $this->assertSame([$approved->id], $only);
    }

    public function test_it_filters_to_csv_derived_searches(): void
    {
        // The panel splits these into two resources on exactly this column;
        // the API returned them merged, so the mobile Runs list showed both.
        $user = $this->actingAsApiUser();
        $import = $this->csvImport();
        $fromCsv = $this->search($user, ['csv_import_id' => $import->id]);
        $this->search($user, ['csv_import_id' => null]);

        $this->getJson('/api/v1/searches?source=csv')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fromCsv->id);
    }

    public function test_it_filters_to_ad_hoc_searches(): void
    {
        $user = $this->actingAsApiUser();
        $import = $this->csvImport();
        $this->search($user, ['csv_import_id' => $import->id]);
        $adHoc = $this->search($user, ['csv_import_id' => null]);

        $this->getJson('/api/v1/searches?source=adhoc')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $adHoc->id);
    }

    public function test_it_filters_by_import(): void
    {
        $user = $this->actingAsApiUser();
        $mine = $this->csvImport();
        $theirs = $this->csvImport();
        $wanted = $this->search($user, ['csv_import_id' => $mine->id]);
        $this->search($user, ['csv_import_id' => $theirs->id]);

        $this->getJson("/api/v1/searches?csv_import_id={$mine->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wanted->id);
    }

    public function test_coverage_no_images_finds_searches_that_ran_and_found_nothing(): void
    {
        // The question `status` cannot answer: both of these are `completed`.
        $user = $this->actingAsApiUser();
        $found = $this->search($user, ['status' => 'completed']);
        $this->image($found);
        $empty = $this->search($user, ['status' => 'completed']);

        $this->getJson('/api/v1/searches?coverage=no_images')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $empty->id);
    }

    public function test_coverage_not_run_includes_running(): void
    {
        // No worker on this host: a row left `running` is a dead request, not
        // work in progress.
        $user = $this->actingAsApiUser();
        $pending = $this->search($user, ['status' => 'pending']);
        $running = $this->search($user, ['status' => 'running']);
        $this->search($user, ['status' => 'completed']);

        $response = $this->getJson('/api/v1/searches?coverage=not_run')->assertOk();

        $this->assertEqualsCanonicalizing(
            [$pending->id, $running->id],
            array_column($response->json('data'), 'id'),
        );
    }

    public function test_an_unfiltered_list_still_returns_everything(): void
    {
        // The three new parameters are all `sometimes`; nothing already
        // deployed shifts because they were added.
        $user = $this->actingAsApiUser();
        $import = $this->csvImport();
        $this->search($user, ['csv_import_id' => $import->id]);
        $this->search($user, ['csv_import_id' => null]);

        $this->getJson('/api/v1/searches')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_it_rejects_an_unknown_coverage_value(): void
    {
        $this->actingAsApiUser();

        $this->getJson('/api/v1/searches?coverage=maybe')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['coverage']);
    }
}
