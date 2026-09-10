# API Authorization Scoping Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the four privileged abilities P3–P5 will be gated on, and make
`POST /api/v1/auth/login` issue a token scoped to what the client asked for
rather than everything that exists.

**Architecture:** `TokenAbilities` splits its single list into `all()` (the
validation target, eight) and `defaultScope()` (what an unscoped request gets,
the legacy four). `LoginRequest` accepts an optional `abilities` array validated
against `all()`; `LoginController` intersects it in `all()`'s order, falling
back to `defaultScope()`. The mobile client's `z.enum` widens to eight so P3 can
request the new scopes without a parse error.

**Tech Stack:** Laravel 13, Sanctum, PHPUnit; Zod 4 on the client.

**Spec:** [`docs/superpowers/specs/2026-09-10-api-authorization-scoping-design.md`](../specs/2026-09-10-api-authorization-scoping-design.md)

## Global Constraints

- Laravel tests run in the `cars-ci-php:8.3` container — host PHP has no
  `pdo_sqlite`:
  `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test`
- **`vendor/bin/pint --test` is a CI gate.** Run it, not just the tests.
- Mobile tests run on the host, from `mobile/`.
- **`ContractFixturesTest` must pass without regenerating fixtures.** Its
  capture posts no `abilities`, so `login.json` must not change. A red run
  there means the default issuance widened — which is the exact bug this plan
  exists to prevent. Do **not** run `UPDATE_CONTRACT_FIXTURES=1` to make it
  green.
- No policies, no `/auth/me` change, no role column, no migration, no new
  endpoint, no UI.
- Never write `make_confirmed` or `year_confirmed` from any client.
- All mobile work stays inside `mobile/`; never add a dependency to the
  repository-root `package.json`.

## File Structure

**Modified**

| File | Change |
|---|---|
| `app/Auth/TokenAbilities.php` | Four new constants; `all()` returns eight; new `defaultScope()` returns the legacy four |
| `app/Http/Requests/Api/V1/LoginRequest.php` | Optional `abilities` array validated against `all()` |
| `app/Http/Controllers/Api/V1/Auth/LoginController.php` | Intersect the request against `all()`, else `defaultScope()`; echo the issued list |
| `tests/Feature/Api/AuthTest.php` | Seven new cases; existing login assertions retargeted at `defaultScope()` |
| `tests/Feature/Api/ApiTestCase.php` | Docblock only — "all four" stops being true |
| `mobile/src/api/schemas.ts` | `TokenAbilitySchema` widens to eight |
| `mobile/src/api/__tests__/schemas.test.ts` | One case: all eight strings parse |

**Unchanged, and asserted to be so:** `mobile/src/api/__fixtures__/login.json`.

---

### Task 1: Split `TokenAbilities` into a validation set and an issuance default

The whole design. One method was naming the valid set *and* naming what gets
issued, so widening it for validation would have widened what every deployed
client receives — and the deployed client's `z.enum` accepts exactly four.

**Files:**
- Modify: `app/Auth/TokenAbilities.php`
- Test: `tests/Feature/Api/AuthTest.php` (added to in Task 2; this task's
  behaviour is covered there)

**Interfaces:**
- Consumes: nothing.
- Produces: `TokenAbilities::IMPORTS_READ`, `::IMPORTS_WRITE`, `::SEARCH_RUN`,
  `::EXPORTS_READ`; `TokenAbilities::all(): list<string>` (eight, canonical
  order); `TokenAbilities::defaultScope(): list<string>` (the legacy four).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Api/AuthTest.php`:

```php
    public function test_the_default_scope_is_a_strict_subset_of_every_ability(): void
    {
        // The two lists do different jobs: all() is what a request is
        // validated against, defaultScope() is what an unscoped request is
        // issued. Collapsing them back into one method is the regression this
        // guards - it would widen what every deployed client receives.
        $this->assertNotSame(TokenAbilities::all(), TokenAbilities::defaultScope());
        $this->assertEmpty(array_diff(TokenAbilities::defaultScope(), TokenAbilities::all()));
    }

    public function test_the_privileged_abilities_are_not_issued_by_default(): void
    {
        $privileged = [
            TokenAbilities::IMPORTS_READ,
            TokenAbilities::IMPORTS_WRITE,
            TokenAbilities::SEARCH_RUN,
            TokenAbilities::EXPORTS_READ,
        ];

        foreach ($privileged as $ability) {
            $this->assertContains($ability, TokenAbilities::all());
            $this->assertNotContains($ability, TokenAbilities::defaultScope());
        }
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=AuthTest`
Expected: FAIL — `Error: Call to undefined method App\Auth\TokenAbilities::defaultScope()`

- [ ] **Step 3: Implement**

Replace `app/Auth/TokenAbilities.php` entirely:

```php
<?php

namespace App\Auth;

/**
 * What a personal access token may do. Routes name these in their
 * `ability:` middleware and the login endpoint mints a scoped subset;
 * keeping the strings here means a typo is a fatal error, not a silent 403.
 */
final class TokenAbilities
{
    public const SEARCH_READ = 'search:read';

    public const SEARCH_WRITE = 'search:write';

    public const REVIEW_WRITE = 'review:write';

    public const ERRORS_READ = 'errors:read';

    /*
     * Privileged. Not issued unless a client asks for them by name, because
     * these are the ones that must never sit in a web build's localStorage:
     * SEARCH_RUN drives an unbounded paced run against Wikimedia (the thing
     * wikimedia_block_events exists to record), EXPORTS_READ hands over the
     * library's metadata in one request, and IMPORTS_WRITE can seed hundreds
     * of queries. No route consumes them yet - the endpoints land in P3/P4.
     */
    public const IMPORTS_READ = 'imports:read';

    public const IMPORTS_WRITE = 'imports:write';

    public const SEARCH_RUN = 'search:run';

    public const EXPORTS_READ = 'exports:read';

    /**
     * Every ability that exists: the set a login request is validated and
     * intersected against.
     *
     * NOT what a token is issued by default - see defaultScope(). These two
     * are deliberately separate methods. When one method did both jobs,
     * adding an ability here would have widened what every already-deployed
     * client receives, and the mobile client parses `abilities` through a
     * Zod enum that accepts exactly the four in defaultScope().
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::SEARCH_READ,
            self::SEARCH_WRITE,
            self::REVIEW_WRITE,
            self::ERRORS_READ,
            self::IMPORTS_READ,
            self::IMPORTS_WRITE,
            self::SEARCH_RUN,
            self::EXPORTS_READ,
        ];
    }

    /**
     * What a client that asks for nothing gets.
     *
     * Exactly the four abilities that existed before scoping, so every
     * already-deployed client keeps precisely what it has today. That
     * compatibility is a side effect of a rule that is independently right:
     * privileged verbs are opt-in.
     *
     * @return list<string>
     */
    public static function defaultScope(): array
    {
        return [
            self::SEARCH_READ,
            self::SEARCH_WRITE,
            self::REVIEW_WRITE,
            self::ERRORS_READ,
        ];
    }
}
```

- [ ] **Step 4: Run the test and watch it pass**

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=AuthTest`
Expected: PASS.

**If other suites now fail:** `ApiTestCase::actingAsApiUser()` defaults to
`TokenAbilities::all()`, which is now eight rather than four. Extra abilities
never cause a 403, so nothing should break — tests that assert a 403 pass a
narrower set explicitly. Investigate rather than widening any assertion.

- [ ] **Step 5: Point login at the default scope**

Widening `all()` alone leaves the tree **red**: `LoginController` still mints
`all()`, so all four privileged abilities leak into the login response and
`ContractFixturesTest` fails on the byte comparison. That is the guard working,
and the fix belongs here rather than in Task 2 — issuing the default scope is
correct on its own, independent of client-declared scoping.

In `app/Http/Controllers/Api/V1/Auth/LoginController.php`:

```php
        // defaultScope(), not all(): all() is the set a request may be
        // validated against, and minting it here would hand every client the
        // privileged abilities it never asked for.
        $abilities = TokenAbilities::defaultScope();

        $token = $user->createToken($credentials['device_name'], $abilities);
```

with `'abilities' => $abilities` in the response and the docblock changed from
"carrying every ability" to "carrying the default scope".

Then retarget the two assertions in the existing
`test_login_returns_a_scoped_bearer_token`: `TokenAbilities::all()` becomes
`TokenAbilities::defaultScope()` in both.

- [ ] **Step 6: Correct the now-false docblock**

`tests/Feature/Api/ApiTestCase.php` says "Defaults to all four". Change that
sentence to:

```php
    /**
     * Authenticate a fresh user with the given abilities. Defaults to every
     * ability, so a test asserting a 403 must pass the narrower set on
     * purpose.
     */
```

- [ ] **Step 7: Pint, full suite, commit**

```bash
vendor/bin/pint
docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test
git add app/Auth/TokenAbilities.php app/Http/Controllers/Api/V1/Auth/LoginController.php tests/Feature/Api/AuthTest.php tests/Feature/Api/ApiTestCase.php
git commit -m "feat(api): split the token abilities into a validation set and an issuance default"
```

---

### Task 2: Scope the issued token to what the client asked for

**Files:**
- Modify: `app/Http/Requests/Api/V1/LoginRequest.php`
- Modify: `app/Http/Controllers/Api/V1/Auth/LoginController.php`
- Test: `tests/Feature/Api/AuthTest.php`

**Interfaces:**
- Consumes: `TokenAbilities::all()`, `TokenAbilities::defaultScope()` (Task 1).
- Produces: `POST /api/v1/auth/login` accepts an optional
  `abilities: list<string>`; the 201 body's `abilities` is the issued list,
  identical to the stored token's.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Api/AuthTest.php`:

```php
    public function test_login_without_abilities_issues_only_the_default_scope(): void
    {
        // The deployed-client guard. mobile/src/api/schemas.ts parses
        // `abilities` through a z.enum of exactly these four, so issuing a
        // wider set to a client that asked for nothing throws on parse and
        // breaks sign-in on the live web build.
        $user = User::factory()->create(['email' => 'allan@example.com']);

        $this->postJson('/api/v1/auth/login', self::LOGIN)
            ->assertCreated()
            ->assertJsonPath('abilities', TokenAbilities::defaultScope());

        $this->assertSame(TokenAbilities::defaultScope(), $user->tokens()->sole()->abilities);
    }

    public function test_login_issues_exactly_the_requested_subset(): void
    {
        $user = User::factory()->create(['email' => 'allan@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'abilities' => [TokenAbilities::SEARCH_READ, TokenAbilities::REVIEW_WRITE],
        ] + self::LOGIN)
            ->assertCreated()
            ->assertJsonPath('abilities', [TokenAbilities::SEARCH_READ, TokenAbilities::REVIEW_WRITE]);

        $this->assertSame(
            [TokenAbilities::SEARCH_READ, TokenAbilities::REVIEW_WRITE],
            $user->tokens()->sole()->abilities,
        );
    }

    public function test_login_grants_a_privileged_ability_when_it_is_asked_for(): void
    {
        // P3's native build depends on this: the privileged abilities are
        // withheld by default, not withheld outright.
        User::factory()->create(['email' => 'allan@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'abilities' => [TokenAbilities::SEARCH_READ, TokenAbilities::SEARCH_RUN],
        ] + self::LOGIN)
            ->assertCreated()
            ->assertJsonPath('abilities', [TokenAbilities::SEARCH_READ, TokenAbilities::SEARCH_RUN]);
    }

    public function test_login_canonicalises_the_order_and_collapses_duplicates(): void
    {
        // The issued list is intersected in all()'s order, not the request's,
        // so the response is byte-stable whatever order the client asked in -
        // which is what keeps the committed contract fixture stable.
        User::factory()->create(['email' => 'allan@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'abilities' => [
                TokenAbilities::REVIEW_WRITE,
                TokenAbilities::SEARCH_READ,
                TokenAbilities::REVIEW_WRITE,
            ],
        ] + self::LOGIN)
            ->assertCreated()
            ->assertJsonPath('abilities', [TokenAbilities::SEARCH_READ, TokenAbilities::REVIEW_WRITE]);
    }

    public function test_login_rejects_an_unknown_ability(): void
    {
        // A typo must be an error, not a silently narrower token.
        $user = User::factory()->create(['email' => 'allan@example.com']);

        $this->postJson('/api/v1/auth/login', ['abilities' => ['search:reed']] + self::LOGIN)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['abilities.0']);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_login_rejects_an_empty_abilities_array(): void
    {
        // A zero-ability token 403s on every route it touches, which reads as
        // a server fault from the client side. Reject at the door instead.
        $user = User::factory()->create(['email' => 'allan@example.com']);

        $this->postJson('/api/v1/auth/login', ['abilities' => []] + self::LOGIN)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['abilities']);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_the_reported_abilities_match_the_stored_token(): void
    {
        // These are written in two places - the response body and the token
        // row - and a client that trusts the body while the row says
        // something narrower gets 403s it cannot explain.
        $user = User::factory()->create(['email' => 'allan@example.com']);

        $response = $this->postJson('/api/v1/auth/login', [
            'abilities' => [TokenAbilities::ERRORS_READ, TokenAbilities::EXPORTS_READ],
        ] + self::LOGIN)->assertCreated();

        $this->assertSame($response->json('abilities'), $user->tokens()->sole()->abilities);
    }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=AuthTest`
Expected: FAIL — the default-scope tests fail because login still mints
`all()`, and the request tests fail because `abilities` is stripped by
`validated()`.

- [ ] **Step 3: Accept the field**

`app/Http/Requests/Api/V1/LoginRequest.php` — add to `rules()`:

```php
            /*
             * Optional, and narrowing only. A client that omits it gets
             * TokenAbilities::defaultScope(); a client that sends it gets the
             * intersection with what exists. `min:1` because a zero-ability
             * token fails every route silently, which is worse than a 422.
             */
            'abilities' => ['sometimes', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(TokenAbilities::all())],
```

with `use App\Auth\TokenAbilities;` and `use Illuminate\Validation\Rule;` added.

- [ ] **Step 4: Issue the scoped token**

In `app/Http/Controllers/Api/V1/Auth/LoginController.php`, replace the token
creation and response:

```php
        $abilities = array_key_exists('abilities', $credentials)
            // Intersected in all()'s order, not the request's: the issued list
            // is then canonical however the client asked, which keeps the
            // response byte-stable for the contract fixture, and a client that
            // names the same ability twice gets it once. Validation already
            // restricts each entry to all() - this is the line that still
            // holds if that rule is ever loosened.
            ? array_values(array_intersect(TokenAbilities::all(), $credentials['abilities']))
            : TokenAbilities::defaultScope();

        $token = $user->createToken($credentials['device_name'], $abilities);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => $abilities,
            'user' => UserResource::make($user)->resolve(),
        ], 201);
```

and update the method's docblock, which currently says "carrying every
ability":

```php
    /**
     * Exchange credentials for a bearer token carrying the requested scope,
     * or the default scope when none is asked for.
     */
```

- [ ] **Step 5: Run the tests and watch them pass**

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=AuthTest`
Expected: PASS.

- [ ] **Step 6: Prove the live client is untouched**

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=ContractFixturesTest`
Expected: PASS, **with no fixture regenerated**. `loginPayload()` posts no
`abilities`, so `login.json` still carries exactly the four legacy strings.

Confirm nothing was rewritten:

Run: `git status --porcelain mobile/src/api/__fixtures__/`
Expected: empty.

If this test is red, the default issuance widened. **Fix the code — do not run
`UPDATE_CONTRACT_FIXTURES=1`.**

- [ ] **Step 7: Pint, full suite, commit**

```bash
vendor/bin/pint
docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test
vendor/bin/pint --test
git add app/Http/Requests/Api/V1/LoginRequest.php app/Http/Controllers/Api/V1/Auth/LoginController.php tests/Feature/Api/AuthTest.php
git commit -m "feat(api): scope the issued token to the abilities the client asked for"
```

---

### Task 3: Widen the client's ability enum

Nothing requests the new abilities yet. The enum widens here so that the moment
P3's native build asks for `imports:read`, a four-value `z.enum` does not throw
on a response that is entirely valid.

**Files:**
- Modify: `mobile/src/api/schemas.ts`
- Test: `mobile/src/api/__tests__/schemas.test.ts`

**Interfaces:**
- Consumes: the ability strings defined in Task 1.
- Produces: `TokenAbilitySchema` accepting all eight.

- [ ] **Step 1: Write the failing test**

Add to `mobile/src/api/__tests__/schemas.test.ts`:

```ts
  it('accepts every ability the API can issue, including the privileged ones', () => {
    // P2 never requests these; P3's native build does. A narrow enum here
    // would throw on a login response that is entirely valid, which is the
    // failure mode this whole sub-project exists to avoid.
    const every = [
      'search:read',
      'search:write',
      'review:write',
      'errors:read',
      'imports:read',
      'imports:write',
      'search:run',
      'exports:read',
    ];

    expect(() => TokenAbilitySchema.array().parse(every)).not.toThrow();
  });

  it('still rejects an ability the API cannot issue', () => {
    expect(() => TokenAbilitySchema.parse('users:write')).toThrow();
  });
```

Import `TokenAbilitySchema` alongside the existing imports.

- [ ] **Step 2: Run it and watch it fail**

Run: `cd mobile && npx jest src/api/__tests__/schemas --silent`
Expected: FAIL — the array parse throws on `imports:read`.

- [ ] **Step 3: Widen the enum**

In `mobile/src/api/schemas.ts`:

```ts
/**
 * Mirrors App\Auth\TokenAbilities::all(). The last four are privileged and
 * are issued only when a client names them: a login that sends no `abilities`
 * receives the first four, which is what both builds do today.
 */
export const TokenAbilitySchema = z.enum([
  'search:read',
  'search:write',
  'review:write',
  'errors:read',
  'imports:read',
  'imports:write',
  'search:run',
  'exports:read',
]);
```

- [ ] **Step 4: Run the test and watch it pass**

Run: `cd mobile && npx jest src/api/__tests__/schemas --silent`
Expected: PASS.

- [ ] **Step 5: Full mobile gate and commit**

```bash
cd mobile && npm run typecheck && npm run lint && npm test
cd .. && git add mobile/src/api/schemas.ts mobile/src/api/__tests__/schemas.test.ts
git commit -m "feat(mobile): widen the token ability enum to what the API can issue"
```

---

### Task 4: Verification

- [ ] **Step 1: Both suites, both linters**

```bash
vendor/bin/pint --test
docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test
cd mobile && npm run typecheck && npm run lint && npm test && npm run build:web
```

Expected: all six exit 0.

- [ ] **Step 2: Assert the contract fixtures are byte-identical to `main`**

```bash
git diff --stat origin/main -- mobile/src/api/__fixtures__/
```

Expected: empty. A non-empty diff means the API's response shape changed for an
unscoped client, which is the one outcome this sub-project must not produce.

- [ ] **Step 3: Report**

State which of the commands in Step 1 passed, with output. Do not claim
completion for any that were not run.

---

## Self-Review

**Spec coverage.** Four new constants → Task 1. `all()` / `defaultScope()`
split → Task 1. `LoginRequest` rules with `min:1` → Task 2 Step 3. Intersection
in `all()` order → Task 2 Step 4. Response echoes the issued list → Task 2
Step 4. All seven spec test cases → Tasks 1 and 2 (two in Task 1, six in Task 2;
the spec's "response vs stored token" and "duplicates and order" are separate
cases here, and Task 1 adds two structural ones the spec implied but did not
enumerate). Client enum widening → Task 3. `ContractFixturesTest` green without
regeneration → Task 2 Step 6 and Task 4 Step 2.

**Addition beyond the spec.** `ApiTestCase`'s docblock says "Defaults to all
four", which stops being true when `all()` returns eight. Task 1 Step 5 fixes
it. Small, but a comment that lies about an auth default is worse than none.

**Type consistency.** `defaultScope()` is named identically in Tasks 1, 2 and
the spec. `TokenAbilities::SEARCH_RUN` and siblings are defined in Task 1 and
used in Tasks 2 and 3. `TokenAbilitySchema` is the existing export name,
unchanged.

**Placeholder scan.** Clean. Every code step carries its code; every run step
names its command and expected result.
