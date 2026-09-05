<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;
use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Authenticate a fresh user with the given abilities. Defaults to all
     * four, so a test asserting a 403 must pass the narrower set on purpose.
     */
    protected function actingAsApiUser(?array $abilities = null): User
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user, $abilities ?? TokenAbilities::all());

        return $user;
    }

    /**
     * Send the next request with a real bearer token.
     *
     * Sanctum's guard caches the resolved user for the life of the app
     * instance, and a feature test reuses one instance across requests.
     * Without forgetting the guards, the second token in a test would be
     * ignored and the first user returned - which hides exactly the
     * revocation bugs these tests exist to catch.
     */
    protected function asToken(string $plainTextToken): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($plainTextToken);
    }

    protected function search(User $user, array $overrides = []): CarSearch
    {
        return CarSearch::create(array_merge([
            'make' => 'Toyota',
            'model' => 'RAV4',
            'from_year' => 1997,
            'to_year' => 1997,
            'transparent_background' => false,
            'images_per_year' => 5,
            'status' => 'completed',
            'requested_by' => $user->id,
        ], $overrides));
    }

    protected function image(CarSearch $search, array $overrides = []): CarImage
    {
        $id = uniqid('img-', true);

        return CarImage::create(array_merge([
            'car_search_id' => $search->id,
            'provider' => 'wikimedia',
            'provider_image_id' => $id,
            'make' => $search->make,
            'model' => $search->model,
            'year' => $search->from_year,
            'title' => "File:{$search->make} {$id}.jpg",
            'source_url' => "https://upload.wikimedia.org/{$id}.jpg",
            'thumbnail_url' => "https://upload.wikimedia.org/thumb/{$id}.jpg",
            'width' => 800,
            'height' => 600,
            'download_status' => 'not_downloaded',
        ], $overrides));
    }
}
