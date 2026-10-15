<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;
use App\Models\CarMake;

class MakesTest extends ApiTestCase
{
    private function make(string $name, array $models = []): void
    {
        $make = CarMake::create(['name' => $name]);

        foreach ($models as $model) {
            $make->models()->create(['name' => $model]);
        }
    }

    public function test_it_requires_the_search_read_ability(): void
    {
        $this->getJson('/api/v1/makes')->assertUnauthorized();

        $this->actingAsApiUser([TokenAbilities::ERRORS_READ]);
        $this->getJson('/api/v1/makes')->assertForbidden();
    }

    public function test_makes_are_sorted_by_name_and_their_models_naturally(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $this->make('Toyota', ['RAV4']);
        $this->make('BMW', ['X5']);
        // Natural and case-insensitive, as the panel's model dropdown sorts. A
        // plain sort gives "Model 10", "Model X", "model 3": capitals first,
        // and 10 before 3.
        $this->make('Tesla', ['Model X', 'model 3', 'Model 10']);

        $this->assertSame([
            ['name' => 'BMW', 'models' => ['X5']],
            ['name' => 'Tesla', 'models' => ['model 3', 'Model 10', 'Model X']],
            ['name' => 'Toyota', 'models' => ['RAV4']],
        ], $this->getJson('/api/v1/makes')->assertOk()->json('data'));
    }

    public function test_an_empty_table_returns_no_makes_rather_than_the_panels_fallback(): void
    {
        // The panel falls back to a hard-coded list when the table is empty.
        // The API does not: an empty list here means CarMakeSeeder has not
        // run, and the fix is running it - not a third copy of the list.
        $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);

        $this->getJson('/api/v1/makes')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_a_make_with_no_models_still_sends_a_list(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $this->make('Lada');

        $this->assertStringContainsString('"models":[]', $this->getJson('/api/v1/makes')->getContent());
    }
}
