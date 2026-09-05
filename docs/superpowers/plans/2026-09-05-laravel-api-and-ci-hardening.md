# Laravel API + CI Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the deploy pipeline safe for a monorepo, then build the `/api/v1` JSON API (Sanctum token auth, image/search/error reads, inline capped search creation, human image review) that the Expo app in Plan B will consume.

**Architecture:** Sanctum in API-token mode with per-token abilities; thin controllers under `App\Http\Controllers\Api\V1` that validate through Form Requests, authorise through permissive Policies, and serialise through API Resources in `App\Http\Resources\Api\V1`. Search creation reuses `CarImageSearchService` for dedupe/creation and `RunSearchQueryAction` for execution so failures are marked and logged exactly as the admin's bulk run does. All list endpoints use cursor pagination.

**Tech Stack:** PHP 8.3, Laravel 13.26, Filament 5, `laravel/sanctum:^4.0`, PHPUnit via `php artisan test`, Pint.

**Spec:** `docs/superpowers/specs/2026-09-05-react-native-mobile-client-design.md` — this is **Plan A** of four (CI + Laravel API). Plans B (Expo app + web deploy), C (APK), D (CSV/ZIP phase 3) follow.

## Global Constraints

- **Branch:** `feat/react-native-mobile-client` (already exists, holds the spec commit). Every task commits here.
- **Commit trailer** on every commit: `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`
- **Tests run only in the container.** Host PHP lacks `pdo_sqlite`. Everywhere a step says `IN_CONTAINER <cmd>` run exactly:
  ```bash
  docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -e COMPOSER_HOME=/tmp/composer -v "$PWD":/app -v "$HOME/.cache/composer-phar/composer.phar":/opt/composer.phar:ro -w /app cars-ci-php:8.3 <cmd>
  ```
  Composer inside the container is `php /opt/composer.phar` (never bare `composer`). Host `php artisan make:*`, `php artisan route:list`, `php artisan config:publish` and `vendor/bin/pint` work on the host.
- **Green baseline before Task 1:** 217 passed. Every task ends with the full suite green and `vendor/bin/pint --test` clean.
- **Every API route lives under `/api/v1`** and returns JSON. Tests use `getJson`/`postJson`/`patchJson`.
- **Throttles (spec):** login `5,1`; `POST searches` `10,1`; `PATCH review` `60,1`; logout `60,1`; all reads `120,1`. The `api` middleware group carries **no** throttle of its own (`Middleware::$apiLimiter` is unset), so these per-route values are the only limits in force.
- **Search caps (spec):** `|to_year - from_year| <= 3`, `images_per_year <= 5`, both read from `config('cars-images.*')`.
- **Token abilities (spec):** `search:read`, `search:write`, `review:write`, `errors:read`. Missing ability → `403`. No token → `401`.
- **Naming (spec):** API Resources in `App\Http\Resources\Api\V1\` named `ImageResource`, `SearchResource`, `ErrorResource`, `UserResource` — never `CarImageResource`, which collides with the Filament class. Form Requests in `App\Http\Requests\Api\V1\`. Controllers in `App\Http\Controllers\Api\V1\`.
- **Visibility (spec):** every authenticated user sees every record. Policies exist and return `true`.
- **`review_status` values:** `pending` | `approved` | `rejected`. Never write `make_confirmed`/`year_confirmed` from the API.
- **No CHANGELOG or README edits** — the last eight feature commits did not touch them; releases do.
- **Record-building in tests:** only `User` has a factory. Build `CarSearch`/`CarImage`/`ErrorEvent` with `::create([...])` through the helpers in `Tests\Feature\Api\ApiTestCase` (Task 2).

## File Structure

| Path | Responsibility |
|---|---|
| `.github/workflows/ci-cd.yml` | Laravel-only CI/deploy; ignores `mobile/**` |
| `.gitignore` | Mobile build artefacts |
| `bootstrap/app.php` | Registers `routes/api.php`, Sanctum `ability` middleware aliases |
| `routes/api.php` | The whole `/api/v1` route table — one file, grouped by ability |
| `app/Auth/TokenAbilities.php` | The four ability strings, one place |
| `app/Http/Controllers/Api/V1/Auth/{Login,Logout,Me}Controller.php` | Token issue/revoke/probe |
| `app/Http/Controllers/Api/V1/ImageController.php` | `index`, `show` |
| `app/Http/Controllers/Api/V1/ReviewImageController.php` | `PATCH images/{image}/review` |
| `app/Http/Controllers/Api/V1/SearchController.php` | `index`, `show`, `images`, `store` |
| `app/Http/Controllers/Api/V1/HealthSummaryController.php` | `GET health/summary` |
| `app/Http/Controllers/Api/V1/ErrorController.php` | `index` |
| `app/Http/Requests/Api/V1/*.php` | One Form Request per validated endpoint |
| `app/Http/Resources/Api/V1/*.php` | JSON shapes; the contract Plan B's Zod schemas mirror |
| `app/Policies/{CarImage,CarSearch}Policy.php` | Permissive, explicit |
| `app/Services/Health/PipelineHealthSummary.php` | The health numbers, independent of Filament widgets |
| `database/migrations/2026_09_05_100000_add_review_status_to_car_images_table.php` | Human review columns |
| `config/cars-images.php`, `.env.example` | API search caps, CORS origins |
| `config/cors.php` | Published; origins from env |
| `app/Filament/Pages/Results.php` | `review_status` column + filter |
| `tests/Feature/Api/ApiTestCase.php` | Shared auth + record helpers |
| `tests/Feature/Api/*Test.php` | One test class per endpoint group |

---

### Task 1: CI hardening

**Files:**
- Modify: `.github/workflows/ci-cd.yml:3-7` (triggers), `:140-142` (deploy filter), `:266-271` (comment above `git reset --hard`)
- Modify: `.gitignore` (append)

**Interfaces:**
- Consumes: nothing
- Produces: a deploy filter regex that later plans rely on to keep `mobile/**` and `.github/workflows/mobile.yml` out of the SiteGround deploy

- [ ] **Step 1: Write the failing shell test**

Create `/tmp/claude-1000/-home-allan-code-laravel-cars-images-api/a6906c9c-2076-45ea-961c-38258ec604cb/scratchpad/deploy-filter-test.sh` (any path outside the repo is fine — this file is never committed):

```bash
#!/usr/bin/env bash
# Extracts the real deploy-filter regex from ci-cd.yml and checks it
# against the change sets that matter.
set -u
re=$(sed -nE "s/.*grep -qvE '([^']+)'.*/\1/p" .github/workflows/ci-cd.yml)
[ -n "$re" ] || { echo "could not extract regex"; exit 1; }
echo "regex: $re"

check() { # $1 = label, $2 = expected (deploy|skip), $3 = newline-separated paths
  if printf '%s\n' "$3" | grep -qvE "$re"; then got=deploy; else got=skip; fi
  if [ "$got" = "$2" ]; then echo "PASS $1 -> $got"; else echo "FAIL $1 -> $got (want $2)"; fail=1; fi
}
fail=0
check "laravel only"        deploy "app/Models/User.php"
check "docs only"           skip   "docs/x.md"
check "mobile only"         skip   "mobile/app/index.tsx"
check "mobile workflow"     skip   ".github/workflows/mobile.yml"
check "mixed laravel+mobile" deploy $'mobile/app/index.tsx\napp/Models/User.php'
check "mobile + docs"       skip   $'mobile/app/index.tsx\ndocs/x.md'
exit $fail
```

- [ ] **Step 2: Run it to verify it fails**

Run: `bash /tmp/claude-1000/-home-allan-code-laravel-cars-images-api/a6906c9c-2076-45ea-961c-38258ec604cb/scratchpad/deploy-filter-test.sh`
Expected: `FAIL mobile only -> deploy (want skip)`, `FAIL mobile workflow -> deploy (want skip)`, `FAIL mobile + docs -> deploy (want skip)`; exit code 1.

- [ ] **Step 3: Stop the workflow starting for mobile-only pushes**

In `.github/workflows/ci-cd.yml` replace lines 3–7:

```yaml
on:
  push:
    branches: [main]
  pull_request:
  workflow_dispatch:
```

with:

```yaml
on:
  push:
    branches: [main]
    # The Expo app under mobile/ has its own workflow (mobile.yml). Filtering
    # here, at the workflow level, means no runner starts for a mobile-only
    # push. Note that a skipped workflow reports "pending", not "success" -
    # never make a job in this file a required status check, or mobile-only
    # pull requests become unmergeable.
    paths-ignore:
      - 'mobile/**'
      - '.github/workflows/mobile.yml'
  pull_request:
    paths-ignore:
      - 'mobile/**'
      - '.github/workflows/mobile.yml'
  workflow_dispatch:
```

- [ ] **Step 4: Harden the deploy filter for mixed commits and manual runs**

Replace (currently lines 140–142):

```bash
          # Deployable if ANY changed path is not documentation. grep -v exits 0
          # when at least one line fails to match the docs pattern.
          if printf '%s\n' "$changed" | grep -qvE '\.md$|^docs/'; then
```

with:

```bash
          # Deployable if ANY changed path is neither documentation nor the
          # Expo app. grep -v exits 0 when at least one line fails to match.
          #
          # The paths-ignore on the triggers already stops a mobile-only push
          # from starting this workflow at all. What this filter adds is the
          # push that mixes only docs/ and mobile/ changes: docs/ is not in
          # paths-ignore, so the workflow starts, and without ^mobile/ here
          # that push would count as deployable. A commit touching Laravel
          # and mobile/ together still deploys, since Laravel changed.
          #
          # workflow_dispatch never reaches this line: the branch above treats
          # any non-push event as deployable, because a manual run is a
          # deliberate deploy. Nothing here guards a manual run.
          if printf '%s\n' "$changed" | grep -qvE '\.md$|^docs/|^mobile/|^\.github/workflows/mobile\.yml$'; then
```

- [ ] **Step 5: Record why `mobile/` is allowed onto the server**

Directly above the line `          git reset --hard origin/main` (line 271) insert:

```bash
          # This checkout includes mobile/ (the Expo app). It is a few MB of
          # TypeScript that PHP never executes; sparse-checkout would keep it
          # off the box at the cost of a manual server-side step that breaks
          # deploys confusingly when forgotten. Accepted deliberately - see
          # docs/superpowers/specs/2026-09-05-react-native-mobile-client-design.md.
```

- [ ] **Step 6: Ignore mobile build artefacts**

Append to `.gitignore`:

```gitignore

# Expo app (mobile/) - only source is tracked; builds and native
# projects are regenerated by `npx expo` / EAS.
/mobile/node_modules
/mobile/.expo
/mobile/dist
/mobile/android
/mobile/ios
```

- [ ] **Step 7: Run the shell test to verify it passes**

Run: `bash /tmp/claude-1000/-home-allan-code-laravel-cars-images-api/a6906c9c-2076-45ea-961c-38258ec604cb/scratchpad/deploy-filter-test.sh`
Expected: six `PASS` lines, exit 0.

- [ ] **Step 8: Confirm the YAML still parses and nothing else changed**

Run: `git diff --stat && php -r '$y = file_get_contents(".github/workflows/ci-cd.yml"); echo substr_count($y, "paths-ignore:") === 2 ? "ok\n" : "paths-ignore count wrong\n";'`
Expected: exactly two files in the stat (`.github/workflows/ci-cd.yml`, `.gitignore`); `ok`.

- [ ] **Step 9: Commit**

```bash
git add .github/workflows/ci-cd.yml .gitignore
git commit -m "ci: keep the Expo app out of the Laravel test and deploy pipeline

The deploy filter treated every non-docs path as deployable and the
workflow ran on every push, so a change to a .tsx file under mobile/
would have cycled production through maintenance mode. A workflow-level
paths-ignore stops the runner starting for mobile-only pushes; the
deploy filter additionally excludes ^mobile/ so mixed commits and manual
runs deploy only when Laravel actually changed.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---
### Task 2: Sanctum + auth endpoints (login, logout, me)

**Files:**
- Generated by `install:api`: `composer.json`, `composer.lock`, `config/sanctum.php`, `database/migrations/<date>_create_personal_access_tokens_table.php`, `routes/api.php`, `bootstrap/app.php` (gains `api:` line)
- Modify: `app/Models/User.php`, `bootstrap/app.php` (middleware aliases), `routes/api.php` (replace stub)
- Create: `app/Auth/TokenAbilities.php`
- Create: `app/Http/Requests/Api/V1/LoginRequest.php`
- Create: `app/Http/Resources/Api/V1/UserResource.php`
- Create: `app/Http/Controllers/Api/V1/Auth/LoginController.php`, `LogoutController.php`, `MeController.php`
- Test: `tests/Feature/Api/ApiTestCase.php`, `tests/Feature/Api/AuthTest.php`

**Interfaces:**
- Consumes: `User` (factory password is `password`), `Laravel\Sanctum\HasApiTokens`
- Produces:
  - `App\Auth\TokenAbilities::SEARCH_READ|SEARCH_WRITE|REVIEW_WRITE|ERRORS_READ` (strings) and `TokenAbilities::all(): list<string>`
  - middleware aliases `ability:<name>` (any-of) and `abilities:<a>,<b>` (all-of)
  - `Tests\Feature\Api\ApiTestCase` with `actingAsApiUser(?array $abilities = null): User`, `asToken(string $plain): static`, `search(User $user, array $overrides = []): CarSearch`, `image(CarSearch $search, array $overrides = []): CarImage`
  - `App\Http\Resources\Api\V1\UserResource` → `{id, name, email}`
  - routes `api.v1.auth.login|logout|me`

- [ ] **Step 1: Make composer available to the container**

Run:
```bash
mkdir -p "$HOME/.cache/composer-phar" && cp /usr/local/bin/composer "$HOME/.cache/composer-phar/composer.phar" && chmod 755 "$HOME/.cache/composer-phar/composer.phar"
```
Expected: no output. (Host `/usr/local/bin/composer` cannot be bind-mounted directly — Docker Desktop's uid mapping presents it as a directory.)

- [ ] **Step 2: Install Sanctum and the API routes file**

Run: `IN_CONTAINER php artisan install:api --composer=/opt/composer.phar --without-migration-prompt`
Expected: composer requires `laravel/sanctum:^4.0` (this takes a minute — `post-autoload-dump` also runs `filament:upgrade`, which is normal), then `Published API routes file.` and `API scaffolding installed. Please add the [Laravel\Sanctum\HasApiTokens] trait to your User model.`

- [ ] **Step 3: Verify what the installer changed**

Run:
```bash
grep -n "api:" bootstrap/app.php; ls database/migrations | grep personal_access_tokens; grep -n '"laravel/sanctum"' composer.json; ls config/sanctum.php routes/api.php
```
Expected: `api: __DIR__.'/../routes/api.php',` on the line after `web:`; one migration file; `"laravel/sanctum": "^4.0"`; both files listed. If `bootstrap/app.php` did **not** gain the `api:` line, add it by hand directly under the `web:` line.

- [ ] **Step 4: Write the shared API test base**

Create `tests/Feature/Api/ApiTestCase.php`:

```php
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
```

- [ ] **Step 5: Write the failing auth tests**

Create `tests/Feature/Api/AuthTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;
use App\Models\User;

class AuthTest extends ApiTestCase
{
    private const LOGIN = ['email' => 'allan@example.com', 'password' => 'password', 'device_name' => 'Pixel 8'];

    public function test_login_returns_a_scoped_bearer_token(): void
    {
        $user = User::factory()->create(['email' => 'allan@example.com']);

        $response = $this->postJson('/api/v1/auth/login', self::LOGIN);

        $response->assertCreated()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('abilities', TokenAbilities::all())
            ->assertJsonPath('user.email', 'allan@example.com');
        $this->assertNotEmpty($response->json('token'));

        $token = $user->tokens()->sole();
        $this->assertSame('Pixel 8', $token->name);
        $this->assertSame(TokenAbilities::all(), $token->abilities);
    }

    public function test_login_rejects_a_wrong_password_without_issuing_a_token(): void
    {
        $user = User::factory()->create(['email' => 'allan@example.com']);

        $this->postJson('/api/v1/auth/login', ['password' => 'wrong'] + self::LOGIN)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_login_requires_a_device_name(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'a@b.c', 'password' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device_name']);
    }

    public function test_login_is_throttled_after_five_attempts(): void
    {
        User::factory()->create(['email' => 'allan@example.com']);
        $bad = ['password' => 'wrong'] + self::LOGIN;

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', $bad)->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', $bad)->assertStatus(429);
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_me_returns_the_user_behind_a_real_bearer_token(): void
    {
        $user = User::factory()->create();
        $plain = $user->createToken('Pixel 8', TokenAbilities::all())->plainTextToken;

        $this->asToken($plain)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonMissingPath('data.password');
    }

    public function test_logout_revokes_only_the_calling_token(): void
    {
        $user = User::factory()->create();
        $phone = $user->createToken('phone', TokenAbilities::all())->plainTextToken;
        $laptop = $user->createToken('laptop', TokenAbilities::all())->plainTextToken;

        $this->asToken($phone)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->asToken($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->asToken($laptop)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame(1, $user->tokens()->count());
    }
}
```

- [ ] **Step 6: Run the tests to verify they fail**

Run: `IN_CONTAINER php artisan test tests/Feature/Api/AuthTest.php`
Expected: errors — `Class "App\Auth\TokenAbilities" not found` (the test base cannot even load).

- [ ] **Step 7: Give the user model tokens**

In `app/Models/User.php` add the import `use Laravel\Sanctum\HasApiTokens;` (alphabetically, after `use Illuminate\Notifications\Notifiable;`) and change the trait line to:

```php
    use HasApiTokens, HasFactory, Notifiable;
```

- [ ] **Step 8: Register the ability middleware aliases**

In `bootstrap/app.php` replace:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
```

with:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum's ability checks are opt-in aliases. `ability:search:read`
        // rejects, with 403, a token that was issued without that ability -
        // the mobile web build keeps its token in localStorage, so a stolen
        // token must be bounded by what it was minted for.
        $middleware->alias([
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);
    })
```

- [ ] **Step 9: Define the abilities in one place**

Create `app/Auth/TokenAbilities.php`:

```php
<?php

namespace App\Auth;

/**
 * What a personal access token may do. Routes name these in their
 * `ability:` middleware and the login endpoint mints tokens with all four;
 * keeping the strings here means a typo is a fatal error, not a silent 403.
 */
final class TokenAbilities
{
    public const SEARCH_READ = 'search:read';

    public const SEARCH_WRITE = 'search:write';

    public const REVIEW_WRITE = 'review:write';

    public const ERRORS_READ = 'errors:read';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::SEARCH_READ, self::SEARCH_WRITE, self::REVIEW_WRITE, self::ERRORS_READ];
    }
}
```

- [ ] **Step 10: Write the request, resource and controllers**

Create `app/Http/Requests/Api/V1/LoginRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            // Names the token (Settings -> "Pixel 8", "Chrome on laptop") so
            // one device can be revoked without touching the others.
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }
}
```

Create `app/Http/Resources/Api/V1/UserResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
```

Create `app/Http/Controllers/Api/V1/Auth/LoginController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\TokenAbilities;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * Exchange credentials for a bearer token carrying every ability.
     */
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            // One message for "no such account" and "wrong password", so the
            // endpoint cannot be used to discover which emails exist.
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $token = $user->createToken($credentials['device_name'], TokenAbilities::all());

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => TokenAbilities::all(),
            'user' => UserResource::make($user)->resolve(),
        ], 201);
    }
}
```

Create `app/Http/Controllers/Api/V1/Auth/LogoutController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LogoutController extends Controller
{
    /**
     * Revoke the token that made this request - and only that one.
     */
    public function __invoke(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
```

Create `app/Http/Controllers/Api/V1/Auth/MeController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * The app's "is my token still good?" probe.
     */
    public function __invoke(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }
}
```

- [ ] **Step 11: Replace the routes stub**

Overwrite `routes/api.php` (the installer's stub is a single `/user` closure) with:

```php
<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use Illuminate\Support\Facades\Route;

/*
 * Versioned from the first commit. The mobile client is built against /v1;
 * a breaking change ships as /v2 beside it rather than in place.
 *
 * The `api` middleware group applies no throttle of its own, so every limit
 * in force is the one named on the route.
 */
Route::prefix('v1')->name('api.v1.')->group(function () {
    // The one route the open internet can reach - hence the tightest limit.
    Route::post('auth/login', LoginController::class)
        ->middleware('throttle:5,1')
        ->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', LogoutController::class)
            ->middleware('throttle:60,1')
            ->name('auth.logout');

        Route::get('auth/me', MeController::class)
            ->middleware('throttle:120,1')
            ->name('auth.me');
    });
});
```

- [ ] **Step 12: Run the auth tests to verify they pass**

Run: `IN_CONTAINER php artisan test tests/Feature/Api/AuthTest.php`
Expected: 7 passed.

- [ ] **Step 13: Run the whole suite and Pint**

Run: `IN_CONTAINER php artisan test && vendor/bin/pint --test`
Expected: 224 passed (217 + 7), `PASS` from Pint. If Pint reports files, run `vendor/bin/pint` and re-check.

- [ ] **Step 14: Commit**

```bash
git add composer.json composer.lock config/sanctum.php database/migrations bootstrap/app.php routes/api.php app/Models/User.php app/Auth app/Http/Requests app/Http/Resources app/Http/Controllers/Api tests/Feature/Api
git commit -m "feat(api): issue and revoke Sanctum bearer tokens

The mobile client cannot use Filament's session cookie, so this adds
Sanctum in API-token mode: POST /api/v1/auth/login exchanges credentials
for a per-device token carrying the four abilities the API will check,
logout revokes only the calling token, and /auth/me lets the app probe
whether its token is still valid. Login is throttled to 5/min because it
is the one endpoint reachable without a token.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---
### Task 3: `review_status` schema, model, policies

**Files:**
- Create: `database/migrations/2026_09_05_100000_add_review_status_to_car_images_table.php`
- Modify: `app/Models/CarImage.php`
- Create: `app/Policies/CarImagePolicy.php`, `app/Policies/CarSearchPolicy.php`
- Test: `tests/Feature/Api/ReviewStatusSchemaTest.php`

**Interfaces:**
- Consumes: `ApiTestCase::search()`, `ApiTestCase::image()`
- Produces:
  - columns `car_images.review_status` (string 16, default `pending`), `reviewed_by` (nullable FK users, null on delete), `reviewed_at` (nullable timestamp)
  - `CarImage::REVIEW_PENDING|REVIEW_APPROVED|REVIEW_REJECTED`, `CarImage::reviewStatuses(): list<string>`, `CarImage::reviewer(): BelongsTo`
  - `CarImagePolicy::viewAny|view|update`, `CarSearchPolicy::viewAny|view|create` — all `true`; auto-discovered by name

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Api/ReviewStatusSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;

class ReviewStatusSchemaTest extends ApiTestCase
{
    public function test_a_new_image_is_pending_review_with_no_reviewer(): void
    {
        $user = User::factory()->create();

        $image = $this->image($this->search($user))->refresh();

        $this->assertSame(CarImage::REVIEW_PENDING, $image->review_status);
        $this->assertNull($image->reviewed_by);
        $this->assertNull($image->reviewed_at);
        $this->assertNull($image->reviewer);
    }

    public function test_reviewer_is_a_user_relation_and_reviewed_at_is_a_date(): void
    {
        $user = User::factory()->create();

        $image = $this->image($this->search($user), [
            'review_status' => CarImage::REVIEW_APPROVED,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        $this->assertTrue($image->reviewer->is($user));
        $this->assertInstanceOf(CarbonInterface::class, $image->reviewed_at);
    }

    public function test_deleting_the_reviewer_keeps_the_verdict(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $image = $this->image($this->search($owner), [
            'review_status' => CarImage::REVIEW_REJECTED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $reviewer->delete();

        $image->refresh();
        $this->assertSame(CarImage::REVIEW_REJECTED, $image->review_status);
        $this->assertNull($image->reviewed_by);
    }

    public function test_policies_let_any_user_see_and_review_everything(): void
    {
        $user = User::factory()->create();
        $search = $this->search(User::factory()->create());
        $image = $this->image($search);

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', CarImage::class));
        $this->assertTrue(Gate::forUser($user)->allows('view', $image));
        $this->assertTrue(Gate::forUser($user)->allows('update', $image));
        $this->assertTrue(Gate::forUser($user)->allows('viewAny', CarSearch::class));
        $this->assertTrue(Gate::forUser($user)->allows('view', $search));
        $this->assertTrue(Gate::forUser($user)->allows('create', CarSearch::class));
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `IN_CONTAINER php artisan test tests/Feature/Api/ReviewStatusSchemaTest.php`
Expected: 4 failures — `Undefined constant App\Models\CarImage::REVIEW_PENDING` and, for the policy test, `false` where `true` was expected (no policy → Gate denies).

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_09_05_100000_add_review_status_to_car_images_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The human verdict on an image.
     *
     * Kept apart from make_confirmed / year_confirmed, which
     * MakeRelevanceChecker writes at harvest time. Overwriting the machine's
     * guess with the reviewer's decision would destroy the one measure of how
     * often the checker is right - and the disagreements are exactly the rows
     * worth reviewing first.
     */
    public function up(): void
    {
        Schema::table('car_images', function (Blueprint $table) {
            $table->string('review_status', 16)->default('pending')->index()->after('year_confirmed');
            // nullOnDelete: a reviewer leaving must not un-review their work.
            $table->foreignId('reviewed_by')->nullable()->after('review_status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('car_images', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['review_status', 'reviewed_at']);
        });
    }
};
```

- [ ] **Step 4: Teach the model the new columns**

In `app/Models/CarImage.php`:

Add after `use HasFactory;`:

```php
    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';
```

In `$fillable`, after `'year_confirmed',` add:

```php
        'review_status',
        'reviewed_by',
        'reviewed_at',
```

In `casts()`, after `'year_confirmed' => 'boolean',` add:

```php
            'reviewed_at' => 'datetime',
```

After the `search()` method add:

```php
    /**
     * @return list<string>
     */
    public static function reviewStatuses(): array
    {
        return [self::REVIEW_PENDING, self::REVIEW_APPROVED, self::REVIEW_REJECTED];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
```

- [ ] **Step 5: Write the policies**

Create `app/Policies/CarImagePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\CarImage;
use App\Models\User;

/**
 * Deliberately permissive. The admin panel shows every record to every
 * signed-in user and the API matches it. The class exists so that
 * visibility is a decision written down here rather than an accident of
 * having no policy - tightening it later is a one-file change.
 */
class CarImagePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CarImage $image): bool
    {
        return true;
    }

    public function update(User $user, CarImage $image): bool
    {
        return true;
    }
}
```

Create `app/Policies/CarSearchPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\CarSearch;
use App\Models\User;

/**
 * Deliberately permissive - see CarImagePolicy.
 */
class CarSearchPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CarSearch $search): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }
}
```

Laravel discovers `App\Policies\CarImagePolicy` for `App\Models\CarImage` by name; no registration needed.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `IN_CONTAINER php artisan test tests/Feature/Api/ReviewStatusSchemaTest.php`
Expected: 4 passed.

- [ ] **Step 7: Run the whole suite and Pint**

Run: `IN_CONTAINER php artisan test && vendor/bin/pint --test`
Expected: 228 passed; Pint `PASS`.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_09_05_100000_add_review_status_to_car_images_table.php app/Models/CarImage.php app/Policies tests/Feature/Api/ReviewStatusSchemaTest.php
git commit -m "feat(images): record a human review verdict beside the machine's

make_confirmed and year_confirmed are written only by
MakeRelevanceChecker, so the app has never had a place for a person to
approve or reject an image. review_status / reviewed_by / reviewed_at
add one without touching the machine columns: overwriting the checker's
guess would lose the only measure of how often it is right.

Policies are added for CarImage and CarSearch. They allow everything,
matching the admin panel; they exist so that decision is explicit.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---
### Task 4: Image reads — `GET /images`, `GET /images/{image}`

**Files:**
- Create: `app/Http/Requests/Api/V1/Concerns/PaginatesWithCursor.php`
- Create: `app/Http/Requests/Api/V1/ListImagesRequest.php`
- Create: `app/Http/Resources/Api/V1/ImageResource.php`
- Create: `app/Http/Controllers/Api/V1/ImageController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/ImagesTest.php`

**Interfaces:**
- Consumes: `TokenAbilities::SEARCH_READ`, `CarImagePolicy`, `CarImage::reviewStatuses()`, `ApiTestCase` helpers
- Produces:
  - trait `PaginatesWithCursor` with `paginationRules(): array` (`per_page` 1–100, `cursor` string) and `perPage(int $default = 24): int`
  - `ListImagesRequest::apply(Builder $query): Builder` — applies every validated filter; reused by Task 5
  - `ImageResource` JSON: `id, car_search_id, make, model, year, color, title, description, source_url, thumbnail_url, width, height, license, attribution, make_confirmed, year_confirmed, review_status, reviewed_by, reviewed_at, download_status, created_at`
  - list responses: `{ data: [...], links: {...}, meta: { path, per_page, next_cursor, prev_cursor } }`
  - routes `api.v1.images.index|show`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Api/ImagesTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;
use App\Models\CarImage;

class ImagesTest extends ApiTestCase
{
    public function test_listing_requires_a_token(): void
    {
        $this->getJson('/api/v1/images')->assertUnauthorized();
    }

    public function test_listing_requires_the_search_read_ability(): void
    {
        $this->actingAsApiUser([TokenAbilities::ERRORS_READ]);

        $this->getJson('/api/v1/images')->assertForbidden();
    }

    public function test_the_list_is_cursor_paginated_newest_first(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $search = $this->search($user);
        $a = $this->image($search);
        $b = $this->image($search);
        $c = $this->image($search);

        $first = $this->getJson('/api/v1/images?per_page=2')->assertOk();
        $this->assertSame([$c->id, $b->id], $first->json('data.*.id'));
        $cursor = $first->json('meta.next_cursor');
        $this->assertNotNull($cursor);

        $second = $this->getJson("/api/v1/images?per_page=2&cursor={$cursor}")->assertOk();
        $this->assertSame([$a->id], $second->json('data.*.id'));
        $this->assertNull($second->json('meta.next_cursor'));
    }

    public function test_each_filter_narrows_the_list(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $toyota = $this->search($user);
        $honda = $this->search($user, ['make' => 'Honda', 'model' => 'Civic', 'from_year' => 2010, 'to_year' => 2010]);

        $hit = $this->image($toyota, [
            'make_confirmed' => true, 'year_confirmed' => true,
            'review_status' => CarImage::REVIEW_APPROVED, 'download_status' => 'downloaded',
        ]);
        $miss = $this->image($honda, [
            'make_confirmed' => false, 'year_confirmed' => false,
            'review_status' => CarImage::REVIEW_REJECTED, 'download_status' => 'failed',
        ]);
        // Neither confirmed nor denied: a boolean filter must never match it.
        $this->image($honda);

        foreach ([
            'make=Toyota', 'model=RAV4', 'year=1997', 'make_confirmed=1', 'year_confirmed=true',
            'review_status=approved', 'download_status=downloaded',
        ] as $query) {
            $ids = $this->getJson("/api/v1/images?{$query}")->assertOk()->json('data.*.id');
            $this->assertSame([$hit->id], $ids, "filter {$query}");
        }

        $ids = $this->getJson('/api/v1/images?make_confirmed=0')->assertOk()->json('data.*.id');
        $this->assertSame([$miss->id], $ids, 'false must exclude the unknown (null) verdict');
    }

    public function test_an_invalid_filter_is_rejected(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);

        $this->getJson('/api/v1/images?review_status=maybe')->assertUnprocessable()
            ->assertJsonValidationErrors(['review_status']);
        $this->getJson('/api/v1/images?per_page=500')->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_show_returns_the_full_record(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $image = $this->image($this->search($user), [
            'license' => 'CC BY-SA 4.0',
            'attribution' => 'Photo by Example',
            'make_confirmed' => true,
            'review_status' => CarImage::REVIEW_APPROVED,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        $this->getJson("/api/v1/images/{$image->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $image->id)
            ->assertJsonPath('data.license', 'CC BY-SA 4.0')
            ->assertJsonPath('data.attribution', 'Photo by Example')
            ->assertJsonPath('data.make_confirmed', true)
            ->assertJsonPath('data.review_status', 'approved')
            ->assertJsonPath('data.reviewed_by', $user->id)
            ->assertJsonStructure(['data' => [
                'id', 'car_search_id', 'make', 'model', 'year', 'color', 'title', 'description',
                'source_url', 'thumbnail_url', 'width', 'height', 'license', 'attribution',
                'make_confirmed', 'year_confirmed', 'review_status', 'reviewed_by', 'reviewed_at',
                'download_status', 'created_at',
            ]]);
    }

    public function test_show_404s_for_an_unknown_image(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);

        $this->getJson('/api/v1/images/999999')->assertNotFound();
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `IN_CONTAINER php artisan test tests/Feature/Api/ImagesTest.php`
Expected: every test fails with `404` — the routes do not exist yet, and an unmatched path 404s before authentication runs.

- [ ] **Step 3: Write the pagination trait**

Create `app/Http/Requests/Api/V1/Concerns/PaginatesWithCursor.php`:

```php
<?php

namespace App\Http\Requests\Api\V1\Concerns;

/**
 * Cursor pagination for every list endpoint.
 *
 * Cursors, not page numbers: the mobile grid is an infinite scroll, and a
 * cursor neither skips nor repeats rows when a harvest inserts while the
 * user is mid-list. Offset pagination does both.
 */
trait PaginatesWithCursor
{
    /**
     * @return array<string, list<string>>
     */
    protected function paginationRules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ];
    }

    public function perPage(int $default = 24): int
    {
        return (int) ($this->validated()['per_page'] ?? $default);
    }
}
```

- [ ] **Step 4: Write the list request with its filter logic**

Create `app/Http/Requests/Api/V1/ListImagesRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\PaginatesWithCursor;
use App\Models\CarImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListImagesRequest extends FormRequest
{
    use PaginatesWithCursor;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'make' => ['sometimes', 'string', 'max:255'],
            'model' => ['sometimes', 'string', 'max:255'],
            'year' => ['sometimes', 'integer', 'min:1900', 'max:'.((int) date('Y') + 1)],
            'make_confirmed' => ['sometimes', 'boolean'],
            'year_confirmed' => ['sometimes', 'boolean'],
            'review_status' => ['sometimes', Rule::in(CarImage::reviewStatuses())],
            'download_status' => ['sometimes', Rule::in(['not_downloaded', 'downloading', 'downloaded', 'failed'])],
        ] + $this->paginationRules();
    }

    /**
     * Narrow a CarImage query by every filter present in the request.
     *
     * Lives on the request rather than the controller so the same filters
     * serve both /images and /searches/{search}/images.
     */
    public function apply(Builder $query): Builder
    {
        $filters = $this->validated();

        foreach (['make', 'model', 'year', 'review_status', 'download_status'] as $column) {
            if (array_key_exists($column, $filters)) {
                $query->where($column, $filters[$column]);
            }
        }

        // A boolean filter matches its value only. The nullable "unknown"
        // verdict is neither true nor false and never matches - the same
        // rule the Filament filters apply.
        foreach (['make_confirmed', 'year_confirmed'] as $column) {
            if (array_key_exists($column, $filters)) {
                $query->where($column, $this->boolean($column));
            }
        }

        return $query;
    }
}
```

- [ ] **Step 5: Write the resource**

Create `app/Http/Resources/Api/V1/ImageResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The image as the mobile client sees it. Field names are the column names;
 * the Zod schema on the client mirrors this list exactly.
 */
class ImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'car_search_id' => $this->car_search_id,
            'make' => $this->make,
            'model' => $this->model,
            'year' => $this->year,
            'color' => $this->color,
            'title' => $this->title,
            'description' => $this->description,
            'source_url' => $this->source_url,
            'thumbnail_url' => $this->thumbnail_url,
            'width' => $this->width,
            'height' => $this->height,
            'license' => $this->license,
            'attribution' => $this->attribution,
            'make_confirmed' => $this->make_confirmed,
            'year_confirmed' => $this->year_confirmed,
            'review_status' => $this->review_status,
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'download_status' => $this->download_status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 6: Write the controller**

Create `app/Http/Controllers/Api/V1/ImageController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListImagesRequest;
use App\Http\Resources\Api\V1\ImageResource;
use App\Models\CarImage;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ImageController extends Controller
{
    public function index(ListImagesRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CarImage::class);

        $images = $request->apply(CarImage::query())
            // Newest first on a unique column, so the cursor stays stable
            // while a harvest is inserting.
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return ImageResource::collection($images);
    }

    public function show(CarImage $image): ImageResource
    {
        Gate::authorize('view', $image);

        return ImageResource::make($image);
    }
}
```

- [ ] **Step 7: Register the routes**

In `routes/api.php` add the import `use App\Http\Controllers\Api\V1\ImageController;` and `use App\Auth\TokenAbilities;` (keep imports alphabetical), then inside the `auth:sanctum` group, after the `auth/me` route, add:

```php
        Route::middleware(['ability:'.TokenAbilities::SEARCH_READ, 'throttle:120,1'])->group(function () {
            Route::get('images', [ImageController::class, 'index'])->name('images.index');
            Route::get('images/{image}', [ImageController::class, 'show'])->name('images.show');
        });
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `IN_CONTAINER php artisan test tests/Feature/Api/ImagesTest.php`
Expected: 7 passed.

- [ ] **Step 9: Run the whole suite and Pint**

Run: `IN_CONTAINER php artisan test && vendor/bin/pint --test`
Expected: 235 passed; Pint `PASS`.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Requests/Api/V1 app/Http/Resources/Api/V1/ImageResource.php app/Http/Controllers/Api/V1/ImageController.php routes/api.php tests/Feature/Api/ImagesTest.php
git commit -m "feat(api): list and show images with cursor pagination

GET /api/v1/images is the mobile browse grid: filterable by make, model,
year, both machine verdicts, review status and download status, and
cursor-paginated newest-first so an infinite scroll neither skips nor
repeats rows while a harvest inserts. The filter logic lives on the form
request so the per-search image list can reuse it.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---
### Task 5: Search reads — `GET /searches`, `GET /searches/{search}`, `GET /searches/{search}/images`

**Files:**
- Create: `app/Http/Requests/Api/V1/ListSearchesRequest.php`
- Create: `app/Http/Resources/Api/V1/SearchResource.php`
- Create: `app/Http/Controllers/Api/V1/SearchController.php` (`index`, `show`, `images`; `store` arrives in Task 6)
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/SearchesTest.php`

**Interfaces:**
- Consumes: `PaginatesWithCursor`, `ListImagesRequest::apply()`, `ImageResource`, `CarSearchPolicy`
- Produces:
  - `SearchResource` JSON: `id, make, model, commons_category, from_year, to_year, color, transmission, transparent_background, images_per_year, status, csv_import_id, requested_by, images_count (when counted), images (when loaded), created_at, updated_at`
  - routes `api.v1.searches.index|show|images`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Api/SearchesTest.php`:

```php
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
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `IN_CONTAINER php artisan test tests/Feature/Api/SearchesTest.php`
Expected: 5 failures, all `404`.

- [ ] **Step 3: Write the list request**

Create `app/Http/Requests/Api/V1/ListSearchesRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\PaginatesWithCursor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListSearchesRequest extends FormRequest
{
    use PaginatesWithCursor;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(['pending', 'running', 'completed', 'failed'])],
        ] + $this->paginationRules();
    }
}
```

- [ ] **Step 4: Write the resource**

Create `app/Http/Resources/Api/V1/SearchResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SearchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'make' => $this->make,
            'model' => $this->model,
            'commons_category' => $this->commons_category,
            'from_year' => $this->from_year,
            'to_year' => $this->to_year,
            'color' => $this->color,
            'transmission' => $this->transmission,
            'transparent_background' => $this->transparent_background,
            'images_per_year' => $this->images_per_year,
            'status' => $this->status,
            'csv_import_id' => $this->csv_import_id,
            'requested_by' => $this->requested_by,
            'images_count' => $this->whenCounted('images'),
            'images' => ImageResource::collection($this->whenLoaded('images')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 5: Write the controller (read methods only)**

Create `app/Http/Controllers/Api/V1/SearchController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListImagesRequest;
use App\Http\Requests\Api\V1\ListSearchesRequest;
use App\Http\Resources\Api\V1\ImageResource;
use App\Http\Resources\Api\V1\SearchResource;
use App\Models\CarSearch;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SearchController extends Controller
{
    public function index(ListSearchesRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CarSearch::class);

        $status = $request->validated()['status'] ?? null;

        $searches = CarSearch::query()
            ->withCount('images')
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return SearchResource::collection($searches);
    }

    public function show(CarSearch $search): SearchResource
    {
        Gate::authorize('view', $search);

        return SearchResource::make($search->loadCount('images'));
    }

    public function images(ListImagesRequest $request, CarSearch $search): AnonymousResourceCollection
    {
        Gate::authorize('view', $search);

        $images = $request->apply($search->images()->getQuery())
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return ImageResource::collection($images);
    }
}
```

- [ ] **Step 6: Register the routes**

In `routes/api.php` add `use App\Http\Controllers\Api\V1\SearchController;` and, inside the `SEARCH_READ` group after the two image routes:

```php
            Route::get('searches', [SearchController::class, 'index'])->name('searches.index');
            Route::get('searches/{search}', [SearchController::class, 'show'])->name('searches.show');
            Route::get('searches/{search}/images', [SearchController::class, 'images'])->name('searches.images');
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `IN_CONTAINER php artisan test tests/Feature/Api/SearchesTest.php`
Expected: 5 passed.

- [ ] **Step 8: Run the whole suite and Pint**

Run: `IN_CONTAINER php artisan test && vendor/bin/pint --test`
Expected: 240 passed; Pint `PASS`.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Requests/Api/V1/ListSearchesRequest.php app/Http/Resources/Api/V1/SearchResource.php app/Http/Controllers/Api/V1/SearchController.php routes/api.php tests/Feature/Api/SearchesTest.php
git commit -m "feat(api): list and show search runs and their images

GET /api/v1/searches is the run list for the mobile app - newest first,
filterable by status, each row carrying its image count so the list can
say \"ran, found nothing\" without a second request. The per-search image
list reuses the /images filters.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---
### Task 6: Search create — `POST /searches`, inline and capped

**Files:**
- Modify: `config/cars-images.php` (new section), `.env.example` (two vars)
- Create: `app/Http/Requests/Api/V1/StoreSearchRequest.php`
- Modify: `app/Http/Controllers/Api/V1/SearchController.php` (add `store` + helper)
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/SearchCreateTest.php`

**Interfaces:**
- Consumes: `CarImageSearchService::findExistingCompletedSearch(make, model, fromYear, toYear, color, transmission, transparent, imagesPerYear): ?CarSearch`, `CarImageSearchService::createSearch(User, …same eight…): CarSearch`, `RunSearchQueryAction::execute(CarSearch): void` (marks `failed`, logs an `error_events` row, re-throws), `WikimediaBlockedException::$retryAfterSeconds`
- Produces:
  - `config('cars-images.api_search_max_year_span')` = 3, `config('cars-images.api_search_max_images_per_year')` = 5
  - `POST /api/v1/searches` → `201 {data: search+images}` on completion; `200` when an identical completed search already exists; `503 {message, retry_after_seconds, data}` + `Retry-After` header when Wikimedia blocks; `502 {message, data}` on any other failure. In every case `data.status` is the row's real status.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Api/SearchCreateTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;
use App\Models\ErrorEvent;
use App\Models\WikimediaBlockEvent;
use Illuminate\Support\Facades\Http;

class SearchCreateTest extends ApiTestCase
{
    private const PAYLOAD = ['make' => 'Toyota', 'model' => 'RAV4', 'from_year' => 1997, 'to_year' => 1997, 'images_per_year' => 5];

    private function fakeEmptyWikimedia(): void
    {
        Http::fake(['*' => Http::response(['query' => ['search' => []]], 200)]);
    }

    public function test_creating_requires_the_search_write_ability(): void
    {
        $this->postJson('/api/v1/searches', self::PAYLOAD)->assertUnauthorized();

        $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $this->postJson('/api/v1/searches', self::PAYLOAD)->assertForbidden();
    }

    public function test_a_year_span_wider_than_the_cap_is_rejected(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_WRITE]);

        $this->postJson('/api/v1/searches', ['from_year' => 2018, 'to_year' => 2022] + self::PAYLOAD)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to_year']);

        // Three years apart (four inclusive) is the widest allowed.
        $this->fakeEmptyWikimedia();
        $this->postJson('/api/v1/searches', ['from_year' => 2018, 'to_year' => 2021] + self::PAYLOAD)
            ->assertCreated();
    }

    public function test_more_images_per_year_than_the_cap_is_rejected(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_WRITE]);

        $this->postJson('/api/v1/searches', ['images_per_year' => 6] + self::PAYLOAD)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['images_per_year']);
    }

    public function test_a_search_is_created_run_inline_and_returned_completed(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_WRITE]);
        $this->fakeEmptyWikimedia();

        $response = $this->postJson('/api/v1/searches', self::PAYLOAD);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.make', 'Toyota')
            ->assertJsonPath('data.requested_by', $user->id)
            ->assertJsonPath('data.images_count', 0)
            ->assertJsonPath('data.images', []);
    }

    public function test_reversed_years_are_stored_in_order(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_WRITE]);
        $this->fakeEmptyWikimedia();

        $this->postJson('/api/v1/searches', ['from_year' => 1999, 'to_year' => 1997] + self::PAYLOAD)
            ->assertCreated()
            ->assertJsonPath('data.from_year', 1997)
            ->assertJsonPath('data.to_year', 1999);
    }

    public function test_an_identical_completed_search_is_returned_without_touching_wikimedia(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_WRITE]);
        $existing = $this->search($user); // Toyota RAV4 1997, 5/yr, completed
        Http::fake();

        $this->postJson('/api/v1/searches', self::PAYLOAD)
            ->assertOk()
            ->assertJsonPath('data.id', $existing->id);

        Http::assertNothingSent();
    }

    public function test_a_wikimedia_block_answers_503_with_retry_after(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_WRITE]);
        Http::fake(['*' => Http::response('Rate limit exceeded', 429, ['Retry-After' => '120'])]);

        $response = $this->postJson('/api/v1/searches', self::PAYLOAD);

        $response->assertStatus(503)
            ->assertHeader('Retry-After', '120')
            ->assertJsonPath('retry_after_seconds', 120)
            ->assertJsonPath('data.status', 'failed');
        $this->assertSame(1, WikimediaBlockEvent::count());
        $this->assertSame(1, ErrorEvent::where('context', ErrorEvent::CONTEXT_WIKIMEDIA_BLOCK)->count());
    }

    public function test_any_other_failure_answers_502_and_is_logged(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_WRITE]);
        Http::fake(['*' => Http::response('Internal server error', 500)]);

        $this->postJson('/api/v1/searches', self::PAYLOAD)
            ->assertStatus(502)
            ->assertJsonPath('data.status', 'failed');

        $this->assertSame(0, WikimediaBlockEvent::count());
        $this->assertSame(1, ErrorEvent::where('context', ErrorEvent::CONTEXT_SEARCH_RUN)->count());
    }

    public function test_creation_is_throttled_to_ten_per_minute(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_WRITE]);
        $this->fakeEmptyWikimedia();

        // First creates; the next nine hit the dedupe path. All ten count.
        for ($i = 0; $i < 10; $i++) {
            $this->assertContains($this->postJson('/api/v1/searches', self::PAYLOAD)->status(), [200, 201]);
        }

        $this->postJson('/api/v1/searches', self::PAYLOAD)->assertStatus(429);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `IN_CONTAINER php artisan test tests/Feature/Api/SearchCreateTest.php`
Expected: 9 failures — the first test's second assertion and everything after it get `405 Method Not Allowed` (GET `searches` exists, POST does not).

- [ ] **Step 3: Add the caps to config and `.env.example`**

In `config/cars-images.php`, before the closing `];`, add:

```php

    /*
    |--------------------------------------------------------------------------
    | JSON API
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/searches runs the search inside the HTTP request - there is
    | no queue worker on shared hosting - so it must finish inside PHP's
    | max_execution_time. These caps bound what one API call may ask for. The
    | admin panel keeps its own, wider limits; a search too big for the API
    | is still available from the panel.
    */

    'api_search_max_year_span' => env('API_SEARCH_MAX_YEAR_SPAN', 3),

    'api_search_max_images_per_year' => env('API_SEARCH_MAX_IMAGES_PER_YEAR', 5),
```

Append to `.env.example`:

```dotenv

# JSON API (mobile client). Caps on an inline search; see config/cars-images.php.
API_SEARCH_MAX_YEAR_SPAN=3
API_SEARCH_MAX_IMAGES_PER_YEAR=5
```

- [ ] **Step 4: Write the request**

Create `app/Http/Requests/Api/V1/StoreSearchRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Mirrors the admin form's rules, plus the API caps.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $maxYear = (int) date('Y') + 1;

        return [
            'make' => ['required', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'from_year' => ['required', 'integer', 'min:1900', "max:{$maxYear}"],
            'to_year' => ['required', 'integer', 'min:1900', "max:{$maxYear}"],
            'color' => ['nullable', 'string', 'max:64'],
            'transmission' => ['nullable', 'string', 'max:64'],
            'transparent_background' => ['sometimes', 'boolean'],
            'images_per_year' => [
                'sometimes', 'integer', 'min:1',
                'max:'.(int) config('cars-images.api_search_max_images_per_year'),
            ],
        ];
    }

    /**
     * The span rule needs both years, so it runs after the field rules.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $span = (int) config('cars-images.api_search_max_year_span');
            $width = abs((int) $this->input('to_year') - (int) $this->input('from_year'));

            if ($width > $span) {
                $validator->errors()->add(
                    'to_year',
                    "The year range may span at most {$span} years, because the search runs inside this request.",
                );
            }
        });
    }

    /**
     * The eight positional arguments CarImageSearchService takes, years in
     * ascending order so dedupe and creation see the same key.
     *
     * @return array{string, ?string, int, int, ?string, ?string, bool, int}
     */
    public function searchArguments(): array
    {
        $data = $this->validated();
        $from = (int) $data['from_year'];
        $to = (int) $data['to_year'];

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            $data['make'],
            $data['model'] ?? null,
            $from,
            $to,
            $data['color'] ?? null,
            $data['transmission'] ?? null,
            (bool) ($data['transparent_background'] ?? false),
            (int) ($data['images_per_year'] ?? config('cars-images.api_search_max_images_per_year')),
        ];
    }
}
```

- [ ] **Step 5: Add `store` to the controller**

In `app/Http/Controllers/Api/V1/SearchController.php` add these imports:

```php
use App\Exceptions\WikimediaBlockedException;
use App\Http\Requests\Api\V1\StoreSearchRequest;
use App\Services\Images\CarImageSearchService;
use App\Services\Search\RunSearchQueryAction;
use Illuminate\Http\JsonResponse;
use Throwable;
```

and, after `images()`, these two methods:

```php
    /**
     * Create a search and run it inside this request.
     *
     * There is no queue worker on shared hosting, so this is the same inline
     * path the admin's single-row action takes - through RunSearchQueryAction,
     * which marks the row `failed` and writes an error_events row before
     * re-throwing. StoreSearchRequest caps the size so it finishes in time.
     */
    public function store(
        StoreSearchRequest $request,
        CarImageSearchService $service,
        RunSearchQueryAction $runner,
    ): JsonResponse {
        Gate::authorize('create', CarSearch::class);

        $arguments = $request->searchArguments();

        // Same dedupe as the admin form. An identical completed search is
        // handed back rather than re-run - which also spares Wikimedia.
        $existing = $service->findExistingCompletedSearch(...$arguments);

        if ($existing !== null) {
            return $this->searchResponse($existing, 200);
        }

        $search = $service->createSearch($request->user(), ...$arguments);

        try {
            $runner->execute($search);
        } catch (WikimediaBlockedException $e) {
            return $this->searchResponse($search, 503, [
                'message' => 'Wikimedia is rate-limiting this server. Try again later.',
                'retry_after_seconds' => $e->retryAfterSeconds,
            ])->withHeaders(array_filter(['Retry-After' => $e->retryAfterSeconds]));
        } catch (Throwable) {
            return $this->searchResponse($search, 502, [
                'message' => 'The search failed. The reason is in the error log.',
            ]);
        }

        return $this->searchResponse($search, 201);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function searchResponse(CarSearch $search, int $status, array $extra = []): JsonResponse
    {
        $search->load('images')->loadCount('images');

        return response()->json($extra + ['data' => SearchResource::make($search)->resolve()], $status);
    }
```

- [ ] **Step 6: Register the route**

In `routes/api.php`, inside the `auth:sanctum` group but **outside** the `SEARCH_READ` group (after its closing `});`), add:

```php
        // Reaches Wikimedia, which has blocked this app before: the tightest
        // authenticated limit, on top of the size caps in StoreSearchRequest.
        Route::post('searches', [SearchController::class, 'store'])
            ->middleware(['ability:'.TokenAbilities::SEARCH_WRITE, 'throttle:10,1'])
            ->name('searches.store');
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `IN_CONTAINER php artisan test tests/Feature/Api/SearchCreateTest.php`
Expected: 9 passed.

- [ ] **Step 8: Run the whole suite and Pint**

Run: `IN_CONTAINER php artisan test && vendor/bin/pint --test`
Expected: 249 passed; Pint `PASS`.

- [ ] **Step 9: Commit**

```bash
git add config/cars-images.php .env.example app/Http/Requests/Api/V1/StoreSearchRequest.php app/Http/Controllers/Api/V1/SearchController.php routes/api.php tests/Feature/Api/SearchCreateTest.php
git commit -m "feat(api): run an ad-hoc search inline from POST /searches

The queue driver is sync and there is no worker, so the endpoint runs
the search inside the request the way the admin's single-row action
does - via RunSearchQueryAction, so a failure is marked and logged the
same way. Two config caps (year span 3, images per year 5) keep it
inside max_execution_time on shared hosting, an identical completed
search is returned instead of re-run, and a Wikimedia block comes back
as 503 with Retry-After rather than a bare 500.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---
### Task 7: Review write — `PATCH /images/{image}/review`

**Files:**
- Create: `app/Http/Requests/Api/V1/ReviewImageRequest.php`
- Create: `app/Http/Controllers/Api/V1/ReviewImageController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/ReviewImageTest.php`

**Interfaces:**
- Consumes: `CarImage::reviewStatuses()`, `CarImagePolicy::update`, `ImageResource`, `TokenAbilities::REVIEW_WRITE`
- Produces: `PATCH /api/v1/images/{image}/review {review_status}` → `200 {data: image}`; `approved`/`rejected` stamp `reviewed_by` + `reviewed_at`; `pending` clears both; never touches `make_confirmed`/`year_confirmed`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Api/ReviewImageTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;
use App\Models\CarImage;

class ReviewImageTest extends ApiTestCase
{
    public function test_reviewing_requires_the_review_write_ability(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_WRITE]);
        $image = $this->image($this->search($user));

        $this->patchJson("/api/v1/images/{$image->id}/review", ['review_status' => 'approved'])
            ->assertForbidden();
    }

    public function test_approving_stamps_the_reviewer_and_leaves_the_machine_verdict_alone(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::REVIEW_WRITE]);
        $image = $this->image($this->search($user), ['make_confirmed' => false, 'year_confirmed' => false]);

        $this->patchJson("/api/v1/images/{$image->id}/review", ['review_status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.review_status', 'approved')
            ->assertJsonPath('data.reviewed_by', $user->id)
            ->assertJsonPath('data.make_confirmed', false);

        $image->refresh();
        $this->assertSame(CarImage::REVIEW_APPROVED, $image->review_status);
        $this->assertSame($user->id, $image->reviewed_by);
        $this->assertNotNull($image->reviewed_at);
        $this->assertFalse($image->make_confirmed, 'the human verdict must not overwrite the machine one');
        $this->assertFalse($image->year_confirmed);
    }

    public function test_rejecting_works_the_same_way(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::REVIEW_WRITE]);
        $image = $this->image($this->search($user));

        $this->patchJson("/api/v1/images/{$image->id}/review", ['review_status' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('data.review_status', 'rejected')
            ->assertJsonPath('data.reviewed_by', $user->id);
    }

    public function test_returning_to_pending_clears_the_reviewer(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::REVIEW_WRITE]);
        $image = $this->image($this->search($user), [
            'review_status' => CarImage::REVIEW_APPROVED, 'reviewed_by' => $user->id, 'reviewed_at' => now(),
        ]);

        $this->patchJson("/api/v1/images/{$image->id}/review", ['review_status' => 'pending'])
            ->assertOk()
            ->assertJsonPath('data.review_status', 'pending')
            ->assertJsonPath('data.reviewed_by', null)
            ->assertJsonPath('data.reviewed_at', null);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::REVIEW_WRITE]);
        $image = $this->image($this->search($user));

        $this->patchJson("/api/v1/images/{$image->id}/review", ['review_status' => 'meh'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['review_status']);
        $this->patchJson("/api/v1/images/{$image->id}/review", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['review_status']);

        $this->assertSame(CarImage::REVIEW_PENDING, $image->fresh()->review_status);
    }

    public function test_an_unknown_image_404s(): void
    {
        $this->actingAsApiUser([TokenAbilities::REVIEW_WRITE]);

        $this->patchJson('/api/v1/images/999999/review', ['review_status' => 'approved'])->assertNotFound();
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `IN_CONTAINER php artisan test tests/Feature/Api/ReviewImageTest.php`
Expected: 6 failures, all `404` (no route).

- [ ] **Step 3: Write the request**

Create `app/Http/Requests/Api/V1/ReviewImageRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\CarImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'review_status' => ['required', Rule::in(CarImage::reviewStatuses())],
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

Create `app/Http/Controllers/Api/V1/ReviewImageController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReviewImageRequest;
use App\Http\Resources\Api\V1\ImageResource;
use App\Models\CarImage;
use Illuminate\Support\Facades\Gate;

class ReviewImageController extends Controller
{
    /**
     * Record the human verdict. Only the three review columns change;
     * make_confirmed / year_confirmed belong to MakeRelevanceChecker.
     */
    public function __invoke(ReviewImageRequest $request, CarImage $image): ImageResource
    {
        Gate::authorize('update', $image);

        $status = $request->validated()['review_status'];

        // "Pending" is the absence of a verdict, so it carries no reviewer.
        $image->forceFill($status === CarImage::REVIEW_PENDING
            ? ['review_status' => $status, 'reviewed_by' => null, 'reviewed_at' => null]
            : ['review_status' => $status, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]
        )->save();

        return ImageResource::make($image);
    }
}
```

- [ ] **Step 5: Register the route**

In `routes/api.php` add `use App\Http\Controllers\Api\V1\ReviewImageController;` and, inside the `auth:sanctum` group after the `searches.store` route:

```php
        Route::patch('images/{image}/review', ReviewImageController::class)
            ->middleware(['ability:'.TokenAbilities::REVIEW_WRITE, 'throttle:60,1'])
            ->name('images.review');
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `IN_CONTAINER php artisan test tests/Feature/Api/ReviewImageTest.php`
Expected: 6 passed.

- [ ] **Step 7: Run the whole suite and Pint**

Run: `IN_CONTAINER php artisan test && vendor/bin/pint --test`
Expected: 255 passed; Pint `PASS`.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Api/V1/ReviewImageRequest.php app/Http/Controllers/Api/V1/ReviewImageController.php routes/api.php tests/Feature/Api/ReviewImageTest.php
git commit -m "feat(api): let a reviewer approve or reject an image

PATCH /api/v1/images/{image}/review records the human verdict with who
made it and when. Returning an image to pending clears both, since
pending is the absence of a verdict. The machine columns are never
written from here.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---
### Task 8: Surface `review_status` in the Filament Results table

**Files:**
- Modify: `app/Filament/Pages/Results.php` (one column after `year_confirmed`, one filter after the `year_confirmed` filter)
- Test: `tests/Feature/Filament/ResultsReviewStatusTest.php`

**Interfaces:**
- Consumes: `CarImage::REVIEW_*`, `CarImage::reviewStatuses()`
- Produces: a `Review` badge column and a `Review` select filter on the Results page, so the web admin and the mobile app agree on what is approved

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/ResultsReviewStatusTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Results;
use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Results page lists only csv-imported images, so every fixture here
 * hangs off an import - the same shape ResultsPageTest uses.
 */
class ResultsReviewStatusTest extends TestCase
{
    use RefreshDatabase;

    private function importedImage(User $user, CsvImport $import, string $reviewStatus): CarImage
    {
        $id = uniqid('img-', true);

        $search = CarSearch::create([
            'make' => 'Toyota', 'model' => 'RAV4', 'from_year' => 1997, 'to_year' => 1997,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'completed', 'requested_by' => $user->id, 'csv_import_id' => $import->id,
        ]);

        return CarImage::create([
            'car_search_id' => $search->id, 'provider' => 'wikimedia', 'provider_image_id' => $id,
            'make' => 'Toyota', 'model' => 'RAV4', 'year' => 1997, 'title' => "File:{$id}.jpg",
            'source_url' => "https://upload.wikimedia.org/{$id}.jpg",
            'thumbnail_url' => "https://upload.wikimedia.org/thumb/{$id}.jpg",
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded',
            'review_status' => $reviewStatus,
        ]);
    }

    public function test_the_review_column_renders_and_its_filter_narrows_to_one_verdict(): void
    {
        $user = User::factory()->create();
        $import = CsvImport::create([
            'original_filename' => 's.csv', 'total_rows' => 2,
            'unique_combos' => 2, 'duplicates_skipped' => 0, 'imported_by' => $user->id,
        ]);
        $approved = $this->importedImage($user, $import, CarImage::REVIEW_APPROVED);
        $pending = $this->importedImage($user, $import, CarImage::REVIEW_PENDING);

        Livewire::actingAs($user)
            ->test(Results::class)
            ->assertCanRenderTableColumn('review_status')
            ->assertCanSeeTableRecords([$approved, $pending])
            ->filterTable('review_status', CarImage::REVIEW_APPROVED)
            ->assertCanSeeTableRecords([$approved])
            ->assertCanNotSeeTableRecords([$pending]);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `IN_CONTAINER php artisan test tests/Feature/Filament/ResultsReviewStatusTest.php`
Expected: 1 failure — `Failed asserting that a table column with name [review_status] exists`.

- [ ] **Step 3: Add the column**

In `app/Filament/Pages/Results.php`, directly after the `year_confirmed` column (the one ending `->tooltip('"Not year-specific" means ...'),`) and before the closing `])` of `->columns([`, add:

```php
                Tables\Columns\TextColumn::make('review_status')
                    ->label('Review')
                    ->visibleFrom('sm')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        CarImage::REVIEW_APPROVED => 'success',
                        CarImage::REVIEW_REJECTED => 'danger',
                        default => 'gray',
                    })
                    ->tooltip('The reviewer\'s verdict, set from the mobile app. Independent of the make/year matches, which are the machine\'s.'),
```

- [ ] **Step 4: Add the filter**

Directly after the `year_confirmed` `SelectFilter` (the one whose `->query()` closure ends with `return $query;` then `}),`), add:

```php
                SelectFilter::make('review_status')
                    ->label('Review')
                    ->options(array_combine(
                        CarImage::reviewStatuses(),
                        array_map('ucfirst', CarImage::reviewStatuses()),
                    )),
```

`SelectFilter` on a real column filters `where review_status = <value>` on its own; no `->query()` needed.

- [ ] **Step 5: Run the test to verify it passes**

Run: `IN_CONTAINER php artisan test tests/Feature/Filament/ResultsReviewStatusTest.php`
Expected: 1 passed.

- [ ] **Step 6: Run the whole suite and Pint**

Run: `IN_CONTAINER php artisan test && vendor/bin/pint --test`
Expected: 256 passed; Pint `PASS`. (`ResultsPageTest` and `ResultsScopingTest` must still pass — the new column and filter add nothing to the query by default.)

- [ ] **Step 7: Commit**

```bash
git add app/Filament/Pages/Results.php tests/Feature/Filament/ResultsReviewStatusTest.php
git commit -m "feat(admin): show the reviewer's verdict on the Results table

A Review badge and filter beside the machine's make/year matches, so
what the mobile app approved is visible where the ZIP is built.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---
### Task 9: Pipeline health — `GET /health/summary`, `GET /errors`

**Files:**
- Create: `app/Services/Health/PipelineHealthSummary.php`
- Create: `app/Http/Controllers/Api/V1/HealthSummaryController.php`
- Create: `app/Http/Requests/Api/V1/ListErrorsRequest.php`
- Create: `app/Http/Resources/Api/V1/ErrorResource.php`
- Create: `app/Http/Controllers/Api/V1/ErrorController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/HealthTest.php`

**Interfaces:**
- Consumes: `ErrorEvent::contexts(): array<string,string>`, `ErrorEvent` (`$timestamps = false`, `occurred_at` cast to datetime), `PaginatesWithCursor`, `TokenAbilities::ERRORS_READ`
- Produces:
  - `PipelineHealthSummary::build(): array{searches_by_status: array<string,int>, errors_last_24h: int, errors_by_context_last_7d: array<string,int>, images_last_7d: int, latest_error_at: ?string}` — every status and every context key is always present, zero when empty
  - `ErrorResource` JSON: `id, context, severity, message, exception_class, exception_message, trace_excerpt, details, car_search_id, csv_import_id, car_image_id, occurred_at`
  - routes `api.v1.health.summary`, `api.v1.errors.index`

The Filament `PipelineHealthOverview` widget is left untouched (spec: no panel refactor beyond `review_status`); the summary service answers a different question - counts by status and by context - rather than duplicating the widget's stats.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Api/HealthTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;
use App\Models\ErrorEvent;

class HealthTest extends ApiTestCase
{
    private function error(string $context, \DateTimeInterface $occurredAt, string $severity = 'error'): ErrorEvent
    {
        return ErrorEvent::create([
            'context' => $context,
            'severity' => $severity,
            'message' => "{$context} broke",
            'occurred_at' => $occurredAt,
        ]);
    }

    public function test_both_endpoints_require_the_errors_read_ability(): void
    {
        $this->getJson('/api/v1/health/summary')->assertUnauthorized();
        $this->getJson('/api/v1/errors')->assertUnauthorized();

        $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $this->getJson('/api/v1/health/summary')->assertForbidden();
        $this->getJson('/api/v1/errors')->assertForbidden();
    }

    public function test_the_summary_counts_runs_errors_and_images_in_their_windows(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::ERRORS_READ]);
        $this->search($user, ['status' => 'completed']);
        $this->search($user, ['status' => 'completed']);
        $failed = $this->search($user, ['status' => 'failed']);
        $this->image($failed);
        $this->error(ErrorEvent::CONTEXT_SEARCH_RUN, now());
        $this->error(ErrorEvent::CONTEXT_WIKIMEDIA_BLOCK, now()->subDays(3));
        $this->error(ErrorEvent::CONTEXT_CSV_ROW, now()->subDays(10)); // outside every window

        $this->getJson('/api/v1/health/summary')
            ->assertOk()
            ->assertJsonPath('data.searches_by_status', ['pending' => 0, 'running' => 0, 'completed' => 2, 'failed' => 1])
            ->assertJsonPath('data.errors_last_24h', 1)
            ->assertJsonPath('data.errors_by_context_last_7d', [
                'csv_upload' => 0, 'csv_row' => 0, 'search_run' => 1, 'image_download' => 0, 'wikimedia_block' => 1,
            ])
            ->assertJsonPath('data.images_last_7d', 1)
            ->assertJsonPath('data.latest_error_at', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_an_empty_database_still_returns_every_key(): void
    {
        $this->actingAsApiUser([TokenAbilities::ERRORS_READ]);

        $this->getJson('/api/v1/health/summary')
            ->assertOk()
            ->assertJsonPath('data.searches_by_status.completed', 0)
            ->assertJsonPath('data.errors_by_context_last_7d.csv_upload', 0)
            ->assertJsonPath('data.latest_error_at', null);
    }

    public function test_errors_are_listed_newest_first_and_filterable(): void
    {
        $this->actingAsApiUser([TokenAbilities::ERRORS_READ]);
        $old = $this->error(ErrorEvent::CONTEXT_SEARCH_RUN, now()->subHour());
        $new = $this->error(ErrorEvent::CONTEXT_IMAGE_DOWNLOAD, now(), 'warning');

        $all = $this->getJson('/api/v1/errors')->assertOk();
        $this->assertSame([$new->id, $old->id], $all->json('data.*.id'));
        $all->assertJsonStructure([
            'data' => [['id', 'context', 'severity', 'message', 'exception_class', 'exception_message',
                'trace_excerpt', 'details', 'car_search_id', 'csv_import_id', 'car_image_id', 'occurred_at']],
            'meta' => ['next_cursor', 'prev_cursor', 'per_page'],
        ]);

        $this->assertSame([$old->id], $this->getJson('/api/v1/errors?context=search_run')->json('data.*.id'));
        $this->assertSame([$new->id], $this->getJson('/api/v1/errors?severity=warning')->json('data.*.id'));

        $this->getJson('/api/v1/errors?context=meteor_strike')->assertUnprocessable()
            ->assertJsonValidationErrors(['context']);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `IN_CONTAINER php artisan test tests/Feature/Api/HealthTest.php`
Expected: 4 failures, all `404`.

- [ ] **Step 3: Write the summary service**

Create `app/Services/Health/PipelineHealthSummary.php`:

```php
<?php

namespace App\Services\Health;

use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\ErrorEvent;

/**
 * The numbers the mobile health screen shows.
 *
 * Every key is always present so the client can render zeros rather than
 * special-case missing ones. Windows match the admin dashboard: "is the
 * pipeline broken now" is a different question from "has it ever been".
 */
class PipelineHealthSummary
{
    private const STATUSES = ['pending', 'running', 'completed', 'failed'];

    /**
     * @return array{
     *     searches_by_status: array<string, int>,
     *     errors_last_24h: int,
     *     errors_by_context_last_7d: array<string, int>,
     *     images_last_7d: int,
     *     latest_error_at: ?string
     * }
     */
    public function build(): array
    {
        $byStatus = CarSearch::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byContext = ErrorEvent::query()
            ->where('occurred_at', '>=', now()->subDays(7))
            ->selectRaw('context, COUNT(*) as total')
            ->groupBy('context')
            ->pluck('total', 'context');

        $searchesByStatus = [];
        foreach (self::STATUSES as $status) {
            $searchesByStatus[$status] = (int) ($byStatus[$status] ?? 0);
        }

        $errorsByContext = [];
        foreach (array_keys(ErrorEvent::contexts()) as $context) {
            $errorsByContext[$context] = (int) ($byContext[$context] ?? 0);
        }

        return [
            'searches_by_status' => $searchesByStatus,
            'errors_last_24h' => ErrorEvent::query()->where('occurred_at', '>=', now()->subDay())->count(),
            'errors_by_context_last_7d' => $errorsByContext,
            'images_last_7d' => CarImage::query()->where('created_at', '>=', now()->subWeek())->count(),
            'latest_error_at' => ErrorEvent::query()->orderByDesc('occurred_at')->first()?->occurred_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: Write the health controller**

Create `app/Http/Controllers/Api/V1/HealthSummaryController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Health\PipelineHealthSummary;
use Illuminate\Http\JsonResponse;

class HealthSummaryController extends Controller
{
    public function __invoke(PipelineHealthSummary $summary): JsonResponse
    {
        return response()->json(['data' => $summary->build()]);
    }
}
```

- [ ] **Step 5: Write the error list request, resource and controller**

Create `app/Http/Requests/Api/V1/ListErrorsRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\PaginatesWithCursor;
use App\Models\ErrorEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListErrorsRequest extends FormRequest
{
    use PaginatesWithCursor;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'context' => ['sometimes', Rule::in(array_keys(ErrorEvent::contexts()))],
            'severity' => ['sometimes', Rule::in(['error', 'warning'])],
        ] + $this->paginationRules();
    }
}
```

Create `app/Http/Resources/Api/V1/ErrorResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ErrorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'context' => $this->context,
            'severity' => $this->severity,
            'message' => $this->message,
            'exception_class' => $this->exception_class,
            'exception_message' => $this->exception_message,
            'trace_excerpt' => $this->trace_excerpt,
            'details' => $this->details,
            'car_search_id' => $this->car_search_id,
            'csv_import_id' => $this->csv_import_id,
            'car_image_id' => $this->car_image_id,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
```

Create `app/Http/Controllers/Api/V1/ErrorController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListErrorsRequest;
use App\Http\Resources\Api\V1\ErrorResource;
use App\Models\ErrorEvent;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ErrorController extends Controller
{
    public function index(ListErrorsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $errors = ErrorEvent::query()
            ->when(isset($filters['context']), fn ($query) => $query->where('context', $filters['context']))
            ->when(isset($filters['severity']), fn ($query) => $query->where('severity', $filters['severity']))
            // occurred_at alone is not unique; id breaks ties so the cursor
            // never skips two events logged in the same second.
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return ErrorResource::collection($errors);
    }
}
```

No policy here: `ErrorEvent` has no owner and no per-user view in Filament either; the `errors:read` ability is the gate.

- [ ] **Step 6: Register the routes**

In `routes/api.php` add `use App\Http\Controllers\Api\V1\ErrorController;` and `use App\Http\Controllers\Api\V1\HealthSummaryController;`, then inside the `auth:sanctum` group after the `images.review` route:

```php
        Route::middleware(['ability:'.TokenAbilities::ERRORS_READ, 'throttle:120,1'])->group(function () {
            Route::get('health/summary', HealthSummaryController::class)->name('health.summary');
            Route::get('errors', [ErrorController::class, 'index'])->name('errors.index');
        });
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `IN_CONTAINER php artisan test tests/Feature/Api/HealthTest.php`
Expected: 4 passed.

- [ ] **Step 8: Run the whole suite and Pint**

Run: `IN_CONTAINER php artisan test && vendor/bin/pint --test`
Expected: 260 passed; Pint `PASS`.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Health app/Http/Controllers/Api/V1/HealthSummaryController.php app/Http/Controllers/Api/V1/ErrorController.php app/Http/Requests/Api/V1/ListErrorsRequest.php app/Http/Resources/Api/V1/ErrorResource.php routes/api.php tests/Feature/Api/HealthTest.php
git commit -m "feat(api): expose pipeline health and the error log

GET /api/v1/health/summary answers \"is the pipeline broken now\" with
run counts by status and a week of errors by context, every key always
present; GET /api/v1/errors pages the error log newest first with the
same context and severity filters the admin table has.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---
### Task 10: CORS locked to the web build's origin

**Files:**
- Create (via `config:publish`): `config/cors.php`
- Modify: `config/cors.php`, `.env.example`
- Test: `tests/Feature/Api/CorsTest.php`

**Interfaces:**
- Consumes: `Illuminate\Http\Middleware\HandleCors` (already in the global middleware stack)
- Produces: `config('cors.allowed_origins')` read from `CORS_ALLOWED_ORIGINS` (comma-separated); empty by default, so nothing cross-origin is allowed until an origin is named

Without a published file Laravel falls back to the framework's `config/cors.php`, whose `allowed_origins` is `['*']` — every origin on the internet may call `api/*`. The native APK never sends an `Origin` header and is unaffected either way; this is for the browser build.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Api/CorsTest.php`:

```php
<?php

namespace Tests\Feature\Api;

class CorsTest extends ApiTestCase
{
    private const ORIGIN = 'https://cars-images.netlify.app';

    private function preflight(string $origin)
    {
        return $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/v1/auth/me');
    }

    public function test_nothing_is_allowed_until_an_origin_is_configured(): void
    {
        $this->assertSame([], config('cors.allowed_origins'));
    }

    public function test_the_configured_origin_gets_cors_headers(): void
    {
        config(['cors.allowed_origins' => [self::ORIGIN]]);

        $this->preflight(self::ORIGIN)
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', self::ORIGIN);
    }

    public function test_any_other_origin_does_not(): void
    {
        config(['cors.allowed_origins' => [self::ORIGIN]]);

        $this->preflight('https://evil.example')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `IN_CONTAINER php artisan test tests/Feature/Api/CorsTest.php`
Expected: `test_nothing_is_allowed_until_an_origin_is_configured` fails — `['*']` is not `[]`. The other two pass already (runtime config wins); they stay as the guard that the published file keeps working.

- [ ] **Step 3: Publish the config**

Run: `php artisan config:publish cors`
Expected: `config/cors.php` created.

- [ ] **Step 4: Read origins from the environment**

In `config/cors.php` replace:

```php
    'allowed_origins' => ['*'],
```

with:

```php
    /*
    | Comma-separated in CORS_ALLOWED_ORIGINS. Empty by default: the mobile
    | app's web build runs on its own origin (Netlify), and that is the only
    | browser that should be talking to api/*. The native build sends no
    | Origin header and is unaffected.
    */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
    ))),
```

Leave `paths`, `allowed_methods`, `allowed_headers` and the rest as published.

Append to `.env.example`:

```dotenv
# Browser origins allowed to call api/* (comma-separated). Local Expo web
# dev server by default; the Netlify URL in production.
CORS_ALLOWED_ORIGINS=http://localhost:8081
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `IN_CONTAINER php artisan test tests/Feature/Api/CorsTest.php`
Expected: 3 passed. (`phpunit.xml` sets no `CORS_ALLOWED_ORIGINS`, so the suite sees the empty default.)

- [ ] **Step 6: Run the whole suite and Pint**

Run: `IN_CONTAINER php artisan test && vendor/bin/pint --test`
Expected: 263 passed; Pint `PASS`.

- [ ] **Step 7: Commit**

```bash
git add config/cors.php .env.example tests/Feature/Api/CorsTest.php
git commit -m "feat(api): allow only the configured browser origin to call the API

Laravel's fallback CORS config allows every origin on api/*. Publish it
and read CORS_ALLOWED_ORIGINS instead, empty by default, so the only
browser that can call the API is the mobile app's own web build.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---
### Task 11: Final verification and hand-off

**Files:** none new.

**Interfaces:**
- Consumes: everything above
- Produces: a branch ready for `superpowers:finishing-a-development-branch`, and the route table Plan B's Zod schemas are written against

- [ ] **Step 1: Confirm the route table matches the spec**

Run: `php artisan route:list --path=api/v1 --columns=method,uri,name,middleware`
Expected: exactly these twelve routes (order may differ):

| Method | URI | Middleware includes |
|---|---|---|
| POST | `api/v1/auth/login` | `throttle:5,1` |
| POST | `api/v1/auth/logout` | `auth:sanctum`, `throttle:60,1` |
| GET | `api/v1/auth/me` | `auth:sanctum`, `throttle:120,1` |
| GET | `api/v1/images` | `ability:search:read`, `throttle:120,1` |
| GET | `api/v1/images/{image}` | `ability:search:read` |
| PATCH | `api/v1/images/{image}/review` | `ability:review:write`, `throttle:60,1` |
| GET | `api/v1/searches` | `ability:search:read` |
| POST | `api/v1/searches` | `ability:search:write`, `throttle:10,1` |
| GET | `api/v1/searches/{search}` | `ability:search:read` |
| GET | `api/v1/searches/{search}/images` | `ability:search:read` |
| GET | `api/v1/health/summary` | `ability:errors:read` |
| GET | `api/v1/errors` | `ability:errors:read` |

If any route is missing or carries the wrong throttle, fix `routes/api.php` and re-run.

- [ ] **Step 2: Run the whole suite one last time, from clean**

Run: `IN_CONTAINER php artisan test && vendor/bin/pint --test && git status --short`
Expected: 263 passed; Pint `PASS`; `git status` prints nothing (no stray files — in particular no `mobile/`, and `public/css/filament` etc. stay ignored).

- [ ] **Step 3: Check the branch history reads as a story**

Run: `git log --oneline main..HEAD`
Expected: eleven commits — the spec, then one per task 1–10, each a `ci:`/`feat(...)` line from this plan.

- [ ] **Step 4: Prove the API from the outside**

Run the app on the host — `php artisan serve --port=8000` in the background — then, with any existing admin user's credentials:

```bash
TOKEN=$(curl -s -X POST http://127.0.0.1:8000/api/v1/auth/login \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"<admin email>","password":"<password>","device_name":"curl"}' | php -r 'echo json_decode(stream_get_contents(STDIN))->token;')
curl -s http://127.0.0.1:8000/api/v1/health/summary -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```

Expected: a JSON `data` object with `searches_by_status`, `errors_last_24h`, `errors_by_context_last_7d`, `images_last_7d`, `latest_error_at`. (The host has no MySQL; point `DB_CONNECTION` at whatever your local `.env` already uses for the admin panel. If there is no local database at all, skip this step — the feature tests cover the same path.) Stop the server afterwards.

- [ ] **Step 5: Hand off**

Do not merge from here. Invoke `superpowers:finishing-a-development-branch` to choose between merging to `main` and opening a pull request. Note for the PR body: deploying this branch runs one migration (`review_status` on `car_images`) plus Sanctum's `personal_access_tokens` table, and production needs `CORS_ALLOWED_ORIGINS` set once the Netlify site exists (Plan B).
