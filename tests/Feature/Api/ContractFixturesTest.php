<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;
use App\Models\CarImage;
use App\Models\ErrorEvent;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * Generates the JSON fixtures the mobile app's Zod schemas are asserted
 * against, and fails when the committed fixtures no longer match what the
 * API returns.
 *
 * Regenerate deliberately with:
 *     UPDATE_CONTRACT_FIXTURES=1 php artisan test --filter=ContractFixturesTest
 *
 * A red run here means the API shape changed. Regenerate, then run the
 * mobile suite - the Zod schemas will tell you which field moved.
 */
class ContractFixturesTest extends ApiTestCase
{
    private const FIXTURE_DIR = 'mobile/src/api/__fixtures__';

    /**
     * The token is a real random string per run, so it is normalised to a
     * constant. Nothing that looks like a credential belongs in a committed
     * fixture, and its exact value is not part of the contract - its
     * presence and type are.
     */
    private const TOKEN_PLACEHOLDER = '1|fixture-token-not-a-real-credential';

    public function test_the_committed_fixtures_match_the_live_api(): void
    {
        // Frozen so every timestamp in the fixtures is byte-stable; with
        // RefreshDatabase restarting ids at 1, a diff means a real change.
        $this->travelTo(Carbon::parse('2026-01-15 09:00:00'));

        // Every numeric `throttle:N,1` here shares ONE cache key once a user
        // is authenticated - Laravel's default ThrottleRequests signature is
        // sha1($user->id) alone, not the route, so all eleven authenticated
        // calls below count against whichever limit is smallest (10, on the
        // search:write routes) regardless of which endpoint they hit. That is
        // abuse protection, not part of the API contract this test pins, so
        // it is switched off for this capture rather than making the fixture
        // count fragile against the request-throttle numbers.
        $this->withoutMiddleware(ThrottleRequests::class);

        $fixtures = $this->buildFixtures();

        foreach ($fixtures as $name => $payload) {
            $path = base_path(self::FIXTURE_DIR."/{$name}.json");
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

            if (env('UPDATE_CONTRACT_FIXTURES')) {
                File::ensureDirectoryExists(dirname($path));
                File::put($path, $json);

                continue;
            }

            $this->assertFileExists($path, "Missing contract fixture {$name}.json. Regenerate with UPDATE_CONTRACT_FIXTURES=1.");
            $this->assertSame(
                File::get($path),
                $json,
                "The API response for {$name} no longer matches the committed fixture. If the change is intended, regenerate with UPDATE_CONTRACT_FIXTURES=1 and update the Zod schema.",
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFixtures(): array
    {
        $user = User::factory()->create([
            'name' => 'Fixture User',
            'email' => 'fixtures@example.test',
            'password' => bcrypt('password'),
        ]);

        /*
         * The fixture search belongs to an import, so the coverage fixture has
         * something real to describe. A second, unrun search under the same
         * import gives coverage both a with_images row and a not_run one -
         * six zeroes would pin nothing.
         */
        $import = $this->csvImport($user, ['total_rows' => 2, 'unique_combos' => 2]);

        $search = $this->search($user, ['status' => 'completed', 'csv_import_id' => $import->id]);

        $this->search($user, [
            'status' => 'pending',
            'csv_import_id' => $import->id,
            'make' => 'Honda',
            'model' => 'Civic',
        ]);

        $reviewed = $this->image($search, [
            'provider_image_id' => 'fixture-a',
            'title' => 'File:Toyota RAV4 1997 front.jpg',
            'source_url' => 'https://upload.wikimedia.org/fixture-a.jpg',
            'thumbnail_url' => 'https://upload.wikimedia.org/thumb/fixture-a.jpg',
            'description' => 'A first-generation RAV4, photographed from the front.',
            'color' => 'silver',
            'license' => 'CC BY-SA 4.0',
            'attribution' => 'Photo by A. Person, CC BY-SA 4.0',
            'make_confirmed' => true,
            'year_confirmed' => false,
            'review_status' => CarImage::REVIEW_APPROVED,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'download_status' => 'downloaded',
        ]);

        $pending = $this->image($search, [
            'provider_image_id' => 'fixture-b',
            'title' => 'File:Toyota RAV4 1997 rear.jpg',
            'source_url' => 'https://upload.wikimedia.org/fixture-b.jpg',
            'thumbnail_url' => null,
            'width' => null,
            'height' => null,
        ]);

        ErrorEvent::create([
            'context' => ErrorEvent::CONTEXT_SEARCH_RUN,
            'severity' => 'error',
            'message' => 'The search run failed.',
            'exception_class' => 'RuntimeException',
            'exception_message' => 'Connection timed out',
            'trace_excerpt' => "#0 /app/Services/Search/RunSearchQueryAction.php(48)\n#1 {main}",
            'details' => ['attempt' => 2],
            'car_search_id' => $search->id,
            'occurred_at' => now(),
        ]);

        // The fixture user, not a second factory user: me.json must describe
        // the same person reviewed_by points at, or the fixtures contradict
        // each other.
        Sanctum::actingAs($user, TokenAbilities::all());

        // Only the search-create fixture below reaches Wikimedia; every other
        // fixture request is served from the database and never touches HTTP.
        $this->fakeWikimediaSearchResults();

        return [
            'login' => $this->loginPayload(),
            'me' => $this->getJson('/api/v1/auth/me')->assertOk()->json(),
            'image' => $this->getJson("/api/v1/images/{$reviewed->id}")->assertOk()->json(),
            'images' => $this->getJson('/api/v1/images?per_page=1')->assertOk()->json(),
            'search' => $this->getJson("/api/v1/searches/{$search->id}")->assertOk()->json(),
            'searches' => $this->getJson('/api/v1/searches')->assertOk()->json(),
            'imports' => $this->getJson('/api/v1/imports?per_page=1')->assertOk()->json(),
            'import' => $this->getJson("/api/v1/imports/{$import->id}")->assertOk()->json(),
            'errors' => $this->getJson('/api/v1/errors')->assertOk()->json(),
            'health' => $this->getJson('/api/v1/health/summary')->assertOk()->json(),
            'validation-error' => $this->postJson('/api/v1/searches', [
                'make' => 'Toyota',
                'from_year' => 1990,
                'to_year' => 2020,
            ])->assertStatus(422)->json(),
            'review' => $this->patchJson("/api/v1/images/{$pending->id}/review", [
                'review_status' => CarImage::REVIEW_REJECTED,
            ])->assertOk()->json(),
            // The one endpoint whose 201 populates `images`: SearchController
            // loads the relation only on the create-success path, so this is
            // the only fixture that can exercise ImageSchema nested inside a
            // populated SearchSchema.images array rather than as .optional().
            'search-create' => $this->postJson('/api/v1/searches', [
                'make' => 'Honda',
                'model' => 'CR-V',
                'from_year' => 1997,
                'to_year' => 1997,
                'images_per_year' => 2,
            ])->assertCreated()->json(),
        ];
    }

    /**
     * Fakes the two Commons endpoints RunSearchQueryAction calls for a fresh
     * search: the category probe (any `titles` request resolves) and the file
     * listing (one file, its title naming the search year so ModelYearMatcher
     * keeps it). Deterministic and offline - no live network call is made.
     */
    private function fakeWikimediaSearchResults(): void
    {
        Http::fake(function ($request) {
            $data = $request->data();

            if (isset($data['titles'])) {
                return Http::response(['query' => ['pages' => [[
                    'title' => $data['titles'],
                    'pageid' => 1,
                    'categoryinfo' => ['files' => 1, 'subcats' => 0],
                ]]]], 200);
            }

            return Http::response(['query' => ['pages' => [[
                'pageid' => 501,
                'title' => 'File:1997 Honda CR-V LX.jpg',
                'imageinfo' => [[
                    'url' => 'https://upload.wikimedia.org/fixture-create.jpg',
                    'thumburl' => 'https://upload.wikimedia.org/thumb/fixture-create.jpg',
                    'width' => 1024,
                    'height' => 768,
                    'mime' => 'image/jpeg',
                    'extmetadata' => [
                        'LicenseShortName' => ['value' => 'CC BY-SA 4.0'],
                        'Artist' => ['value' => 'Fixture Photographer'],
                        'ImageDescription' => ['value' => 'A 1997 Honda CR-V, photographed for the fixtures.'],
                    ],
                ]],
            ]]]], 200);
        });
    }

    /**
     * Login is the one unwrapped response, so it gets its own capture.
     *
     * @return array<string, mixed>
     */
    private function loginPayload(): array
    {
        $payload = $this->postJson('/api/v1/auth/login', [
            'email' => 'fixtures@example.test',
            'password' => 'password',
            'device_name' => 'fixture-device',
        ])->assertCreated()->json();

        $payload['token'] = self::TOKEN_PLACEHOLDER;

        return $payload;
    }
}
