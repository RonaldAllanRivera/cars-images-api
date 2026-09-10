# API Authorization Scoping — Design

**Date:** 2026-09-10
**Status:** Approved (pending implementation plan)
**Project:** cars-images-api (Laravel 13 + Filament 5 + Expo 57)
**Sub-project:** P2 of 5 — see the [P1 spec's programme table](2026-09-09-mobile-design-system-and-navigation-design.md#programme)

## Goal

Define the ability set that P3–P5's privileged endpoints will be gated on, and
make the login endpoint issue a token scoped to what the client asked for
instead of everything that exists.

Laravel only, plus four strings in the mobile client's Zod enum. No new
endpoint, no schema change, no migration, no UI.

## Why this is its own sub-project

It is the gate in front of every privileged verb P3–P5 add, and a security
change is worth reviewing on its own rather than buried inside a plan that also
moves eight screens. It is also the smallest thing in the programme, which
makes it a cheap place to get the ability vocabulary right before three
sub-projects start depending on it.

## The constraint that shapes the design

**A client is deployed and live against the current login response.**

[`mobile/src/api/schemas.ts`](../../../mobile/src/api/schemas.ts) declares:

```ts
export const TokenAbilitySchema = z.enum([
  'search:read', 'search:write', 'review:write', 'errors:read',
]);

export const LoginResponseSchema = z.object({
  token: z.string(),
  token_type: z.literal('Bearer'),
  abilities: z.array(TokenAbilitySchema),
  user: UserSchema,
});
```

`LoginController` currently mints `TokenAbilities::all()` and echoes the same
list back. So **adding an ability to `all()` would return eight strings to a
client whose `z.enum` accepts four, the parse would throw, and sign-in would
break on cars-mobile.netlify.app.**

The Laravel suite would not catch that through `AuthTest`, which asserts
`abilities === TokenAbilities::all()` and stays true as the set grows. It would
be caught by
[`ContractFixturesTest`](../../../tests/Feature/Api/ContractFixturesTest.php),
which pins `mobile/src/api/__fixtures__/login.json` byte-for-byte — a genuine
safety net, and the reason that test must stay green **without regeneration**.

## Decisions

| Decision | Choice | Why |
|---|---|---|
| Authorization model | **Client-declared scope, intersected server-side** | Settled during the programme brainstorm. No role column: the user base is single-tier, and a `role` column buys nothing until a reviewer exists who is not an operator. |
| Default when `abilities` is omitted | **The four legacy abilities** | Every deployed client omits the field, so every deployed client keeps exactly what it has. Backward compatibility falls out of a rule that is independently correct: privileged verbs should be opt-in. |
| Empty `abilities: []` | **422** | A zero-ability token is almost certainly a client bug, and it fails silently — every route 403s. Better to reject loudly at the door. |
| Unknown ability | **422** | Typos become errors rather than silently-narrowed tokens. |
| Policies | **Deferred to P3/P4** | A policy with no caller is dead code whose shape depends on the endpoint it will guard. |
| `abilities` on `/auth/me` | **Deferred to P3** | Needed for conditional UI, not for issuance. |
| Web vs native scope | **Web keeps the legacy four; native adds the privileged four** — the intended split, *implemented in P3* | Corrects the programme brainstorm, which said web should hold "read + review only". That would drop `search:write` and break the search form, which is the entire public demo. P2 changes no client behaviour: both builds continue to omit `abilities` and receive `defaultScope()`. |

### On the web/native split

The four privileged abilities are the ones that must never reach a token in
`localStorage`:

| Ability | What a stolen token could do |
|---|---|
| `imports:read` | Read what `search:read` already exposes. Negligible on its own; grouped for symmetry. |
| `imports:write` | Seed up to 500 queries. Creates rows, destroys nothing. |
| `search:run` | Drive a long paced run against Wikimedia — the thing `wikimedia_block_events` exists to record. |
| `exports:read` | Exfiltrate the library's metadata in one request. |

The existing four already reach `localStorage` today and continue to. Nothing
about this change makes the web build less safe than it is now; it makes the
native build able to be *more* capable without dragging the web build with it.

**`search:run` is deliberately separate from `search:write`.** Both "run a
search", but `search:write` runs one query inline, hard-capped at 4 years × 5
images by [`StoreSearchRequest`](../../../app/Http/Requests/Api/V1/StoreSearchRequest.php)
and `config('cars-images.api_search_max_*')`. `search:run` drives an unbounded
queue of pre-existing rows. Same Wikimedia exposure per request, wildly
different exposure per token — collapsing them would let the search form's
token drive a 500-query run.

## Design

### `TokenAbilities`: two lists, not one

```php
public const IMPORTS_READ  = 'imports:read';
public const IMPORTS_WRITE = 'imports:write';
public const SEARCH_RUN    = 'search:run';
public const EXPORTS_READ  = 'exports:read';

/** Every ability that exists - the set a request is intersected against. */
public static function all(): array;          // eight

/** What a client that asks for nothing gets. */
public static function defaultScope(): array; // search:read, search:write,
                                              // review:write, errors:read
```

The split is the whole design. One function was doing two jobs — naming the
valid set *and* naming what gets issued — so widening it for validation would
have widened what every existing client receives.

### `LoginRequest`

```php
'abilities'   => ['sometimes', 'array', 'min:1'],
'abilities.*' => ['string', Rule::in(TokenAbilities::all())],
```

### `LoginController`

```php
$abilities = array_key_exists('abilities', $credentials)
    ? array_values(array_intersect(TokenAbilities::all(), $credentials['abilities']))
    : TokenAbilities::defaultScope();
```

Intersecting in **`all()`'s order**, not the request's, does two jobs beyond
filtering: the issued list is canonical whatever order the client asked in, so
`assertSame` and the contract fixture stay byte-stable; and a client that sends
the same ability twice gets it once.

Validation already restricts every entry to `all()`, so the intersection is
belt-and-braces — which is the point. It is the line that must hold if the
validation rule is ever loosened.

The response's `abilities` becomes the issued list rather than `all()`.

### Mobile client

`TokenAbilitySchema` widens from four strings to eight. Nothing requests the
new ones in P2; the widening exists so that the moment P3's native build asks
for `imports:read`, a narrow `z.enum` does not throw on a response that is
entirely valid.

## Testing

In `AuthTest`:

| Case | Asserts |
|---|---|
| `abilities` omitted | issues exactly `defaultScope()` — **the deployed-client guard** |
| a subset requested | issues exactly that subset |
| a privileged ability requested | is actually granted, so P3 can rely on it |
| an unknown ability | 422 on `abilities.0` |
| `abilities: []` | 422 |
| duplicates and shuffled order | collapse to one canonical list |
| response vs stored token | the two ability lists are identical and never drift |

`ContractFixturesTest` must stay green **with no regeneration**: its capture
posts no `abilities`, so the login fixture is unchanged. That is the assertion
that the live client is unaffected, and it costs nothing.

In the mobile suite: a schema test that all eight strings parse.

## Risks

| Risk | Mitigation |
|---|---|
| Widening `all()` silently widens what existing clients receive | The whole point of `defaultScope()`. Guarded by the omitted-`abilities` test and by `ContractFixturesTest` staying green unregenerated. |
| A future contributor "tidies" the two lists back into one | Both methods carry a docblock saying why they are separate, and the omitted-`abilities` test fails the moment they merge. |
| P3 requests a new ability and the client's `z.enum` throws | The enum is widened here, ahead of the need. |
| `min:1` rejects a client that legitimately wants a zero-ability token | No such client exists or should. A token that can do nothing is indistinguishable from a bug. |

## Out of scope

No policies, no `/auth/me` change, no role column, no migration, no new
endpoint, no UI. No route consumes the four new abilities until P3 — they are
mintable and validated here, and enforced where the endpoints land.
