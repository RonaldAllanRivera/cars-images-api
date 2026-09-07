# Expo Mobile Client and Web Deploy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Expo app under `mobile/` against the `/api/v1` JSON API that Plan A delivered, and publish its web build to Netlify at a public URL.

**Architecture:** A single Expo (managed) codebase under `mobile/`, with its own lockfile and its own CI workflow, talking to the existing Laravel API over bearer tokens. Zod validates every response at the fetch boundary so PHP/TypeScript drift fails loudly at the edge instead of crashing three screens later; the Zod schemas are asserted against fixtures generated from real API responses by a PHP test, so drift fails a test in *both* languages. TanStack Query owns caching, infinite scroll and the optimistic review update.

**Tech Stack:** Expo SDK 56 (managed), expo-router, TypeScript `strict`, TanStack Query v5, Zod, `expo-image`, NativeWind v4, Jest (`jest-expo`) + React Native Testing Library, Netlify.

**Spec:** [`docs/superpowers/specs/2026-09-05-react-native-mobile-client-design.md`](../specs/2026-09-05-react-native-mobile-client-design.md)

**This is Plan B of four.** Plan A (CI hardening + Laravel API) is complete and merged into this branch. Plan C is the APK; Plan D is the CSV/ZIP phase 3. Both are out of scope here.

## Global Constraints

- **Branch:** `feat/react-native-mobile-client`. Every task commits here. Do not merge.
- **All mobile work happens inside `mobile/`.** Never add a dependency to the repository-root `package.json` — Metro must resolve `mobile/node_modules`, and two separate lockfiles with no workspace linking is the mitigation the spec names for that risk.
- **TypeScript `strict: true`.** No `any` in committed code; no `@ts-expect-error` without a comment saying what it is waiting for.
- **API base path is `/api/v1`,** versioned from the first commit.
- **`EXPO_PUBLIC_API_URL` is inlined at build time**, never read from a runtime config file.
- **Token abilities are exactly:** `search:read`, `search:write`, `review:write`, `errors:read`.
- **Search caps bind API callers:** `to_year - from_year` ≤ 3, `images_per_year` ≤ 5. The client must enforce these in its own form too, so the user sees the limit before a round trip.
- **`review_status` is one of `pending` | `approved` | `rejected`.** Never write `make_confirmed` or `year_confirmed` from the client — they are the machine's verdict.
- **Error contexts are exactly:** `csv_upload`, `csv_row`, `search_run`, `image_download`, `wikimedia_block`.
- **Laravel tests run in the `cars-ci-php:8.3` container** — host PHP has no `pdo_sqlite`. Mobile tests run on the host with Node.
- **Do not modify `app/`, `routes/` or `config/` except where a task explicitly says so.** The API is done; this plan consumes it.

---

## The API contract, as actually delivered

Read this before writing any schema. These shapes were verified against the merged source, not against Plan A's prose.

**Three response envelopes exist. They are not interchangeable.**

**1. Unwrapped — `POST /api/v1/auth/login` only (201):**

```json
{
  "token": "3|plaintext...",
  "token_type": "Bearer",
  "abilities": ["search:read", "search:write", "review:write", "errors:read"],
  "user": { "id": 1, "name": "Ada", "email": "ada@example.com" }
}
```

**2. Single resource — `{ "data": { ... } }`:**
`GET /auth/me`, `GET /images/{id}`, `PATCH /images/{id}/review`, `GET /searches/{id}`, `GET /health/summary`, and every `POST /searches` response.

**3. Cursor collection — Laravel's cursor envelope:**
`GET /images`, `GET /searches`, `GET /searches/{id}/images`, `GET /errors`.

```json
{
  "data": [ ... ],
  "links": { "first": null, "last": null, "prev": null, "next": "https://…?cursor=eyJ…" },
  "meta": { "path": "https://…", "per_page": 24, "next_cursor": "eyJ…", "prev_cursor": null }
}
```

`next_cursor` is `null` on the last page. That is the infinite-scroll terminator.

**Other endpoints:** `POST /auth/logout` returns **204 with no body**.

**`POST /api/v1/searches` has four success-ish shapes** — the client must branch on status, not assume 201:

| Status | Meaning | Body |
|---|---|---|
| 201 | New search ran and completed | `{ "data": Search }` |
| 200 | An identical completed search already existed; handed back, not re-run | `{ "data": Search }` |
| 503 | Wikimedia is rate-limiting the server | `{ "message": "…", "retry_after_seconds": N, "data": Search }` + `Retry-After` header |
| 502 | The run failed; reason is in the error log | `{ "message": "…", "data": Search }` |

On 503 and 502 the `data` search is still a real row (marked `failed` where applicable), so the client can link the user to the run.

**Filters, verified from the Form Requests:**

- `GET /images` and `GET /searches/{id}/images`: `make`, `model`, `year`, `make_confirmed`, `year_confirmed`, `review_status`, `download_status`, `per_page` (1–100, default 24), `cursor`. Booleans are sent as the strings `"true"` / `"false"` — the request remaps them. `download_status` ∈ `not_downloaded` | `downloading` | `downloaded` | `failed`.
- `GET /searches`: `status` ∈ `pending` | `running` | `completed` | `failed`, plus pagination.
- `GET /errors`: `context` (the five above), `severity` ∈ `error` | `warning`, plus pagination.

**Auth failures:** missing/revoked token → **401**. Valid token lacking the route's ability → **403**. Validation failure → **422** with `{ "message": …, "errors": { field: [msg] } }`. Throttle exceeded → **429**.

---

## File Structure

Created under `mobile/`:

| File | Responsibility |
|---|---|
| `app/_layout.tsx` | Root providers: QueryClient, AuthProvider, NativeWind global CSS |
| `app/index.tsx` | Unauthenticated landing — explains the app before asking for credentials |
| `app/login.tsx` | Email / password / device name form |
| `app/(app)/_layout.tsx` | Auth guard + tab bar for the four authenticated sections |
| `app/(app)/search/index.tsx` | Search form (enforces the caps) → posts, then routes to the run |
| `app/(app)/search/images.tsx` | Infinite image grid with filters |
| `app/(app)/search/[id].tsx` | Single image detail — attribution, license, dimensions |
| `app/(app)/runs/index.tsx` | Run list, filterable by status |
| `app/(app)/runs/[id].tsx` | Run detail + its images |
| `app/(app)/review/index.tsx` | Approve/reject queue with optimistic updates |
| `app/(app)/health/index.tsx` | Pipeline health counters + error log |
| `src/api/client.ts` | Base URL, bearer header, envelope unwrapping, central 401 handling |
| `src/api/schemas.ts` | Zod schemas mirroring the API Resources exactly |
| `src/api/hooks/*.ts` | One file per resource: `useImages`, `useSearches`, `useReviewImage`, `useHealth`, `useErrors` |
| `src/auth/TokenStore.ts` | The interface + platform split (SecureStore native, localStorage web) |
| `src/auth/AuthContext.tsx` | Token state, login/logout, the `signOut` the 401 handler calls |
| `src/ui/*.tsx` | Shared presentational pieces (`ImageCard`, `StatusBadge`, `EmptyState`, `ErrorBanner`) |

Created outside `mobile/`:

| File | Responsibility |
|---|---|
| `.github/workflows/mobile.yml` | Expo-only CI: install, typecheck, lint, test, web export, deploy |
| `tests/Feature/Api/ContractFixturesTest.php` | Generates the JSON fixtures the Zod schemas are asserted against |

---

## Task 1: CI workflow and a web-exportable Expo scaffold

Nothing else can be verified until `mobile/` builds for web in CI. This task ends when a mobile-only commit runs `mobile.yml`, does **not** start `ci-cd.yml`, and produces a `dist/` directory.

**Files:**
- Create: `.github/workflows/mobile.yml`
- Create: `mobile/` (scaffolded), `mobile/app/_layout.tsx`, `mobile/app/index.tsx`
- Create: `mobile/global.css`, `mobile/tailwind.config.js`, `mobile/metro.config.js`, `mobile/babel.config.js`, `mobile/.eslintrc.js`, `mobile/jest.config.js`, `mobile/.env.example`
- Modify: `mobile/app.json`, `mobile/package.json`, `mobile/tsconfig.json`
- Test: `mobile/app/__tests__/index.test.tsx`

**Interfaces:**
- Consumes: nothing — this is the first task.
- Produces: the `mobile/` workspace, the npm scripts `typecheck`, `lint`, `test`, `build:web`, and the `@/` path alias resolving to `mobile/src`.

- [ ] **Step 1: Scaffold the Expo app**

Run from the repository root. The blank TypeScript template is used rather than the tabs template so that no example screens need deleting afterwards.

```bash
cd /home/allan/code/laravel/cars-images-api
npx create-expo-app@latest mobile --template blank-typescript
```

Confirm `mobile/package.json` exists and the repository-root `package.json` is unchanged:

```bash
git status --short
```

Expected: only `mobile/` appears (its `node_modules` is already gitignored by the entries Plan A added).

- [ ] **Step 2: Add expo-router and the runtime dependencies**

```bash
cd mobile
npx expo install expo-router react-native-safe-area-context react-native-screens expo-linking expo-constants expo-status-bar expo-image expo-secure-store
npx expo install @tanstack/react-query zod
npx expo install nativewind tailwindcss --dev
```

- [ ] **Step 3: Point the entry point at expo-router**

Edit `mobile/package.json` so `main` is expo-router's entry and the scripts exist. Replace the `main` and `scripts` keys with:

```json
{
  "main": "expo-router/entry",
  "scripts": {
    "start": "expo start",
    "android": "expo start --android",
    "web": "expo start --web",
    "typecheck": "tsc --noEmit",
    "lint": "eslint .",
    "test": "jest",
    "build:web": "expo export -p web"
  }
}
```

- [ ] **Step 4: Configure the app for static web output**

Replace `mobile/app.json` with:

```json
{
  "expo": {
    "name": "Cars Images",
    "slug": "cars-images",
    "version": "1.0.0",
    "orientation": "portrait",
    "scheme": "carsimages",
    "userInterfaceStyle": "automatic",
    "newArchEnabled": true,
    "ios": {
      "supportsTablet": true
    },
    "android": {
      "package": "com.carsimages.app",
      "adaptiveIcon": {
        "backgroundColor": "#0f172a"
      }
    },
    "web": {
      "bundler": "metro",
      "output": "static"
    },
    "plugins": ["expo-router", "expo-secure-store"],
    "experiments": {
      "typedRoutes": true
    }
  }
}
```

`web.output: "static"` is what emits real HTML per route, so `/runs/42` is a shareable URL with a working link preview and no SPA catch-all redirect.

- [ ] **Step 5: Configure NativeWind**

Create `mobile/global.css`:

```css
@tailwind base;
@tailwind components;
@tailwind utilities;
```

Create `mobile/tailwind.config.js`:

```js
/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./app/**/*.{js,jsx,ts,tsx}', './src/**/*.{js,jsx,ts,tsx}'],
  presets: [require('nativewind/preset')],
  theme: {
    extend: {},
  },
  plugins: [],
};
```

Create `mobile/metro.config.js`:

```js
const { getDefaultConfig } = require('expo/metro-config');
const { withNativeWind } = require('nativewind/metro');

const config = getDefaultConfig(__dirname);

module.exports = withNativeWind(config, { input: './global.css' });
```

Create `mobile/babel.config.js`:

```js
module.exports = function (api) {
  api.cache(true);

  return {
    presets: [['babel-preset-expo', { jsxImportSource: 'nativewind' }], 'nativewind/babel'],
  };
};
```

- [ ] **Step 6: Configure TypeScript strict mode and the path alias**

Replace `mobile/tsconfig.json`:

```json
{
  "extends": "expo/tsconfig.base",
  "compilerOptions": {
    "strict": true,
    "noUncheckedIndexedAccess": true,
    "paths": {
      "@/*": ["./src/*"]
    }
  },
  "include": ["**/*.ts", "**/*.tsx", ".expo/types/**/*.ts", "expo-env.d.ts", "nativewind-env.d.ts"]
}
```

- [ ] **Step 7: Write the root layout and the landing screen**

Delete the template's `mobile/App.tsx` if it exists, then create `mobile/app/_layout.tsx`:

```tsx
import '../global.css';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useState } from 'react';

export default function RootLayout() {
  // Created in state so Fast Refresh does not discard the cache on every edit.
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            retry: 2,
            staleTime: 30_000,
          },
        },
      }),
  );

  return (
    <QueryClientProvider client={queryClient}>
      <StatusBar style="auto" />
      <Stack screenOptions={{ headerShown: false }} />
    </QueryClientProvider>
  );
}
```

Create `mobile/app/index.tsx`. This screen is unauthenticated by design — it is the mitigation for having no demo account, so a portfolio visitor sees what the app does before it asks for credentials.

```tsx
import { Link } from 'expo-router';
import { Text, View } from 'react-native';

export default function Landing() {
  return (
    <View className="flex-1 items-center justify-center gap-4 bg-slate-900 p-6">
      <Text className="text-center text-3xl font-bold text-white">Cars Images</Text>
      <Text className="max-w-md text-center text-base text-slate-300">
        Search Wikimedia Commons for high-resolution car photography by make, model and year,
        review what comes back, and watch the harvest pipeline&apos;s health.
      </Text>
      <Link href="/login" className="mt-4 rounded-lg bg-sky-500 px-6 py-3 text-base font-semibold text-white">
        Sign in
      </Link>
    </View>
  );
}
```

- [ ] **Step 8: Configure ESLint and Jest**

```bash
cd mobile
npx expo install eslint eslint-config-expo jest jest-expo @testing-library/react-native react-test-renderer --dev
```

Create `mobile/.eslintrc.js`:

```js
module.exports = {
  extends: 'expo',
  ignorePatterns: ['dist/', 'node_modules/', '.expo/'],
};
```

Create `mobile/jest.config.js`:

```js
module.exports = {
  preset: 'jest-expo',
  setupFilesAfterEnv: ['<rootDir>/jest.setup.js'],
  // Metro honours the tsconfig `paths` entry on its own; Jest does not, so
  // the @/ alias has to be repeated here or every test importing it fails to
  // resolve.
  moduleNameMapper: {
    '^@/(.*)$': '<rootDir>/src/$1',
  },
  transformIgnorePatterns: [
    'node_modules/(?!((jest-)?react-native|@react-native(-community)?|expo(nent)?|@expo(nent)?/.*|@expo-google-fonts/.*|react-navigation|@react-navigation/.*|@unimodules/.*|unimodules|sentry-expo|native-base|react-native-svg|nativewind|react-native-css-interop))',
  ],
};
```

Create `mobile/jest.setup.js`:

```js
// The web TokenStore path reads localStorage; jsdom provides it, but the
// native path's SecureStore must be mocked or every suite touching auth
// tries to reach a native module that does not exist under Jest.
jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(async () => null),
  setItemAsync: jest.fn(async () => undefined),
  deleteItemAsync: jest.fn(async () => undefined),
}));
```

- [ ] **Step 9: Write the failing smoke test**

Create `mobile/app/__tests__/index.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react-native';

import Landing from '../index';

describe('<Landing />', () => {
  it('names the app and offers a way in', () => {
    render(<Landing />);

    expect(screen.getByText('Cars Images')).toBeTruthy();
    expect(screen.getByText('Sign in')).toBeTruthy();
  });
});
```

- [ ] **Step 10: Run the full local gate**

```bash
cd mobile
npm run typecheck && npm run lint && npm test && npm run build:web
```

Expected: all four pass, and `mobile/dist/` now contains `index.html`.

**If `expo export -p web` fails inside NativeWind:** the spec names the fallback explicitly — NativeWind is "replaceable with plain `StyleSheet` if it fights the web build". Do not spend more than one attempt debugging it. Remove `nativewind`/`tailwindcss`, delete `global.css`, `tailwind.config.js`, drop the `nativewind` entries from `babel.config.js` and `metro.config.js`, restore the plain `getDefaultConfig` export, and convert the `className` props written so far to `StyleSheet.create`. Record the swap in the commit message so later tasks know which vocabulary to use.

- [ ] **Step 11: Add the environment file**

Create `mobile/.env.example`:

```
# Inlined at build time by Metro - anything prefixed EXPO_PUBLIC_ is public.
# Local: the Laravel dev server. CI injects the production URL for the web build.
EXPO_PUBLIC_API_URL=http://localhost:8000
```

Append to the repository-root `.gitignore`, under the existing mobile block:

```
/mobile/.env
```

- [ ] **Step 12: Write the mobile CI workflow**

Create `.github/workflows/mobile.yml`. This is the mirror image of `ci-cd.yml`'s `paths-ignore`: that workflow ignores `mobile/**`, this one runs on nothing else.

```yaml
name: Mobile CI

on:
  push:
    branches: [main]
    paths:
      - 'mobile/**'
      - '.github/workflows/mobile.yml'
  pull_request:
    paths:
      - 'mobile/**'
      - '.github/workflows/mobile.yml'
  workflow_dispatch:

concurrency:
  group: ${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: true

defaults:
  run:
    working-directory: mobile

jobs:
  check:
    name: Typecheck, lint, test, export
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - uses: actions/setup-node@v4
        with:
          node-version: 22
          cache: npm
          cache-dependency-path: mobile/package-lock.json

      # `npm ci` from mobile/package-lock.json only. The repository root has
      # its own lockfile for Laravel's Vite assets and the two never mix -
      # that separation is what stops Metro resolving the root node_modules.
      - name: Install
        run: npm ci

      - name: Typecheck
        run: npm run typecheck

      - name: Lint
        run: npm run lint

      - name: Test
        run: npm test -- --ci

      - name: Export web build
        run: npm run build:web
        env:
          EXPO_PUBLIC_API_URL: ${{ vars.EXPO_PUBLIC_API_URL || 'http://localhost:8000' }}
```

- [ ] **Step 13: Commit**

```bash
cd /home/allan/code/laravel/cars-images-api
git add mobile .github/workflows/mobile.yml .gitignore
git commit -m "feat(mobile): scaffold the Expo app and its own CI workflow

The app builds for web as static HTML per route, so every screen gets a
shareable URL. Its workflow triggers on mobile/** alone, the mirror of the
paths-ignore ci-cd.yml already carries, so a mobile commit never starts a
Laravel deploy.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---
## Task 2: Contract fixtures and Zod schemas

The spec requires the Zod schemas to be "asserted against fixtures captured from real API responses, so drift fails a test rather than the app". This task builds that in both directions: a PHP test *generates* the fixtures from real responses and fails if the committed ones no longer match, and a Jest test parses each fixture with its schema.

A monorepo does not stop PHP and TypeScript types drifting. This is what does.

**Files:**
- Create: `tests/Feature/Api/ContractFixturesTest.php`
- Create: `mobile/src/api/__fixtures__/*.json` (generated, committed)
- Create: `mobile/src/api/schemas.ts`
- Test: `mobile/src/api/__tests__/schemas.test.ts`

**Interfaces:**
- Consumes: the `mobile/` workspace and `@/` alias from Task 1.
- Produces: `ImageSchema`, `SearchSchema`, `ErrorEventSchema`, `UserSchema`, `HealthSummarySchema`, `LoginResponseSchema`, `ValidationErrorSchema`, the generic helpers `single<T>()` and `cursorPage<T>()`, and the inferred types `Image`, `Search`, `ErrorEvent`, `User`, `HealthSummary`, `CursorPage<T>`.

- [ ] **Step 1: Write the fixture generator as a failing test**

Create `tests/Feature/Api/ContractFixturesTest.php`. Time is frozen and `RefreshDatabase` restarts IDs at 1, so the generated JSON is byte-stable — a diff means the contract actually changed, not that the clock moved.

```php
<?php

namespace Tests\Feature\Api;

use App\Models\CarImage;
use App\Auth\TokenAbilities;
use App\Models\ErrorEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
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

        $search = $this->search($user, ['status' => 'completed']);

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

        return [
            'login' => $this->loginPayload(),
            'me' => $this->getJson('/api/v1/auth/me')->assertOk()->json(),
            'image' => $this->getJson("/api/v1/images/{$reviewed->id}")->assertOk()->json(),
            'images' => $this->getJson('/api/v1/images?per_page=1')->assertOk()->json(),
            'search' => $this->getJson("/api/v1/searches/{$search->id}")->assertOk()->json(),
            'searches' => $this->getJson('/api/v1/searches')->assertOk()->json(),
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
        ];
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
```

- [ ] **Step 2: Run it to verify it fails**

```bash
docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=ContractFixturesTest
```

Expected: FAIL — "Missing contract fixture login.json."

*(If the container is invoked differently in this repo, match how `ci-cd.yml` runs `php artisan test`. Host PHP has no `pdo_sqlite` and will not work.)*

- [ ] **Step 3: Generate the fixtures**

```bash
docker run --rm -e UPDATE_CONTRACT_FIXTURES=1 -v "$PWD":/app -w /app cars-ci-php:8.3 \
  php artisan test --filter=ContractFixturesTest
```

Expected: PASS, and `mobile/src/api/__fixtures__/` now holds ten `.json` files.

- [ ] **Step 4: Run it again to verify the fixtures are stable**

```bash
docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=ContractFixturesTest
git status --short mobile/src/api/__fixtures__
```

Expected: PASS, and `git status` reports no modifications. If a fixture is dirty on a second run, something non-deterministic leaked in (an unfrozen timestamp, a random id) — fix that before continuing, or every future test run will produce spurious diffs.

- [ ] **Step 5: Write the Zod schemas**

Create `mobile/src/api/schemas.ts`. Field lists mirror `App\Http\Resources\Api\V1\*` exactly; nullability mirrors the migrations.

```ts
import { z } from 'zod';

/** The three review verdicts. Never write make_confirmed from the client. */
export const ReviewStatusSchema = z.enum(['pending', 'approved', 'rejected']);
export type ReviewStatus = z.infer<typeof ReviewStatusSchema>;

export const DownloadStatusSchema = z.enum([
  'not_downloaded',
  'downloading',
  'downloaded',
  'failed',
]);

export const SearchStatusSchema = z.enum(['pending', 'running', 'completed', 'failed']);
export type SearchStatus = z.infer<typeof SearchStatusSchema>;

export const ErrorContextSchema = z.enum([
  'csv_upload',
  'csv_row',
  'search_run',
  'image_download',
  'wikimedia_block',
]);
export type ErrorContext = z.infer<typeof ErrorContextSchema>;

export const TokenAbilitySchema = z.enum([
  'search:read',
  'search:write',
  'review:write',
  'errors:read',
]);

export const UserSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  email: z.string(),
});
export type User = z.infer<typeof UserSchema>;

export const ImageSchema = z.object({
  id: z.number().int(),
  car_search_id: z.number().int().nullable(),
  make: z.string(),
  model: z.string().nullable(),
  year: z.number().int(),
  color: z.string().nullable(),
  title: z.string(),
  description: z.string().nullable(),
  source_url: z.string(),
  thumbnail_url: z.string().nullable(),
  width: z.number().int().nullable(),
  height: z.number().int().nullable(),
  license: z.string().nullable(),
  attribution: z.string().nullable(),
  // Nullable is the machine's "unknown" verdict - neither true nor false.
  make_confirmed: z.boolean().nullable(),
  year_confirmed: z.boolean().nullable(),
  review_status: ReviewStatusSchema,
  reviewed_by: z.number().int().nullable(),
  reviewed_at: z.string().nullable(),
  download_status: DownloadStatusSchema,
  created_at: z.string().nullable(),
});
export type Image = z.infer<typeof ImageSchema>;

export const SearchSchema = z.object({
  id: z.number().int(),
  make: z.string(),
  model: z.string().nullable(),
  commons_category: z.string().nullable(),
  from_year: z.number().int(),
  to_year: z.number().int(),
  color: z.string().nullable(),
  transmission: z.string().nullable(),
  transparent_background: z.boolean(),
  images_per_year: z.number().int(),
  status: SearchStatusSchema,
  csv_import_id: z.number().int().nullable(),
  requested_by: z.number().int().nullable(),
  // whenCounted / whenLoaded: present only on the endpoints that add them.
  images_count: z.number().int().optional(),
  images: z.array(ImageSchema).optional(),
  created_at: z.string().nullable(),
  updated_at: z.string().nullable(),
});
export type Search = z.infer<typeof SearchSchema>;

export const ErrorEventSchema = z.object({
  id: z.number().int(),
  context: ErrorContextSchema,
  severity: z.string(),
  message: z.string().nullable(),
  exception_class: z.string().nullable(),
  exception_message: z.string().nullable(),
  trace_excerpt: z.string().nullable(),
  details: z.unknown().nullable(),
  car_search_id: z.number().int().nullable(),
  csv_import_id: z.number().int().nullable(),
  car_image_id: z.number().int().nullable(),
  occurred_at: z.string().nullable(),
});
export type ErrorEvent = z.infer<typeof ErrorEventSchema>;

export const HealthSummarySchema = z.object({
  searches_by_status: z.record(SearchStatusSchema, z.number().int()),
  errors_last_24h: z.number().int(),
  errors_by_context_last_7d: z.record(ErrorContextSchema, z.number().int()),
  images_last_7d: z.number().int(),
  latest_error_at: z.string().nullable(),
});
export type HealthSummary = z.infer<typeof HealthSummarySchema>;

/** POST /auth/login is the ONE endpoint with no `data` envelope. */
export const LoginResponseSchema = z.object({
  token: z.string(),
  token_type: z.literal('Bearer'),
  abilities: z.array(TokenAbilitySchema),
  user: UserSchema,
});
export type LoginResponse = z.infer<typeof LoginResponseSchema>;

/** A 422 from any Form Request. */
export const ValidationErrorSchema = z.object({
  message: z.string(),
  errors: z.record(z.string(), z.array(z.string())),
});
export type ValidationError = z.infer<typeof ValidationErrorSchema>;

/** Every single-resource endpoint except login. */
export const single = <T extends z.ZodTypeAny>(schema: T) => z.object({ data: schema });

/**
 * Laravel's cursor envelope. `meta.next_cursor` is null on the last page -
 * that null is the infinite-scroll terminator.
 */
export const cursorPage = <T extends z.ZodTypeAny>(schema: T) =>
  z.object({
    data: z.array(schema),
    links: z.object({
      first: z.string().nullable(),
      last: z.string().nullable(),
      prev: z.string().nullable(),
      next: z.string().nullable(),
    }),
    meta: z.object({
      path: z.string(),
      per_page: z.number().int(),
      next_cursor: z.string().nullable(),
      prev_cursor: z.string().nullable(),
    }),
  });

export type CursorPage<T> = {
  data: T[];
  links: { first: string | null; last: string | null; prev: string | null; next: string | null };
  meta: { path: string; per_page: number; next_cursor: string | null; prev_cursor: string | null };
};
```

- [ ] **Step 6: Write the failing contract test**

Create `mobile/src/api/__tests__/schemas.test.ts`:

```ts
import errorsFixture from '../__fixtures__/errors.json';
import healthFixture from '../__fixtures__/health.json';
import imageFixture from '../__fixtures__/image.json';
import imagesFixture from '../__fixtures__/images.json';
import loginFixture from '../__fixtures__/login.json';
import meFixture from '../__fixtures__/me.json';
import reviewFixture from '../__fixtures__/review.json';
import searchFixture from '../__fixtures__/search.json';
import searchesFixture from '../__fixtures__/searches.json';
import validationErrorFixture from '../__fixtures__/validation-error.json';
import {
  cursorPage,
  ErrorEventSchema,
  HealthSummarySchema,
  ImageSchema,
  LoginResponseSchema,
  SearchSchema,
  single,
  UserSchema,
  ValidationErrorSchema,
} from '../schemas';

describe('the API contract', () => {
  it('parses the unwrapped login response', () => {
    const parsed = LoginResponseSchema.parse(loginFixture);

    expect(parsed.token_type).toBe('Bearer');
    expect(parsed.abilities).toContain('review:write');
  });

  it('parses the data-wrapped single resources', () => {
    expect(single(UserSchema).parse(meFixture).data.email).toBeTruthy();
    expect(single(ImageSchema).parse(imageFixture).data.review_status).toBe('approved');
    expect(single(ImageSchema).parse(reviewFixture).data.review_status).toBe('rejected');
    expect(single(SearchSchema).parse(searchFixture).data.status).toBe('completed');
    expect(single(HealthSummarySchema).parse(healthFixture).data.errors_last_24h).toBeGreaterThanOrEqual(0);
  });

  it('parses the cursor-paginated collections', () => {
    const images = cursorPage(ImageSchema).parse(imagesFixture);

    expect(images.data.length).toBe(1);
    // per_page=1 with two images, so there is a further page.
    expect(images.meta.next_cursor).not.toBeNull();

    expect(cursorPage(SearchSchema).parse(searchesFixture).meta.next_cursor).toBeNull();
    expect(cursorPage(ErrorEventSchema).parse(errorsFixture).data[0]?.context).toBe('search_run');
  });

  it('parses a 422', () => {
    const parsed = ValidationErrorSchema.parse(validationErrorFixture);

    // The 30-year span breaks the cap, and the cap names the offending field.
    expect(parsed.errors.to_year).toBeDefined();
  });

  it('rejects a response with a field the schema does not know about being wrong', () => {
    expect(() => ImageSchema.parse({ ...imageFixture.data, review_status: 'maybe' })).toThrow();
  });
});
```

- [ ] **Step 7: Enable JSON imports and run the test**

`resolveJsonModule` is already on via `expo/tsconfig.base`; confirm by running both gates:

```bash
cd mobile && npm run typecheck && npm test
```

Expected: PASS.

- [ ] **Step 8: Wire the fixture test into the Laravel CI job**

No change is needed — `ContractFixturesTest` is an ordinary feature test, so `php artisan test` in `ci-cd.yml` already runs it and will fail the Laravel build if a resource changes without the fixtures being regenerated. Verify it is picked up:

```bash
docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=Api
```

Expected: the whole `tests/Feature/Api` suite passes, `ContractFixturesTest` among it.

- [ ] **Step 9: Commit**

```bash
git add tests/Feature/Api/ContractFixturesTest.php mobile/src/api
git commit -m "feat(mobile): pin the API contract with generated fixtures and Zod schemas

A PHP test captures every response shape and fails when the committed
fixtures drift; the mobile suite parses those same fixtures with the Zod
schemas. A field that moves in PHP now breaks a test in both languages
instead of crashing three screens into the app.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---
## Task 3: TokenStore, the one file that knows web storage differs

The web build cannot use `SecureStore`; there is no equivalent in a browser. Keeping that split behind a single interface is what stops `Platform.OS` checks spreading into screens.

**Files:**
- Create: `mobile/src/auth/TokenStore.ts`
- Test: `mobile/src/auth/__tests__/TokenStore.test.ts`

**Interfaces:**
- Consumes: nothing beyond Task 1's workspace.
- Produces: `interface TokenStore { get(): Promise<string | null>; set(token: string): Promise<void>; clear(): Promise<void> }`, plus the concrete `webTokenStore`, `nativeTokenStore`, and the platform-selected `tokenStore`.

- [ ] **Step 1: Write the failing test**

Both implementations are exported by name so each is testable directly, rather than mocking `Platform` and hoping the right branch ran.

Create `mobile/src/auth/__tests__/TokenStore.test.ts`:

```ts
import * as SecureStore from 'expo-secure-store';

import { nativeTokenStore, TOKEN_KEY, webTokenStore } from '../TokenStore';

describe('webTokenStore', () => {
  beforeEach(() => window.localStorage.clear());

  it('round-trips a token', async () => {
    await webTokenStore.set('abc123');

    expect(await webTokenStore.get()).toBe('abc123');
  });

  it('returns null when nothing is stored', async () => {
    expect(await webTokenStore.get()).toBeNull();
  });

  it('clears the token', async () => {
    await webTokenStore.set('abc123');
    await webTokenStore.clear();

    expect(await webTokenStore.get()).toBeNull();
  });

  it('survives storage being unavailable', async () => {
    // Safari in private mode throws on setItem rather than returning.
    const spy = jest.spyOn(window.localStorage.__proto__, 'setItem').mockImplementation(() => {
      throw new Error('QuotaExceededError');
    });

    await expect(webTokenStore.set('abc123')).resolves.toBeUndefined();

    spy.mockRestore();
  });
});

describe('nativeTokenStore', () => {
  beforeEach(() => jest.clearAllMocks());

  it('reads through SecureStore', async () => {
    jest.mocked(SecureStore.getItemAsync).mockResolvedValueOnce('abc123');

    expect(await nativeTokenStore.get()).toBe('abc123');
    expect(SecureStore.getItemAsync).toHaveBeenCalledWith(TOKEN_KEY);
  });

  it('writes through SecureStore', async () => {
    await nativeTokenStore.set('abc123');

    expect(SecureStore.setItemAsync).toHaveBeenCalledWith(TOKEN_KEY, 'abc123');
  });

  it('clears through SecureStore', async () => {
    await nativeTokenStore.clear();

    expect(SecureStore.deleteItemAsync).toHaveBeenCalledWith(TOKEN_KEY);
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd mobile && npm test -- TokenStore
```

Expected: FAIL — "Cannot find module '../TokenStore'".

- [ ] **Step 3: Write the implementation**

Create `mobile/src/auth/TokenStore.ts`:

```ts
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

export const TOKEN_KEY = 'cars-images.token';

export interface TokenStore {
  get(): Promise<string | null>;
  set(token: string): Promise<void>;
  clear(): Promise<void>;
}

/**
 * The web build has no SecureStore equivalent, so the token lives in
 * localStorage. Scoped token abilities are the mitigation for that: a
 * stolen web token can do only what the API let it do.
 *
 * Every call is guarded because a browser in private mode can throw on
 * access rather than returning empty. A missing token means "signed out",
 * which is a recoverable state; a thrown error at module load is not.
 */
export const webTokenStore: TokenStore = {
  async get() {
    try {
      return window.localStorage.getItem(TOKEN_KEY);
    } catch {
      return null;
    }
  },
  async set(token) {
    try {
      window.localStorage.setItem(TOKEN_KEY, token);
    } catch {
      // Storage unavailable: the session still works for this page load.
    }
  },
  async clear() {
    try {
      window.localStorage.removeItem(TOKEN_KEY);
    } catch {
      // Nothing to do - the in-memory token is cleared by AuthContext.
    }
  },
};

export const nativeTokenStore: TokenStore = {
  get: () => SecureStore.getItemAsync(TOKEN_KEY),
  set: (token) => SecureStore.setItemAsync(TOKEN_KEY, token),
  clear: () => SecureStore.deleteItemAsync(TOKEN_KEY),
};

export const tokenStore: TokenStore = Platform.OS === 'web' ? webTokenStore : nativeTokenStore;
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd mobile && npm test -- TokenStore
```

Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add mobile/src/auth
git commit -m "feat(mobile): store the token per platform behind one interface

SecureStore on native, localStorage on web. Exactly one file knows the
platforms differ, so no screen ever branches on Platform.OS.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: The API client

One place builds URLs, attaches the bearer header, unwraps the envelope, validates with Zod, and handles 401. Screens never call `fetch`.

**Files:**
- Create: `mobile/src/api/client.ts`
- Test: `mobile/src/api/__tests__/client.test.ts`

**Interfaces:**
- Consumes: `schemas.ts` from Task 2 (`single`, `cursorPage`, `ValidationErrorSchema`).
- Produces:
  - `class ApiError extends Error { status: number; body: unknown }`
  - `class ApiValidationError extends ApiError { fieldErrors: Record<string, string[]> }`
  - `configureApiClient(options: { onUnauthorized: () => void; getToken: () => string | null }): void`
  - `apiRequest<T>(path: string, options?: { method?: string; body?: unknown; query?: QueryParams; schema?: z.ZodType<T> }): Promise<T>`
  - `type QueryParams = Record<string, string | number | boolean | null | undefined>`

- [ ] **Step 1: Write the failing test**

Create `mobile/src/api/__tests__/client.test.ts`:

```ts
import { z } from 'zod';

import { ApiError, apiRequest, ApiValidationError, configureApiClient } from '../client';

const json = (body: unknown, status = 200) =>
  Promise.resolve(
    new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    }),
  );

describe('apiRequest', () => {
  let onUnauthorized: jest.Mock;
  let token: string | null;

  beforeEach(() => {
    token = 'test-token';
    onUnauthorized = jest.fn();
    configureApiClient({ onUnauthorized, getToken: () => token });
    global.fetch = jest.fn();
  });

  it('sends the bearer token and asks for JSON', async () => {
    jest.mocked(global.fetch).mockReturnValueOnce(json({ data: { id: 1 } }));

    await apiRequest('/images/1');

    const [url, init] = jest.mocked(global.fetch).mock.calls[0]!;
    expect(String(url)).toContain('/api/v1/images/1');
    expect((init?.headers as Record<string, string>).Authorization).toBe('Bearer test-token');
    expect((init?.headers as Record<string, string>).Accept).toBe('application/json');
  });

  it('omits the Authorization header when signed out', async () => {
    token = null;
    jest.mocked(global.fetch).mockReturnValueOnce(json({ token: 'x' }, 201));

    await apiRequest('/auth/login', { method: 'POST', body: { email: 'a@b.c' } });

    const [, init] = jest.mocked(global.fetch).mock.calls[0]!;
    expect((init?.headers as Record<string, string>).Authorization).toBeUndefined();
  });

  it('serialises query params and encodes booleans as true/false strings', async () => {
    jest.mocked(global.fetch).mockReturnValueOnce(json({ data: [] }));

    await apiRequest('/images', {
      query: { make: 'Toyota', make_confirmed: true, year: 1997, model: null, color: undefined },
    });

    const url = String(jest.mocked(global.fetch).mock.calls[0]![0]);
    expect(url).toContain('make=Toyota');
    // The Form Request remaps "true"/"false"; 1/0 would also pass, but the
    // string spelling is what a JS client naturally sends and what is tested.
    expect(url).toContain('make_confirmed=true');
    expect(url).toContain('year=1997');
    // Null and undefined are omitted entirely rather than sent empty, which
    // would 422 against the `sometimes` rules.
    expect(url).not.toContain('model=');
    expect(url).not.toContain('color=');
  });

  it('validates the response against the schema', async () => {
    jest.mocked(global.fetch).mockReturnValueOnce(json({ id: 1, name: 'Ada' }));

    const schema = z.object({ id: z.number(), name: z.string() });

    await expect(apiRequest('/whatever', { schema })).resolves.toEqual({ id: 1, name: 'Ada' });
  });

  it('throws when the response does not match the schema', async () => {
    jest.mocked(global.fetch).mockReturnValueOnce(json({ id: 'not-a-number' }));

    const schema = z.object({ id: z.number() });

    await expect(apiRequest('/whatever', { schema })).rejects.toThrow(/contract/i);
  });

  it('calls onUnauthorized exactly once for a 401 and still throws', async () => {
    jest.mocked(global.fetch).mockReturnValueOnce(json({ message: 'Unauthenticated.' }, 401));

    await expect(apiRequest('/images')).rejects.toBeInstanceOf(ApiError);
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });

  it('does not sign the user out on a 403', async () => {
    jest.mocked(global.fetch).mockReturnValueOnce(json({ message: 'Forbidden.' }, 403));

    // A missing ability is not a dead token - signing out would be wrong.
    await expect(apiRequest('/errors')).rejects.toMatchObject({ status: 403 });
    expect(onUnauthorized).not.toHaveBeenCalled();
  });

  it('exposes field errors from a 422', async () => {
    jest.mocked(global.fetch).mockReturnValueOnce(
      json({ message: 'The given data was invalid.', errors: { to_year: ['Too wide.'] } }, 422),
    );

    await expect(apiRequest('/searches', { method: 'POST', body: {} })).rejects.toBeInstanceOf(
      ApiValidationError,
    );
  });

  it('returns undefined for a 204', async () => {
    jest
      .mocked(global.fetch)
      .mockReturnValueOnce(Promise.resolve(new Response(null, { status: 204 })));

    await expect(apiRequest('/auth/logout', { method: 'POST' })).resolves.toBeUndefined();
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd mobile && npm test -- client
```

Expected: FAIL — "Cannot find module '../client'".

- [ ] **Step 3: Write the implementation**

Create `mobile/src/api/client.ts`:

```ts
import type { z } from 'zod';

import { ValidationErrorSchema } from './schemas';

export type QueryParams = Record<string, string | number | boolean | null | undefined>;

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly body: unknown = undefined,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

export class ApiValidationError extends ApiError {
  constructor(
    message: string,
    public readonly fieldErrors: Record<string, string[]>,
    body: unknown,
  ) {
    super(422, message, body);
    this.name = 'ApiValidationError';
  }

  /** The first message for a field, for rendering under an input. */
  first(field: string): string | undefined {
    return this.fieldErrors[field]?.[0];
  }
}

interface ClientConfig {
  onUnauthorized: () => void;
  getToken: () => string | null;
}

let config: ClientConfig = {
  onUnauthorized: () => {},
  getToken: () => null,
};

export function configureApiClient(next: ClientConfig): void {
  config = next;
}

/**
 * Inlined at build time by Metro. There is no runtime config file to fetch,
 * so a deployed build cannot be pointed at the wrong backend by accident.
 */
const BASE_URL = process.env.EXPO_PUBLIC_API_URL ?? 'http://localhost:8000';

function buildUrl(path: string, query?: QueryParams): string {
  const url = new URL(`/api/v1${path}`, BASE_URL);

  for (const [key, value] of Object.entries(query ?? {})) {
    // Absent is not the same as empty: the list filters are `sometimes`
    // rules, so an empty string would 422 rather than mean "no filter".
    if (value === null || value === undefined || value === '') continue;

    url.searchParams.set(key, String(value));
  }

  return url.toString();
}

interface RequestOptions<T> {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE';
  body?: unknown;
  query?: QueryParams;
  schema?: z.ZodType<T>;
}

export async function apiRequest<T = unknown>(
  path: string,
  options: RequestOptions<T> = {},
): Promise<T> {
  const { method = 'GET', body, query, schema } = options;
  const token = config.getToken();

  const headers: Record<string, string> = { Accept: 'application/json' };
  if (token) headers.Authorization = `Bearer ${token}`;
  if (body !== undefined) headers['Content-Type'] = 'application/json';

  const response = await fetch(buildUrl(path, query), {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  if (response.status === 204) {
    return undefined as T;
  }

  const payload: unknown = await response.json().catch(() => undefined);

  if (!response.ok) {
    throw toError(response.status, payload, config);
  }

  if (!schema) {
    return payload as T;
  }

  const parsed = schema.safeParse(payload);

  if (!parsed.success) {
    // Drift between the PHP Resource and this schema surfaces here, at the
    // fetch, naming the field - not as a crash three screens later.
    throw new ApiError(
      response.status,
      `The API response did not match the client contract for ${path}: ${parsed.error.issues
        .map((issue) => `${issue.path.join('.')} ${issue.message}`)
        .join('; ')}`,
      payload,
    );
  }

  return parsed.data;
}

function toError(status: number, payload: unknown, current: ClientConfig): ApiError {
  if (status === 401) {
    // The single place a dead token signs the user out. A 403 must NOT do
    // this: it means the token is alive but lacks an ability.
    current.onUnauthorized();

    return new ApiError(401, 'Your session has expired. Please sign in again.', payload);
  }

  if (status === 422) {
    const parsed = ValidationErrorSchema.safeParse(payload);

    if (parsed.success) {
      return new ApiValidationError(parsed.data.message, parsed.data.errors, payload);
    }
  }

  if (status === 429) {
    return new ApiError(429, 'Too many requests. Wait a moment and try again.', payload);
  }

  const message =
    typeof payload === 'object' && payload !== null && 'message' in payload
      ? String((payload as { message: unknown }).message)
      : `The request failed (${status}).`;

  return new ApiError(status, message, payload);
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd mobile && npm test -- client && npm run typecheck
```

Expected: PASS (9 tests), typecheck clean.

- [ ] **Step 5: Commit**

```bash
git add mobile/src/api/client.ts mobile/src/api/__tests__/client.test.ts
git commit -m "feat(mobile): add the API client with Zod validation and central 401 handling

One place builds the URL, attaches the bearer token, and decides what an
error means. A 401 signs the user out exactly once; a 403 does not, because
a missing ability is not a dead token.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Auth context, login, and the route guard

**Files:**
- Create: `mobile/src/auth/AuthContext.tsx`
- Create: `mobile/app/login.tsx`
- Create: `mobile/app/(app)/_layout.tsx`
- Create: `mobile/src/ui/Screen.tsx`, `mobile/src/ui/ErrorBanner.tsx`
- Modify: `mobile/app/_layout.tsx` (wrap in `AuthProvider`)
- Test: `mobile/src/auth/__tests__/AuthContext.test.tsx`

**Interfaces:**
- Consumes: `tokenStore` (Task 3); `apiRequest`, `configureApiClient`, `ApiValidationError` (Task 4); `LoginResponseSchema`, `single`, `UserSchema` (Task 2).
- Produces:
  - `<AuthProvider>` — restores the stored token on mount and configures the client
  - `useAuth(): { status: 'loading' | 'authenticated' | 'anonymous'; user: User | null; signIn(email: string, password: string, deviceName: string): Promise<void>; signOut(): Promise<void> }`

- [ ] **Step 1: Write the failing test**

Create `mobile/src/auth/__tests__/AuthContext.test.tsx`:

```tsx
import { act, renderHook, waitFor } from '@testing-library/react-native';
import type { ReactNode } from 'react';

import * as client from '../../api/client';
import { AuthProvider, useAuth } from '../AuthContext';
import { tokenStore } from '../TokenStore';

const wrapper = ({ children }: { children: ReactNode }) => <AuthProvider>{children}</AuthProvider>;

describe('useAuth', () => {
  beforeEach(async () => {
    jest.restoreAllMocks();
    await tokenStore.clear();
  });

  it('starts anonymous when nothing is stored', async () => {
    const { result } = renderHook(() => useAuth(), { wrapper });

    await waitFor(() => expect(result.current.status).toBe('anonymous'));
    expect(result.current.user).toBeNull();
  });

  it('restores a stored token and confirms it against /auth/me', async () => {
    await tokenStore.set('stored-token');
    jest
      .spyOn(client, 'apiRequest')
      .mockResolvedValueOnce({ data: { id: 7, name: 'Ada', email: 'ada@example.test' } });

    const { result } = renderHook(() => useAuth(), { wrapper });

    await waitFor(() => expect(result.current.status).toBe('authenticated'));
    expect(result.current.user?.id).toBe(7);
  });

  it('discards a stored token the API rejects', async () => {
    await tokenStore.set('revoked-token');
    jest
      .spyOn(client, 'apiRequest')
      .mockRejectedValueOnce(new client.ApiError(401, 'Your session has expired.'));

    const { result } = renderHook(() => useAuth(), { wrapper });

    await waitFor(() => expect(result.current.status).toBe('anonymous'));
    expect(await tokenStore.get()).toBeNull();
  });

  it('stores the token on a successful sign-in', async () => {
    jest.spyOn(client, 'apiRequest').mockResolvedValueOnce({
      token: 'fresh-token',
      token_type: 'Bearer',
      abilities: ['search:read', 'search:write', 'review:write', 'errors:read'],
      user: { id: 7, name: 'Ada', email: 'ada@example.test' },
    });

    const { result } = renderHook(() => useAuth(), { wrapper });
    await waitFor(() => expect(result.current.status).toBe('anonymous'));

    await act(async () => {
      await result.current.signIn('ada@example.test', 'password', 'test-device');
    });

    expect(result.current.status).toBe('authenticated');
    expect(await tokenStore.get()).toBe('fresh-token');
  });

  it('clears the token on sign-out even if the API call fails', async () => {
    await tokenStore.set('stored-token');
    jest
      .spyOn(client, 'apiRequest')
      .mockResolvedValueOnce({ data: { id: 7, name: 'Ada', email: 'ada@example.test' } })
      .mockRejectedValueOnce(new client.ApiError(500, 'Server error'));

    const { result } = renderHook(() => useAuth(), { wrapper });
    await waitFor(() => expect(result.current.status).toBe('authenticated'));

    await act(async () => {
      await result.current.signOut();
    });

    // A failed revoke must not strand the user in a signed-in shell with a
    // token the server has already forgotten.
    expect(result.current.status).toBe('anonymous');
    expect(await tokenStore.get()).toBeNull();
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd mobile && npm test -- AuthContext
```

Expected: FAIL — "Cannot find module '../AuthContext'".

- [ ] **Step 3: Write the implementation**

Create `mobile/src/auth/AuthContext.tsx`:

```tsx
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';

import { apiRequest, configureApiClient } from '../api/client';
import { LoginResponseSchema, single, UserSchema } from '../api/schemas';
import type { User } from '../api/schemas';
import { tokenStore } from './TokenStore';

type Status = 'loading' | 'authenticated' | 'anonymous';

interface AuthValue {
  status: Status;
  user: User | null;
  signIn: (email: string, password: string, deviceName: string) => Promise<void>;
  signOut: () => Promise<void>;
}

const AuthContext = createContext<AuthValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<Status>('loading');
  const [user, setUser] = useState<User | null>(null);

  // A ref, not state: the client reads the token synchronously on every
  // request, and a stale closure would send the previous token.
  const tokenRef = useRef<string | null>(null);

  const signOut = useCallback(async () => {
    if (tokenRef.current) {
      // Best effort. If the revoke fails the local token still goes - the
      // alternative is a signed-in shell holding a token the server may
      // already have forgotten.
      await apiRequest('/auth/logout', { method: 'POST' }).catch(() => undefined);
    }

    tokenRef.current = null;
    await tokenStore.clear();
    setUser(null);
    setStatus('anonymous');
  }, []);

  // Configured once, before the restore below can fire a request.
  useEffect(() => {
    configureApiClient({
      getToken: () => tokenRef.current,
      onUnauthorized: () => {
        void signOut();
      },
    });
  }, [signOut]);

  useEffect(() => {
    let cancelled = false;

    const restore = async () => {
      const stored = await tokenStore.get();

      if (!stored) {
        if (!cancelled) setStatus('anonymous');

        return;
      }

      tokenRef.current = stored;

      try {
        const me = await apiRequest('/auth/me', { schema: single(UserSchema) });

        if (cancelled) return;

        setUser(me.data);
        setStatus('authenticated');
      } catch {
        // Revoked, expired, or the server is unreachable. Either way there
        // is nothing to show behind the guard.
        if (cancelled) return;

        tokenRef.current = null;
        await tokenStore.clear();
        setStatus('anonymous');
      }
    };

    void restore();

    return () => {
      cancelled = true;
    };
  }, []);

  const signIn = useCallback(async (email: string, password: string, deviceName: string) => {
    const result = await apiRequest('/auth/login', {
      method: 'POST',
      body: { email, password, device_name: deviceName },
      schema: LoginResponseSchema,
    });

    tokenRef.current = result.token;
    await tokenStore.set(result.token);
    setUser(result.user);
    setStatus('authenticated');
  }, []);

  const value = useMemo<AuthValue>(
    () => ({ status, user, signIn, signOut }),
    [status, user, signIn, signOut],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthValue {
  const value = useContext(AuthContext);

  if (!value) {
    throw new Error('useAuth must be used inside an AuthProvider.');
  }

  return value;
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd mobile && npm test -- AuthContext
```

Expected: PASS (5 tests).

- [ ] **Step 5: Add the shared screen shell**

Create `mobile/src/ui/Screen.tsx`:

```tsx
import type { ReactNode } from 'react';
import { View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

export function Screen({ children }: { children: ReactNode }) {
  return (
    <SafeAreaView className="flex-1 bg-slate-900">
      <View className="flex-1 p-4">{children}</View>
    </SafeAreaView>
  );
}
```

Create `mobile/src/ui/ErrorBanner.tsx`:

```tsx
import { Text, View } from 'react-native';

export function ErrorBanner({ message }: { message: string }) {
  return (
    <View className="mb-3 rounded-lg border border-red-500/40 bg-red-500/10 p-3">
      <Text className="text-sm text-red-200">{message}</Text>
    </View>
  );
}
```

- [ ] **Step 6: Wrap the root layout in AuthProvider**

Modify `mobile/app/_layout.tsx` — add the import and wrap the `Stack`:

```tsx
import '../global.css';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useState } from 'react';

import { AuthProvider } from '@/auth/AuthContext';

export default function RootLayout() {
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            retry: 2,
            staleTime: 30_000,
          },
        },
      }),
  );

  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <StatusBar style="auto" />
        <Stack screenOptions={{ headerShown: false }} />
      </AuthProvider>
    </QueryClientProvider>
  );
}
```

- [ ] **Step 7: Write the login screen**

Create `mobile/app/login.tsx`:

```tsx
import { router } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Platform, Pressable, Text, TextInput, View } from 'react-native';

import { ApiValidationError } from '@/api/client';
import { useAuth } from '@/auth/AuthContext';
import { ErrorBanner } from '@/ui/ErrorBanner';
import { Screen } from '@/ui/Screen';

/** Names the token so a lost device can be revoked without rotating the rest. */
const DEVICE_NAME = Platform.select({ web: 'web', android: 'android', ios: 'ios' }) ?? 'unknown';

export default function Login() {
  const { signIn } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setBusy(true);
    setError(null);

    try {
      await signIn(email.trim(), password, DEVICE_NAME);
      router.replace('/(app)/search');
    } catch (caught) {
      setError(
        caught instanceof ApiValidationError
          ? (caught.first('email') ?? caught.message)
          : caught instanceof Error
            ? caught.message
            : 'Sign in failed.',
      );
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <View className="flex-1 justify-center gap-3">
        <Text className="mb-2 text-2xl font-bold text-white">Sign in</Text>

        {error ? <ErrorBanner message={error} /> : null}

        <TextInput
          className="rounded-lg bg-slate-800 px-4 py-3 text-white"
          placeholder="Email"
          placeholderTextColor="#94a3b8"
          autoCapitalize="none"
          autoComplete="email"
          keyboardType="email-address"
          value={email}
          onChangeText={setEmail}
        />

        <TextInput
          className="rounded-lg bg-slate-800 px-4 py-3 text-white"
          placeholder="Password"
          placeholderTextColor="#94a3b8"
          autoCapitalize="none"
          secureTextEntry
          value={password}
          onChangeText={setPassword}
          onSubmitEditing={submit}
        />

        <Pressable
          className="mt-2 items-center rounded-lg bg-sky-500 px-6 py-3 active:opacity-80"
          disabled={busy}
          onPress={submit}
        >
          {busy ? (
            <ActivityIndicator color="white" />
          ) : (
            <Text className="text-base font-semibold text-white">Sign in</Text>
          )}
        </Pressable>

        <Text className="mt-4 text-center text-xs text-slate-400">
          Credentials are issued on request. There is no demo account.
        </Text>
      </View>
    </Screen>
  );
}
```

- [ ] **Step 8: Write the authenticated group layout and guard**

Create `mobile/app/(app)/_layout.tsx`:

```tsx
import { Redirect, Tabs } from 'expo-router';
import { ActivityIndicator, View } from 'react-native';

import { useAuth } from '@/auth/AuthContext';

export default function AppLayout() {
  const { status } = useAuth();

  if (status === 'loading') {
    return (
      <View className="flex-1 items-center justify-center bg-slate-900">
        <ActivityIndicator color="#38bdf8" />
      </View>
    );
  }

  if (status === 'anonymous') {
    return <Redirect href="/login" />;
  }

  return (
    <Tabs
      screenOptions={{
        headerStyle: { backgroundColor: '#0f172a' },
        headerTintColor: '#f8fafc',
        tabBarStyle: { backgroundColor: '#0f172a', borderTopColor: '#1e293b' },
        tabBarActiveTintColor: '#38bdf8',
        tabBarInactiveTintColor: '#94a3b8',
      }}
    >
      <Tabs.Screen name="search" options={{ title: 'Search' }} />
      <Tabs.Screen name="runs" options={{ title: 'Runs' }} />
      <Tabs.Screen name="review" options={{ title: 'Review' }} />
      <Tabs.Screen name="health" options={{ title: 'Health' }} />
    </Tabs>
  );
}
```

- [ ] **Step 9: Add placeholder tab screens so the router resolves**

The four tab directories must each have an `index.tsx` or the group layout throws. Create these four files; Tasks 6–8 replace their bodies.

`mobile/app/(app)/search/index.tsx`, `mobile/app/(app)/runs/index.tsx`, `mobile/app/(app)/review/index.tsx`, `mobile/app/(app)/health/index.tsx` — each with the section name substituted:

```tsx
import { Text } from 'react-native';

import { Screen } from '@/ui/Screen';

export default function Search() {
  return (
    <Screen>
      <Text className="text-white">Search</Text>
    </Screen>
  );
}
```

- [ ] **Step 10: Verify the whole gate**

```bash
cd mobile && npm run typecheck && npm run lint && npm test && npm run build:web
```

Expected: all pass. `mobile/dist/` now contains `login.html` and the four tab routes.

- [ ] **Step 11: Commit**

```bash
git add mobile/app mobile/src
git commit -m "feat(mobile): add token auth, the login screen and the route guard

The landing screen stays reachable signed out - it is the mitigation for
having no demo account. Everything under (app) redirects to login until
/auth/me confirms the stored token.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---
## Task 6: TanStack Query read hooks

Every screen reads through a hook; no screen calls `apiRequest` directly. The infinite hooks turn `meta.next_cursor` into `getNextPageParam`, which is the whole reason the API was built with cursors.

**Files:**
- Create: `mobile/src/api/hooks/useImages.ts`, `useSearches.ts`, `useHealth.ts`, `useErrors.ts`
- Create: `mobile/src/api/queryKeys.ts`
- Test: `mobile/src/api/hooks/__tests__/useImages.test.tsx`

**Interfaces:**
- Consumes: `apiRequest` (Task 4); `ImageSchema`, `SearchSchema`, `ErrorEventSchema`, `HealthSummarySchema`, `single`, `cursorPage` (Task 2).
- Produces:
  - `type ImageFilters = { make?: string; model?: string; year?: number; make_confirmed?: boolean; year_confirmed?: boolean; review_status?: ReviewStatus; download_status?: string }`
  - `useImages(filters?: ImageFilters)` — infinite, returns pages of `CursorPage<Image>`
  - `useImage(id: number)` — returns `Image`
  - `useSearchImages(searchId: number, filters?: ImageFilters)` — infinite
  - `useSearches(status?: SearchStatus)` — infinite, pages of `CursorPage<Search>`
  - `useSearch(id: number)` — returns `Search`
  - `useHealth()` — returns `HealthSummary`
  - `useErrors(filters?: { context?: ErrorContext; severity?: string })` — infinite
  - `queryKeys` — the shared key factory every hook and mutation uses

- [ ] **Step 1: Write the key factory**

Create `mobile/src/api/queryKeys.ts`. A single factory means an invalidation in Task 8 cannot miss a cache the grid is using.

```ts
import type { ErrorContext, ReviewStatus, SearchStatus } from './schemas';

export interface ImageFilters {
  make?: string;
  model?: string;
  year?: number;
  make_confirmed?: boolean;
  year_confirmed?: boolean;
  review_status?: ReviewStatus;
  download_status?: 'not_downloaded' | 'downloading' | 'downloaded' | 'failed';
}

export interface ErrorFilters {
  context?: ErrorContext;
  severity?: 'error' | 'warning';
}

export const queryKeys = {
  images: (filters: ImageFilters = {}) => ['images', filters] as const,
  image: (id: number) => ['images', id] as const,
  searchImages: (searchId: number, filters: ImageFilters = {}) =>
    ['searches', searchId, 'images', filters] as const,
  searches: (status?: SearchStatus) => ['searches', { status }] as const,
  search: (id: number) => ['searches', id] as const,
  health: () => ['health'] as const,
  errors: (filters: ErrorFilters = {}) => ['errors', filters] as const,
};
```

- [ ] **Step 2: Write the failing test**

Create `mobile/src/api/hooks/__tests__/useImages.test.tsx`:

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react-native';
import type { ReactNode } from 'react';

import imagesFixture from '../../__fixtures__/images.json';
import * as client from '../../client';
import { useImages } from '../useImages';

const wrapper = ({ children }: { children: ReactNode }) => {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
};

describe('useImages', () => {
  beforeEach(() => jest.restoreAllMocks());

  it('fetches the first page with no cursor', async () => {
    const spy = jest.spyOn(client, 'apiRequest').mockResolvedValue(imagesFixture);

    const { result } = renderHook(() => useImages({ make: 'Toyota' }), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(spy).toHaveBeenCalledWith(
      '/images',
      expect.objectContaining({ query: expect.objectContaining({ make: 'Toyota', cursor: undefined }) }),
    );
  });

  it('follows meta.next_cursor for the next page', async () => {
    const spy = jest
      .spyOn(client, 'apiRequest')
      .mockResolvedValueOnce(imagesFixture)
      .mockResolvedValueOnce({
        ...imagesFixture,
        meta: { ...imagesFixture.meta, next_cursor: null },
      });

    const { result } = renderHook(() => useImages(), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.hasNextPage).toBe(true);

    await result.current.fetchNextPage();

    await waitFor(() => expect(result.current.hasNextPage).toBe(false));
    expect(spy).toHaveBeenLastCalledWith(
      '/images',
      expect.objectContaining({
        query: expect.objectContaining({ cursor: imagesFixture.meta.next_cursor }),
      }),
    );
  });
});
```

- [ ] **Step 3: Run it to verify it fails**

```bash
cd mobile && npm test -- useImages
```

Expected: FAIL — "Cannot find module '../useImages'".

- [ ] **Step 4: Write the image hooks**

Create `mobile/src/api/hooks/useImages.ts`:

```ts
import { useInfiniteQuery, useQuery } from '@tanstack/react-query';

import { apiRequest } from '../client';
import type { ImageFilters } from '../queryKeys';
import { queryKeys } from '../queryKeys';
import { cursorPage, ImageSchema, single } from '../schemas';

const imagePage = cursorPage(ImageSchema);
const oneImage = single(ImageSchema);

export function useImages(filters: ImageFilters = {}) {
  return useInfiniteQuery({
    queryKey: queryKeys.images(filters),
    // null rather than undefined so the first page is an explicit value;
    // the client omits null query params, so no cursor is sent.
    initialPageParam: null as string | null,
    queryFn: ({ pageParam }) =>
      apiRequest('/images', {
        query: { ...filters, cursor: pageParam ?? undefined },
        schema: imagePage,
      }),
    getNextPageParam: (lastPage) => lastPage.meta.next_cursor,
  });
}

export function useSearchImages(searchId: number, filters: ImageFilters = {}) {
  return useInfiniteQuery({
    queryKey: queryKeys.searchImages(searchId, filters),
    initialPageParam: null as string | null,
    queryFn: ({ pageParam }) =>
      apiRequest(`/searches/${searchId}/images`, {
        query: { ...filters, cursor: pageParam ?? undefined },
        schema: imagePage,
      }),
    getNextPageParam: (lastPage) => lastPage.meta.next_cursor,
  });
}

export function useImage(id: number) {
  return useQuery({
    queryKey: queryKeys.image(id),
    queryFn: async () => (await apiRequest(`/images/${id}`, { schema: oneImage })).data,
  });
}
```

- [ ] **Step 5: Write the search, health and error hooks**

Create `mobile/src/api/hooks/useSearches.ts`:

```ts
import { useInfiniteQuery, useQuery } from '@tanstack/react-query';

import { apiRequest } from '../client';
import { queryKeys } from '../queryKeys';
import { cursorPage, SearchSchema, single } from '../schemas';
import type { SearchStatus } from '../schemas';

const searchPage = cursorPage(SearchSchema);
const oneSearch = single(SearchSchema);

export function useSearches(status?: SearchStatus) {
  return useInfiniteQuery({
    queryKey: queryKeys.searches(status),
    initialPageParam: null as string | null,
    queryFn: ({ pageParam }) =>
      apiRequest('/searches', {
        query: { status, cursor: pageParam ?? undefined },
        schema: searchPage,
      }),
    getNextPageParam: (lastPage) => lastPage.meta.next_cursor,
  });
}

export function useSearch(id: number) {
  return useQuery({
    queryKey: queryKeys.search(id),
    queryFn: async () => (await apiRequest(`/searches/${id}`, { schema: oneSearch })).data,
  });
}
```

Create `mobile/src/api/hooks/useHealth.ts`:

```ts
import { useQuery } from '@tanstack/react-query';

import { apiRequest } from '../client';
import { queryKeys } from '../queryKeys';
import { HealthSummarySchema, single } from '../schemas';

const health = single(HealthSummarySchema);

export function useHealth() {
  return useQuery({
    queryKey: queryKeys.health(),
    queryFn: async () => (await apiRequest('/health/summary', { schema: health })).data,
    // "Is the pipeline broken now" goes stale fast.
    staleTime: 15_000,
  });
}
```

Create `mobile/src/api/hooks/useErrors.ts`:

```ts
import { useInfiniteQuery } from '@tanstack/react-query';

import { apiRequest } from '../client';
import type { ErrorFilters } from '../queryKeys';
import { queryKeys } from '../queryKeys';
import { cursorPage, ErrorEventSchema } from '../schemas';

const errorPage = cursorPage(ErrorEventSchema);

export function useErrors(filters: ErrorFilters = {}) {
  return useInfiniteQuery({
    queryKey: queryKeys.errors(filters),
    initialPageParam: null as string | null,
    queryFn: ({ pageParam }) =>
      apiRequest('/errors', {
        query: { ...filters, cursor: pageParam ?? undefined },
        schema: errorPage,
      }),
    getNextPageParam: (lastPage) => lastPage.meta.next_cursor,
  });
}
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
cd mobile && npm test -- useImages && npm run typecheck
```

Expected: PASS (2 tests), typecheck clean.

- [ ] **Step 7: Commit**

```bash
git add mobile/src/api
git commit -m "feat(mobile): add the TanStack Query read hooks

Every list follows meta.next_cursor, so an infinite scroll neither skips
nor repeats a row when a harvest inserts mid-scroll. One key factory, so a
later invalidation cannot miss a cache the grid is using.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Search, browse and runs screens

**Files:**
- Create: `mobile/src/api/hooks/useCreateSearch.ts`
- Create: `mobile/src/ui/ImageCard.tsx`, `mobile/src/ui/StatusBadge.tsx`, `mobile/src/ui/EmptyState.tsx`, `mobile/src/ui/InfiniteGrid.tsx`
- Modify: `mobile/app/(app)/search/index.tsx`
- Create: `mobile/app/(app)/search/images.tsx`, `mobile/app/(app)/search/[id].tsx`
- Modify: `mobile/app/(app)/runs/index.tsx`
- Create: `mobile/app/(app)/runs/[id].tsx`
- Test: `mobile/src/api/hooks/__tests__/useCreateSearch.test.tsx`

**Interfaces:**
- Consumes: `useImages`, `useImage`, `useSearches`, `useSearch`, `useSearchImages` (Task 6); `apiRequest`, `ApiError`, `ApiValidationError` (Task 4).
- Produces:
  - `useCreateSearch()` — mutation returning `{ search: Search; outcome: 'created' | 'existing' | 'blocked' | 'failed'; message?: string; retryAfterSeconds?: number }`
  - `MAX_YEAR_SPAN = 3`, `MAX_IMAGES_PER_YEAR = 5`
  - `<InfiniteGrid<T>>`, `<ImageCard>`, `<StatusBadge>`, `<EmptyState>`

- [ ] **Step 1: Write the failing mutation test**

`POST /searches` has four outcomes and only one is a 201. Treating anything else as failure would hide the dedupe hit and lose the user's link to a failed run.

Create `mobile/src/api/hooks/__tests__/useCreateSearch.test.tsx`:

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react-native';
import type { ReactNode } from 'react';

import searchFixture from '../../__fixtures__/search.json';
import * as client from '../../client';
import { MAX_IMAGES_PER_YEAR, MAX_YEAR_SPAN, useCreateSearch } from '../useCreateSearch';

const wrapper = ({ children }: { children: ReactNode }) => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
};

const input = { make: 'Toyota', model: 'RAV4', from_year: 1997, to_year: 1999 };

describe('useCreateSearch', () => {
  beforeEach(() => jest.restoreAllMocks());

  it('mirrors the API caps so the form can enforce them locally', () => {
    expect(MAX_YEAR_SPAN).toBe(3);
    expect(MAX_IMAGES_PER_YEAR).toBe(5);
  });

  it('reports a fresh run as created', async () => {
    jest.spyOn(client, 'apiRequest').mockResolvedValueOnce({ status: 201, body: searchFixture });

    const { result } = renderHook(() => useCreateSearch(), { wrapper });

    await act(async () => {
      await result.current.mutateAsync(input);
    });

    await waitFor(() => expect(result.current.data?.outcome).toBe('created'));
    expect(result.current.data?.search.id).toBe(searchFixture.data.id);
  });

  it('reports a deduped run as existing', async () => {
    jest.spyOn(client, 'apiRequest').mockResolvedValueOnce({ status: 200, body: searchFixture });

    const { result } = renderHook(() => useCreateSearch(), { wrapper });

    await act(async () => {
      await result.current.mutateAsync(input);
    });

    await waitFor(() => expect(result.current.data?.outcome).toBe('existing'));
  });

  it('reports a Wikimedia block without throwing, keeping the run link', async () => {
    jest.spyOn(client, 'apiRequest').mockResolvedValueOnce({
      status: 503,
      body: { ...searchFixture, message: 'Wikimedia is rate-limiting this server.', retry_after_seconds: 120 },
    });

    const { result } = renderHook(() => useCreateSearch(), { wrapper });

    await act(async () => {
      await result.current.mutateAsync(input);
    });

    await waitFor(() => expect(result.current.data?.outcome).toBe('blocked'));
    expect(result.current.data?.retryAfterSeconds).toBe(120);
    expect(result.current.data?.search.id).toBe(searchFixture.data.id);
  });

  it('reports a failed run as failed', async () => {
    jest.spyOn(client, 'apiRequest').mockResolvedValueOnce({
      status: 502,
      body: { ...searchFixture, message: 'The search failed.' },
    });

    const { result } = renderHook(() => useCreateSearch(), { wrapper });

    await act(async () => {
      await result.current.mutateAsync(input);
    });

    await waitFor(() => expect(result.current.data?.outcome).toBe('failed'));
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd mobile && npm test -- useCreateSearch
```

Expected: FAIL — "Cannot find module '../useCreateSearch'".

- [ ] **Step 3: Add a raw-response mode to the client**

The mutation needs the status code, which `apiRequest` currently discards, and it must not throw on 503/502. Add this export to `mobile/src/api/client.ts`, below `apiRequest`:

```ts
/**
 * Like apiRequest, but hands back the status alongside the body and does not
 * throw for the caller's listed statuses.
 *
 * POST /searches answers 201, 200, 503 and 502 with a real search row in
 * every case; only the caller knows that a 503 there is information, not a
 * failure. A 401 still signs the user out.
 */
export async function apiRequestRaw(
  path: string,
  options: RequestOptions<unknown> & { acceptStatuses: number[] },
): Promise<{ status: number; body: unknown }> {
  const { method = 'GET', body, query, acceptStatuses } = options;
  const token = config.getToken();

  const headers: Record<string, string> = { Accept: 'application/json' };
  if (token) headers.Authorization = `Bearer ${token}`;
  if (body !== undefined) headers['Content-Type'] = 'application/json';

  const response = await fetch(buildUrl(path, query), {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  const payload: unknown = await response.json().catch(() => undefined);

  if (!response.ok && !acceptStatuses.includes(response.status)) {
    throw toError(response.status, payload, config);
  }

  return { status: response.status, body: payload };
}
```

- [ ] **Step 4: Write the mutation**

Create `mobile/src/api/hooks/useCreateSearch.ts`:

```ts
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { apiRequestRaw } from '../client';
import { queryKeys } from '../queryKeys';
import { SearchSchema } from '../schemas';
import type { Search } from '../schemas';

/**
 * Mirrors config/cars-images.php. The form enforces these before the round
 * trip so the user sees the limit rather than a 422.
 */
export const MAX_YEAR_SPAN = 3;
export const MAX_IMAGES_PER_YEAR = 5;

export interface CreateSearchInput {
  make: string;
  model?: string;
  from_year: number;
  to_year: number;
  color?: string;
  transmission?: string;
  transparent_background?: boolean;
  images_per_year?: number;
}

export type CreateSearchOutcome = 'created' | 'existing' | 'blocked' | 'failed';

export interface CreateSearchResult {
  search: Search;
  outcome: CreateSearchOutcome;
  message?: string;
  retryAfterSeconds?: number;
}

const envelope = z.object({
  data: SearchSchema,
  message: z.string().optional(),
  retry_after_seconds: z.number().int().optional(),
});

const OUTCOMES: Record<number, CreateSearchOutcome> = {
  201: 'created',
  200: 'existing',
  503: 'blocked',
  502: 'failed',
};

export function useCreateSearch() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: CreateSearchInput): Promise<CreateSearchResult> => {
      const { status, body } = await apiRequestRaw('/searches', {
        method: 'POST',
        body: input,
        // 422 and 429 are NOT listed: a rejected cap or a throttle is a real
        // error the form must surface, not an outcome to render.
        acceptStatuses: [503, 502],
      });

      const parsed = envelope.parse(body);

      return {
        search: parsed.data,
        outcome: OUTCOMES[status] ?? 'failed',
        message: parsed.message,
        retryAfterSeconds: parsed.retry_after_seconds,
      };
    },
    onSuccess: () => {
      // A new run changes both the run list and the health counters.
      void queryClient.invalidateQueries({ queryKey: ['searches'] });
      void queryClient.invalidateQueries({ queryKey: queryKeys.health() });
    },
  });
}
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
cd mobile && npm test -- useCreateSearch
```

Expected: PASS (5 tests).

- [ ] **Step 6: Write the shared UI pieces**

Create `mobile/src/ui/StatusBadge.tsx`:

```tsx
import { Text, View } from 'react-native';

interface Tone {
  bg: string;
  text: string;
}

const NEUTRAL: Tone = { bg: 'bg-slate-500/15', text: 'text-slate-300' };
const GOOD: Tone = { bg: 'bg-emerald-500/15', text: 'text-emerald-300' };
const BUSY: Tone = { bg: 'bg-sky-500/15', text: 'text-sky-300' };
const BAD: Tone = { bg: 'bg-red-500/15', text: 'text-red-300' };

/** Keyed by both review_status and download_status - they never collide. */
const TONE: Record<string, Tone> = {
  completed: GOOD,
  approved: GOOD,
  downloaded: GOOD,
  running: BUSY,
  downloading: BUSY,
  pending: NEUTRAL,
  not_downloaded: NEUTRAL,
  failed: BAD,
  rejected: BAD,
};

export function StatusBadge({ status }: { status: string }) {
  // The background belongs to the pill and the colour to the label; applying
  // one combined string to both paints a second background behind the text.
  const tone = TONE[status] ?? NEUTRAL;

  return (
    <View className={`self-start rounded-full px-2 py-0.5 ${tone.bg}`}>
      <Text className={`text-xs font-medium ${tone.text}`}>{status.replace(/_/g, ' ')}</Text>
    </View>
  );
}
```

Create `mobile/src/ui/EmptyState.tsx`:

```tsx
import { Text, View } from 'react-native';

export function EmptyState({ title, hint }: { title: string; hint?: string }) {
  return (
    <View className="items-center gap-1 p-8">
      <Text className="text-base font-medium text-slate-300">{title}</Text>
      {hint ? <Text className="text-center text-sm text-slate-500">{hint}</Text> : null}
    </View>
  );
}
```

Create `mobile/src/ui/ImageCard.tsx`. `expo-image` rather than RN's `Image`: grids of remote Wikimedia photos need real disk caching and a placeholder while they load.

```tsx
import { Image } from 'expo-image';
import { Link } from 'expo-router';
import { Pressable, Text, View } from 'react-native';

import type { Image as CarImage } from '@/api/schemas';
import { StatusBadge } from './StatusBadge';

export function ImageCard({ image }: { image: CarImage }) {
  return (
    <Link href={{ pathname: '/(app)/search/[id]', params: { id: image.id } }} asChild>
      <Pressable className="mb-3 flex-1 overflow-hidden rounded-xl bg-slate-800 active:opacity-80">
        <Image
          source={image.thumbnail_url ?? image.source_url}
          style={{ width: '100%', aspectRatio: 4 / 3 }}
          contentFit="cover"
          transition={150}
          cachePolicy="disk"
        />
        <View className="gap-1 p-2">
          <Text className="text-sm font-medium text-white" numberOfLines={1}>
            {image.make} {image.model ?? ''} {image.year}
          </Text>
          <StatusBadge status={image.review_status} />
        </View>
      </Pressable>
    </Link>
  );
}
```

Create `mobile/src/ui/InfiniteGrid.tsx`:

```tsx
import type { InfiniteData } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { ActivityIndicator, FlatList, RefreshControl } from 'react-native';

import type { CursorPage } from '@/api/schemas';
import { EmptyState } from './EmptyState';

interface Props<T> {
  query: {
    data?: InfiniteData<CursorPage<T>>;
    isLoading: boolean;
    isRefetching: boolean;
    hasNextPage: boolean;
    isFetchingNextPage: boolean;
    fetchNextPage: () => void;
    refetch: () => void;
  };
  renderItem: (item: T) => ReactElement;
  keyExtractor: (item: T) => string;
  numColumns?: number;
  emptyTitle: string;
  emptyHint?: string;
}

export function InfiniteGrid<T>({
  query,
  renderItem,
  keyExtractor,
  numColumns = 1,
  emptyTitle,
  emptyHint,
}: Props<T>) {
  const items = query.data?.pages.flatMap((page) => page.data) ?? [];

  if (query.isLoading) {
    return <ActivityIndicator className="mt-8" color="#38bdf8" />;
  }

  return (
    <FlatList
      data={items}
      numColumns={numColumns}
      // A multi-column list needs the gap or the cards touch.
      columnWrapperStyle={numColumns > 1 ? { gap: 12 } : undefined}
      keyExtractor={keyExtractor}
      renderItem={({ item }) => renderItem(item)}
      onEndReachedThreshold={0.5}
      onEndReached={() => {
        if (query.hasNextPage && !query.isFetchingNextPage) query.fetchNextPage();
      }}
      refreshControl={
        <RefreshControl refreshing={query.isRefetching} onRefresh={query.refetch} tintColor="#38bdf8" />
      }
      ListEmptyComponent={<EmptyState title={emptyTitle} hint={emptyHint} />}
      ListFooterComponent={
        query.isFetchingNextPage ? <ActivityIndicator className="my-4" color="#38bdf8" /> : null
      }
    />
  );
}
```

- [ ] **Step 7: Write the search form screen**

Replace `mobile/app/(app)/search/index.tsx`:

```tsx
import { router } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, Text, TextInput, View } from 'react-native';

import { ApiValidationError } from '@/api/client';
import {
  MAX_IMAGES_PER_YEAR,
  MAX_YEAR_SPAN,
  useCreateSearch,
} from '@/api/hooks/useCreateSearch';
import { ErrorBanner } from '@/ui/ErrorBanner';
import { Screen } from '@/ui/Screen';

export default function SearchForm() {
  const createSearch = useCreateSearch();
  const [make, setMake] = useState('');
  const [model, setModel] = useState('');
  const [fromYear, setFromYear] = useState('');
  const [toYear, setToYear] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const submit = async () => {
    setError(null);
    setNotice(null);

    const from = Number(fromYear);
    const to = Number(toYear);

    if (!make.trim()) {
      setError('A make is required.');

      return;
    }

    if (!Number.isInteger(from) || !Number.isInteger(to) || from < 1900 || to < 1900) {
      setError('Enter both years as four-digit numbers.');

      return;
    }

    // Checked here as well as server-side: the search runs inline inside the
    // request, so a too-wide span is a limit worth showing before the wait.
    if (Math.abs(to - from) > MAX_YEAR_SPAN) {
      setError(
        `The year range may span at most ${MAX_YEAR_SPAN} years, because the search runs inside the request.`,
      );

      return;
    }

    try {
      const result = await createSearch.mutateAsync({
        make: make.trim(),
        model: model.trim() || undefined,
        from_year: from,
        to_year: to,
        images_per_year: MAX_IMAGES_PER_YEAR,
      });

      if (result.outcome === 'blocked') {
        setNotice(
          `${result.message ?? 'Wikimedia is rate-limiting this server.'}${
            result.retryAfterSeconds ? ` Try again in ${result.retryAfterSeconds}s.` : ''
          }`,
        );
      } else if (result.outcome === 'failed') {
        setNotice(result.message ?? 'The search failed. The reason is in the error log.');
      }

      // Every outcome produced a real run row, so the user always gets a
      // link to it - including the ones that failed.
      router.push({ pathname: '/(app)/runs/[id]', params: { id: result.search.id } });
    } catch (caught) {
      setError(
        caught instanceof ApiValidationError
          ? (caught.first('to_year') ?? caught.first('make') ?? caught.message)
          : caught instanceof Error
            ? caught.message
            : 'The search could not be started.',
      );
    }
  };

  return (
    <Screen>
      <ScrollView contentContainerClassName="gap-3">
        <Text className="text-xl font-bold text-white">New search</Text>
        <Text className="text-sm text-slate-400">
          Runs immediately against Wikimedia Commons. Up to {MAX_YEAR_SPAN + 1} years,{' '}
          {MAX_IMAGES_PER_YEAR} images per year.
        </Text>

        {error ? <ErrorBanner message={error} /> : null}
        {notice ? (
          <View className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3">
            <Text className="text-sm text-amber-200">{notice}</Text>
          </View>
        ) : null}

        <TextInput
          className="rounded-lg bg-slate-800 px-4 py-3 text-white"
          placeholder="Make (e.g. Toyota)"
          placeholderTextColor="#94a3b8"
          value={make}
          onChangeText={setMake}
        />
        <TextInput
          className="rounded-lg bg-slate-800 px-4 py-3 text-white"
          placeholder="Model (optional)"
          placeholderTextColor="#94a3b8"
          value={model}
          onChangeText={setModel}
        />
        <View className="flex-row gap-3">
          <TextInput
            className="flex-1 rounded-lg bg-slate-800 px-4 py-3 text-white"
            placeholder="From year"
            placeholderTextColor="#94a3b8"
            keyboardType="number-pad"
            value={fromYear}
            onChangeText={setFromYear}
          />
          <TextInput
            className="flex-1 rounded-lg bg-slate-800 px-4 py-3 text-white"
            placeholder="To year"
            placeholderTextColor="#94a3b8"
            keyboardType="number-pad"
            value={toYear}
            onChangeText={setToYear}
          />
        </View>

        <Pressable
          className="items-center rounded-lg bg-sky-500 px-6 py-3 active:opacity-80"
          disabled={createSearch.isPending}
          onPress={submit}
        >
          {createSearch.isPending ? (
            <ActivityIndicator color="white" />
          ) : (
            <Text className="text-base font-semibold text-white">Run search</Text>
          )}
        </Pressable>

        {createSearch.isPending ? (
          <Text className="text-center text-xs text-slate-500">
            This runs inline and can take several seconds.
          </Text>
        ) : null}

        <Pressable className="mt-4 items-center py-2" onPress={() => router.push('/(app)/search/images')}>
          <Text className="text-sm text-sky-400">Browse all images</Text>
        </Pressable>
      </ScrollView>
    </Screen>
  );
}
```

- [ ] **Step 8: Write the image grid and detail screens**

Create `mobile/app/(app)/search/images.tsx`:

```tsx
import { useState } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';

import { useImages } from '@/api/hooks/useImages';
import type { ReviewStatus } from '@/api/schemas';
import { ImageCard } from '@/ui/ImageCard';
import { InfiniteGrid } from '@/ui/InfiniteGrid';
import { Screen } from '@/ui/Screen';

const STATUSES: (ReviewStatus | 'all')[] = ['all', 'pending', 'approved', 'rejected'];

export default function ImageGrid() {
  const [make, setMake] = useState('');
  const [status, setStatus] = useState<ReviewStatus | 'all'>('all');

  const query = useImages({
    make: make.trim() || undefined,
    review_status: status === 'all' ? undefined : status,
  });

  return (
    <Screen>
      <TextInput
        className="mb-3 rounded-lg bg-slate-800 px-4 py-3 text-white"
        placeholder="Filter by make"
        placeholderTextColor="#94a3b8"
        value={make}
        onChangeText={setMake}
      />

      <View className="mb-3 flex-row gap-2">
        {STATUSES.map((option) => (
          <Pressable
            key={option}
            className={`rounded-full px-3 py-1 ${status === option ? 'bg-sky-500' : 'bg-slate-800'}`}
            onPress={() => setStatus(option)}
          >
            <Text className={`text-xs ${status === option ? 'text-white' : 'text-slate-300'}`}>
              {option}
            </Text>
          </Pressable>
        ))}
      </View>

      <InfiniteGrid
        query={query}
        numColumns={2}
        keyExtractor={(image) => String(image.id)}
        renderItem={(image) => <ImageCard image={image} />}
        emptyTitle="No images match"
        emptyHint="Try a different make, or run a new search."
      />
    </Screen>
  );
}
```

Create `mobile/app/(app)/search/[id].tsx`:

```tsx
import { Image } from 'expo-image';
import { useLocalSearchParams } from 'expo-router';
import { ActivityIndicator, ScrollView, Text, View } from 'react-native';

import { useImage } from '@/api/hooks/useImages';
import { ErrorBanner } from '@/ui/ErrorBanner';
import { Screen } from '@/ui/Screen';
import { StatusBadge } from '@/ui/StatusBadge';

function Row({ label, value }: { label: string; value: string | null }) {
  if (!value) return null;

  return (
    <View className="border-b border-slate-800 py-2">
      <Text className="text-xs uppercase tracking-wide text-slate-500">{label}</Text>
      <Text className="text-sm text-slate-200">{value}</Text>
    </View>
  );
}

export default function ImageDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const query = useImage(Number(id));

  if (query.isLoading) {
    return (
      <Screen>
        <ActivityIndicator className="mt-8" color="#38bdf8" />
      </Screen>
    );
  }

  if (query.isError || !query.data) {
    return (
      <Screen>
        <ErrorBanner message={query.error instanceof Error ? query.error.message : 'Not found.'} />
      </Screen>
    );
  }

  const image = query.data;

  return (
    <Screen>
      <ScrollView>
        <Image
          source={image.source_url}
          style={{ width: '100%', aspectRatio: 4 / 3, borderRadius: 12 }}
          contentFit="contain"
          transition={150}
          cachePolicy="disk"
        />

        <Text className="mt-3 text-lg font-bold text-white">
          {image.make} {image.model ?? ''} {image.year}
        </Text>

        <View className="mt-2 flex-row gap-2">
          <StatusBadge status={image.review_status} />
          <StatusBadge status={image.download_status} />
        </View>

        <View className="mt-4">
          <Row label="Title" value={image.title} />
          <Row label="Description" value={image.description} />
          <Row label="Licence" value={image.license} />
          <Row label="Attribution" value={image.attribution} />
          <Row
            label="Dimensions"
            value={image.width && image.height ? `${image.width} × ${image.height}` : null}
          />
          <Row label="Source" value={image.source_url} />
          <Row
            label="Machine verdict"
            value={`make ${describe(image.make_confirmed)}, year ${describe(image.year_confirmed)}`}
          />
        </View>
      </ScrollView>
    </Screen>
  );
}

/** null is the machine's "unknown" - neither confirmed nor rejected. */
function describe(flag: boolean | null): string {
  if (flag === null) return 'unchecked';

  return flag ? 'confirmed' : 'not confirmed';
}
```

- [ ] **Step 9: Write the runs screens**

Replace `mobile/app/(app)/runs/index.tsx`:

```tsx
import { Link } from 'expo-router';
import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { useSearches } from '@/api/hooks/useSearches';
import type { SearchStatus } from '@/api/schemas';
import { InfiniteGrid } from '@/ui/InfiniteGrid';
import { Screen } from '@/ui/Screen';
import { StatusBadge } from '@/ui/StatusBadge';

const STATUSES: (SearchStatus | 'all')[] = ['all', 'pending', 'running', 'completed', 'failed'];

export default function Runs() {
  const [status, setStatus] = useState<SearchStatus | 'all'>('all');
  const query = useSearches(status === 'all' ? undefined : status);

  return (
    <Screen>
      <View className="mb-3 flex-row flex-wrap gap-2">
        {STATUSES.map((option) => (
          <Pressable
            key={option}
            className={`rounded-full px-3 py-1 ${status === option ? 'bg-sky-500' : 'bg-slate-800'}`}
            onPress={() => setStatus(option)}
          >
            <Text className={`text-xs ${status === option ? 'text-white' : 'text-slate-300'}`}>
              {option}
            </Text>
          </Pressable>
        ))}
      </View>

      <InfiniteGrid
        query={query}
        keyExtractor={(search) => String(search.id)}
        emptyTitle="No runs yet"
        emptyHint="Start one from the Search tab."
        renderItem={(search) => (
          <Link href={{ pathname: '/(app)/runs/[id]', params: { id: search.id } }} asChild>
            <Pressable className="mb-2 rounded-xl bg-slate-800 p-3 active:opacity-80">
              <Text className="text-base font-medium text-white">
                {search.make} {search.model ?? ''} · {search.from_year}–{search.to_year}
              </Text>
              <View className="mt-1 flex-row items-center gap-2">
                <StatusBadge status={search.status} />
                <Text className="text-xs text-slate-400">{search.images_count ?? 0} images</Text>
              </View>
            </Pressable>
          </Link>
        )}
      />
    </Screen>
  );
}
```

Create `mobile/app/(app)/runs/[id].tsx`:

```tsx
import { useLocalSearchParams } from 'expo-router';
import { ActivityIndicator, Text, View } from 'react-native';

import { useSearchImages } from '@/api/hooks/useImages';
import { useSearch } from '@/api/hooks/useSearches';
import { ImageCard } from '@/ui/ImageCard';
import { InfiniteGrid } from '@/ui/InfiniteGrid';
import { Screen } from '@/ui/Screen';
import { StatusBadge } from '@/ui/StatusBadge';

export default function RunDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const searchId = Number(id);
  const search = useSearch(searchId);
  const images = useSearchImages(searchId);

  if (search.isLoading) {
    return (
      <Screen>
        <ActivityIndicator className="mt-8" color="#38bdf8" />
      </Screen>
    );
  }

  return (
    <Screen>
      {search.data ? (
        <View className="mb-3">
          <Text className="text-lg font-bold text-white">
            {search.data.make} {search.data.model ?? ''} · {search.data.from_year}–
            {search.data.to_year}
          </Text>
          <View className="mt-1 flex-row items-center gap-2">
            <StatusBadge status={search.data.status} />
            <Text className="text-xs text-slate-400">{search.data.images_count ?? 0} images</Text>
          </View>
          {search.data.status === 'failed' ? (
            <Text className="mt-2 text-xs text-red-300">
              This run failed. The reason is on the Health tab&apos;s error log.
            </Text>
          ) : null}
        </View>
      ) : null}

      <InfiniteGrid
        query={images}
        numColumns={2}
        keyExtractor={(image) => String(image.id)}
        renderItem={(image) => <ImageCard image={image} />}
        emptyTitle="This run returned no images"
      />
    </Screen>
  );
}
```

- [ ] **Step 10: Verify the whole gate**

```bash
cd mobile && npm run typecheck && npm run lint && npm test && npm run build:web
```

Expected: all pass.

- [ ] **Step 11: Commit**

```bash
git add mobile
git commit -m "feat(mobile): add the search form, image grid and run screens

POST /searches answers 201, 200, 503 and 502 with a real run row in every
case, so the form routes to that run whatever happened rather than treating
anything but 201 as failure.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---
## Task 8: The review queue with optimistic updates

The spec is specific: "approve moves the card immediately and rolls back only if the PATCH fails." The rollback is the part worth testing — an optimistic update that cannot undo itself is worse than no optimistic update, because the UI then lies about what the server holds.

**Files:**
- Create: `mobile/src/api/hooks/useReviewImage.ts`
- Modify: `mobile/app/(app)/review/index.tsx`
- Test: `mobile/src/api/hooks/__tests__/useReviewImage.test.tsx`

**Interfaces:**
- Consumes: `apiRequest` (Task 4); `queryKeys`, `useImages` (Task 6); `ImageSchema`, `single` (Task 2).
- Produces: `useReviewImage()` — a mutation taking `{ id: number; review_status: ReviewStatus }` that patches every cached copy of the image before the request and restores the snapshot on failure.

- [ ] **Step 1: Write the failing test**

Create `mobile/src/api/hooks/__tests__/useReviewImage.test.tsx`:

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react-native';
import type { ReactNode } from 'react';

import * as client from '../../client';
import { queryKeys } from '../../queryKeys';
import type { Image } from '../../schemas';
import { useReviewImage } from '../useReviewImage';

const image = (overrides: Partial<Image> = {}): Image =>
  ({
    id: 1,
    car_search_id: 1,
    make: 'Toyota',
    model: 'RAV4',
    year: 1997,
    color: null,
    title: 'File:RAV4.jpg',
    description: null,
    source_url: 'https://upload.wikimedia.org/a.jpg',
    thumbnail_url: null,
    width: null,
    height: null,
    license: null,
    attribution: null,
    make_confirmed: true,
    year_confirmed: null,
    review_status: 'pending',
    reviewed_by: null,
    reviewed_at: null,
    download_status: 'not_downloaded',
    created_at: '2026-01-15T09:00:00+00:00',
    ...overrides,
  }) as Image;

const page = (items: Image[]) => ({
  data: items,
  links: { first: null, last: null, prev: null, next: null },
  meta: { path: '/api/v1/images', per_page: 24, next_cursor: null, prev_cursor: null },
});

function setup() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  queryClient.setQueryData(queryKeys.images({ review_status: 'pending' }), {
    pages: [page([image()])],
    pageParams: [null],
  });

  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );

  return { queryClient, wrapper };
}

const cachedStatus = (queryClient: QueryClient) => {
  const cached = queryClient.getQueryData<{ pages: ReturnType<typeof page>[] }>(
    queryKeys.images({ review_status: 'pending' }),
  );

  return cached?.pages[0]?.data[0]?.review_status;
};

describe('useReviewImage', () => {
  beforeEach(() => jest.restoreAllMocks());

  it('moves the card before the request resolves', async () => {
    const { queryClient, wrapper } = setup();
    let resolve: ((value: unknown) => void) | undefined;
    jest.spyOn(client, 'apiRequest').mockReturnValueOnce(
      new Promise((r) => {
        resolve = r;
      }),
    );

    const { result } = renderHook(() => useReviewImage(), { wrapper });

    act(() => {
      result.current.mutate({ id: 1, review_status: 'approved' });
    });

    // The cache is already updated while the PATCH is still in flight.
    await waitFor(() => expect(cachedStatus(queryClient)).toBe('approved'));

    act(() => {
      resolve?.({ data: image({ review_status: 'approved', reviewed_by: 7 }) });
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
  });

  it('rolls the card back when the PATCH fails', async () => {
    const { queryClient, wrapper } = setup();
    jest
      .spyOn(client, 'apiRequest')
      .mockRejectedValueOnce(new client.ApiError(500, 'Server error'));

    const { result } = renderHook(() => useReviewImage(), { wrapper });

    act(() => {
      result.current.mutate({ id: 1, review_status: 'approved' });
    });

    await waitFor(() => expect(result.current.isError).toBe(true));

    // The snapshot is restored - the UI must not keep claiming "approved".
    expect(cachedStatus(queryClient)).toBe('pending');
  });

  it('never sends the machine verdict fields', async () => {
    const { wrapper } = setup();
    const spy = jest
      .spyOn(client, 'apiRequest')
      .mockResolvedValueOnce({ data: image({ review_status: 'rejected' }) });

    const { result } = renderHook(() => useReviewImage(), { wrapper });

    await act(async () => {
      await result.current.mutateAsync({ id: 1, review_status: 'rejected' });
    });

    expect(spy).toHaveBeenCalledWith(
      '/images/1/review',
      expect.objectContaining({ method: 'PATCH', body: { review_status: 'rejected' } }),
    );
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd mobile && npm test -- useReviewImage
```

Expected: FAIL — "Cannot find module '../useReviewImage'".

- [ ] **Step 3: Write the mutation**

Create `mobile/src/api/hooks/useReviewImage.ts`:

```ts
import type { InfiniteData } from '@tanstack/react-query';
import { useMutation, useQueryClient } from '@tanstack/react-query';

import { apiRequest } from '../client';
import { queryKeys } from '../queryKeys';
import { ImageSchema, single } from '../schemas';
import type { CursorPage, Image, ReviewStatus } from '../schemas';

interface Variables {
  id: number;
  review_status: ReviewStatus;
}

const oneImage = single(ImageSchema);

export function useReviewImage() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, review_status }: Variables) =>
      apiRequest(`/images/${id}/review`, {
        method: 'PATCH',
        // Only the verdict. make_confirmed and year_confirmed belong to
        // MakeRelevanceChecker and the API would ignore them anyway.
        body: { review_status },
        schema: oneImage,
      }),

    onMutate: async ({ id, review_status }) => {
      // Stop an in-flight refetch landing on top of the optimistic write.
      await queryClient.cancelQueries({ queryKey: ['images'] });

      const snapshot = queryClient.getQueriesData({ queryKey: ['images'] });

      // Every cached list, whatever its filters, plus the detail cache.
      queryClient.setQueriesData<InfiniteData<CursorPage<Image>>>(
        { queryKey: ['images'] },
        (current) => {
          if (!current?.pages) return current;

          return {
            ...current,
            pages: current.pages.map((page) => ({
              ...page,
              data: page.data.map((image) =>
                image.id === id ? { ...image, review_status } : image,
              ),
            })),
          };
        },
      );

      queryClient.setQueryData<Image>(queryKeys.image(id), (current) =>
        current ? { ...current, review_status } : current,
      );

      return { snapshot };
    },

    onError: (_error, _variables, context) => {
      // Put every cache back exactly as it was. An optimistic update that
      // cannot undo itself leaves the UI asserting something the server
      // never accepted.
      for (const [key, data] of context?.snapshot ?? []) {
        queryClient.setQueryData(key, data);
      }
    },

    onSettled: (_data, _error, { id }) => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.image(id) });
      void queryClient.invalidateQueries({ queryKey: ['images'] });
    },
  });
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd mobile && npm test -- useReviewImage
```

Expected: PASS (3 tests).

- [ ] **Step 5: Write the review queue screen**

Replace `mobile/app/(app)/review/index.tsx`:

```tsx
import { Image } from 'expo-image';
import { Pressable, Text, View } from 'react-native';

import { useImages } from '@/api/hooks/useImages';
import { useReviewImage } from '@/api/hooks/useReviewImage';
import type { Image as CarImage } from '@/api/schemas';
import { ErrorBanner } from '@/ui/ErrorBanner';
import { InfiniteGrid } from '@/ui/InfiniteGrid';
import { Screen } from '@/ui/Screen';

export default function ReviewQueue() {
  // The queue is exactly the images no human has ruled on yet.
  const query = useImages({ review_status: 'pending' });
  const review = useReviewImage();

  return (
    <Screen>
      <Text className="mb-1 text-xl font-bold text-white">Review queue</Text>
      <Text className="mb-3 text-sm text-slate-400">
        Your verdict is recorded separately from the machine&apos;s, so both stay comparable.
      </Text>

      {review.isError ? (
        <ErrorBanner
          message={
            review.error instanceof Error
              ? `${review.error.message} The card has been put back.`
              : 'The verdict could not be saved. The card has been put back.'
          }
        />
      ) : null}

      <InfiniteGrid
        query={query}
        keyExtractor={(image) => String(image.id)}
        emptyTitle="Nothing left to review"
        emptyHint="Every image has a verdict."
        renderItem={(image: CarImage) => (
          <View className="mb-3 overflow-hidden rounded-xl bg-slate-800">
            <Image
              source={image.thumbnail_url ?? image.source_url}
              style={{ width: '100%', aspectRatio: 4 / 3 }}
              contentFit="cover"
              transition={150}
              cachePolicy="disk"
            />
            <View className="gap-2 p-3">
              <Text className="text-base font-medium text-white" numberOfLines={1}>
                {image.make} {image.model ?? ''} {image.year}
              </Text>
              <Text className="text-xs text-slate-400" numberOfLines={1}>
                {image.title}
              </Text>
              <View className="mt-1 flex-row gap-2">
                <Pressable
                  className="flex-1 items-center rounded-lg bg-emerald-500 py-2 active:opacity-80"
                  onPress={() => review.mutate({ id: image.id, review_status: 'approved' })}
                >
                  <Text className="text-sm font-semibold text-white">Approve</Text>
                </Pressable>
                <Pressable
                  className="flex-1 items-center rounded-lg bg-red-500 py-2 active:opacity-80"
                  onPress={() => review.mutate({ id: image.id, review_status: 'rejected' })}
                >
                  <Text className="text-sm font-semibold text-white">Reject</Text>
                </Pressable>
              </View>
            </View>
          </View>
        )}
      />
    </Screen>
  );
}
```

- [ ] **Step 6: Verify the gate**

```bash
cd mobile && npm run typecheck && npm run lint && npm test
```

Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add mobile
git commit -m "feat(mobile): add the review queue with optimistic approve and reject

The card moves before the PATCH resolves and every cached copy is restored
from a snapshot if it fails, so the UI never claims a verdict the server
refused.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: The pipeline health screen

**Files:**
- Modify: `mobile/app/(app)/health/index.tsx`
- Create: `mobile/src/ui/StatTile.tsx`
- Test: `mobile/app/(app)/health/__tests__/health.test.tsx`

**Interfaces:**
- Consumes: `useHealth`, `useErrors` (Task 6); `useAuth` (Task 5).
- Produces: nothing later tasks depend on.

- [ ] **Step 1: Write the failing test**

Create `mobile/app/(app)/health/__tests__/health.test.tsx`:

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react-native';
import type { ReactNode } from 'react';

import errorsFixture from '@/api/__fixtures__/errors.json';
import healthFixture from '@/api/__fixtures__/health.json';
import * as client from '@/api/client';
import Health from '../index';

jest.mock('@/auth/AuthContext', () => ({
  useAuth: () => ({ status: 'authenticated', user: null, signIn: jest.fn(), signOut: jest.fn() }),
}));

const wrapper = ({ children }: { children: ReactNode }) => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
};

describe('<Health />', () => {
  it('renders the counters and the error log', async () => {
    jest.spyOn(client, 'apiRequest').mockImplementation((path) =>
      Promise.resolve(path === '/health/summary' ? healthFixture : errorsFixture),
    );

    render(<Health />, { wrapper });

    await waitFor(() => expect(screen.getByText('completed')).toBeTruthy());
    expect(screen.getByText(/search run/i)).toBeTruthy();
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd mobile && npm test -- health
```

Expected: FAIL — the placeholder screen renders only the word "Health".

- [ ] **Step 3: Write the stat tile**

Create `mobile/src/ui/StatTile.tsx`:

```tsx
import { Text, View } from 'react-native';

export function StatTile({ label, value }: { label: string; value: number | string }) {
  return (
    <View className="min-w-[88px] flex-1 rounded-xl bg-slate-800 p-3">
      <Text className="text-2xl font-bold text-white">{value}</Text>
      <Text className="text-xs text-slate-400">{label}</Text>
    </View>
  );
}
```

- [ ] **Step 4: Write the health screen**

Replace `mobile/app/(app)/health/index.tsx`:

```tsx
import { ActivityIndicator, Pressable, ScrollView, Text, View } from 'react-native';

import { useErrors } from '@/api/hooks/useErrors';
import { useHealth } from '@/api/hooks/useHealth';
import { useAuth } from '@/auth/AuthContext';
import { ErrorBanner } from '@/ui/ErrorBanner';
import { Screen } from '@/ui/Screen';
import { StatTile } from '@/ui/StatTile';

/** Mirrors ErrorEvent::contexts() - keys to the labels Filament shows. */
const CONTEXT_LABELS: Record<string, string> = {
  csv_upload: 'CSV upload',
  csv_row: 'CSV row',
  search_run: 'Search run',
  image_download: 'Image download',
  wikimedia_block: 'Wikimedia block',
};

export default function Health() {
  const health = useHealth();
  const errors = useErrors();
  const { signOut } = useAuth();

  const events = errors.data?.pages.flatMap((page) => page.data) ?? [];

  return (
    <Screen>
      <ScrollView>
        <Text className="mb-3 text-xl font-bold text-white">Pipeline health</Text>

        {health.isError ? (
          <ErrorBanner
            message={health.error instanceof Error ? health.error.message : 'Health unavailable.'}
          />
        ) : null}

        {health.isLoading ? <ActivityIndicator color="#38bdf8" /> : null}

        {health.data ? (
          <>
            <Text className="mb-2 text-xs uppercase tracking-wide text-slate-500">Runs</Text>
            <View className="mb-4 flex-row flex-wrap gap-2">
              {Object.entries(health.data.searches_by_status).map(([status, count]) => (
                <StatTile key={status} label={status} value={count} />
              ))}
            </View>

            <Text className="mb-2 text-xs uppercase tracking-wide text-slate-500">Recent</Text>
            <View className="mb-4 flex-row flex-wrap gap-2">
              <StatTile label="errors, 24h" value={health.data.errors_last_24h} />
              <StatTile label="images, 7d" value={health.data.images_last_7d} />
            </View>

            <Text className="mb-2 text-xs uppercase tracking-wide text-slate-500">
              Errors by context, 7d
            </Text>
            <View className="mb-4 flex-row flex-wrap gap-2">
              {Object.entries(health.data.errors_by_context_last_7d).map(([context, count]) => (
                <StatTile key={context} label={CONTEXT_LABELS[context] ?? context} value={count} />
              ))}
            </View>
          </>
        ) : null}

        <Text className="mb-2 text-xs uppercase tracking-wide text-slate-500">Error log</Text>

        {events.length === 0 ? (
          <Text className="mb-4 text-sm text-slate-400">Nothing logged.</Text>
        ) : (
          events.map((event) => (
            <View key={event.id} className="mb-2 rounded-xl bg-slate-800 p-3">
              <View className="flex-row justify-between">
                <Text className="text-xs font-medium text-sky-300">
                  {CONTEXT_LABELS[event.context] ?? event.context}
                </Text>
                <Text className="text-xs text-slate-500">{event.occurred_at ?? ''}</Text>
              </View>
              <Text className="mt-1 text-sm text-slate-200">{event.message ?? '(no message)'}</Text>
              {event.exception_message ? (
                <Text className="mt-1 text-xs text-slate-400" numberOfLines={2}>
                  {event.exception_class}: {event.exception_message}
                </Text>
              ) : null}
            </View>
          ))
        )}

        {errors.hasNextPage ? (
          <Pressable
            className="mt-2 items-center rounded-lg bg-slate-800 py-3 active:opacity-80"
            onPress={() => errors.fetchNextPage()}
          >
            <Text className="text-sm text-sky-400">
              {errors.isFetchingNextPage ? 'Loading…' : 'Load more'}
            </Text>
          </Pressable>
        ) : null}

        <Pressable className="my-8 items-center py-3" onPress={() => void signOut()}>
          <Text className="text-sm text-red-400">Sign out</Text>
        </Pressable>
      </ScrollView>
    </Screen>
  );
}
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
cd mobile && npm test -- health && npm run typecheck && npm run lint
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add mobile
git commit -m "feat(mobile): add the pipeline health screen and error log

Every counter the API always returns is rendered, including the zeros, so
the screen never has to special-case a missing key.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 10: Netlify web deploy

The last task, and the one with prerequisites outside the repository. Do the prerequisites first — the workflow cannot be tested without them.

**Files:**
- Modify: `.github/workflows/mobile.yml` (add the deploy job)
- Create: `mobile/netlify.toml`
- Modify: `.env.example` (document the production CORS origin)
- Modify: `README.md` (document the mobile app and its URL)

**Interfaces:**
- Consumes: the `build:web` script and the `check` job from Task 1.
- Produces: a public URL, and the value production's `CORS_ALLOWED_ORIGINS` must be set to.

- [ ] **Step 1: Create the Netlify site and collect the secrets**

This is a manual step — it needs a browser and an account.

1. Create a new Netlify site (no repository link; this deploys from CI).
2. Note its site ID and its default URL, e.g. `https://cars-images.netlify.app`.
3. Create a personal access token.
4. Add to the GitHub repository: secrets `NETLIFY_AUTH_TOKEN` and `NETLIFY_SITE_ID`, and variable `EXPO_PUBLIC_API_URL` set to the production API origin (for example `https://cars-images.example.com`).

Verify the secrets are visible to Actions:

```bash
gh secret list
gh variable list
```

Expected: `NETLIFY_AUTH_TOKEN`, `NETLIFY_SITE_ID`, and `EXPO_PUBLIC_API_URL`.

- [ ] **Step 2: Add the Netlify config**

Create `mobile/netlify.toml`. With `web.output: "static"` every route is real HTML, so there is deliberately **no** SPA catch-all redirect — adding one would mask genuine 404s.

```toml
# The build runs in the GitHub workflow, not on Netlify; this file only
# describes the directory to publish and the headers to serve it with.
[build]
  publish = "dist"

[[headers]]
  for = "/*"
  [headers.values]
    X-Content-Type-Options = "nosniff"
    Referrer-Policy = "strict-origin-when-cross-origin"

[[headers]]
  for = "/_expo/static/*"
  [headers.values]
    Cache-Control = "public, max-age=31536000, immutable"
```

- [ ] **Step 3: Add the deploy job**

Append to `.github/workflows/mobile.yml`:

```yaml
  deploy:
    name: Deploy web build to Netlify
    runs-on: ubuntu-latest
    needs: check
    # Only from main, and never from a fork's pull request - the secrets are
    # not available there and the job would fail confusingly.
    if: github.ref == 'refs/heads/main' && github.event_name == 'push'

    steps:
      - uses: actions/checkout@v4

      - uses: actions/setup-node@v4
        with:
          node-version: 22
          cache: npm
          cache-dependency-path: mobile/package-lock.json

      - name: Install
        run: npm ci

      # EXPO_PUBLIC_API_URL is inlined into the bundle here. There is no
      # runtime config file, so this value is baked into what ships.
      - name: Export web build
        run: npm run build:web
        env:
          EXPO_PUBLIC_API_URL: ${{ vars.EXPO_PUBLIC_API_URL }}

      - name: Deploy
        run: npx --yes netlify-cli@17 deploy --dir=dist --prod --message "$GITHUB_SHA"
        env:
          NETLIFY_AUTH_TOKEN: ${{ secrets.NETLIFY_AUTH_TOKEN }}
          NETLIFY_SITE_ID: ${{ secrets.NETLIFY_SITE_ID }}
```

- [ ] **Step 4: Verify the deploy locally before trusting CI**

```bash
cd mobile
EXPO_PUBLIC_API_URL=https://cars-images.example.com npm run build:web
npx --yes netlify-cli@17 deploy --dir=dist --message "local smoke test"
```

Expected: a draft deploy URL. Open it and confirm the landing screen renders and `/login` is a real page (not a client-side redirect). Confirm the API URL was inlined:

```bash
grep -rl "cars-images.example.com" dist/_expo/static/js | head -1
```

Expected: at least one bundle matches. If nothing matches, the variable was not read at build time — check the `EXPO_PUBLIC_` prefix.

- [ ] **Step 5: Point production CORS at the Netlify origin**

The API rejects browser origins it does not know. Update `.env.example` so the requirement is documented:

```bash
sed -i 's|^CORS_ALLOWED_ORIGINS=.*|CORS_ALLOWED_ORIGINS=http://localhost:8081|' .env.example
```

Then append the production note directly beneath that line in `.env.example`:

```
# Production must list the deployed web build's origin instead, e.g.
# CORS_ALLOWED_ORIGINS=https://cars-images.netlify.app
```

Set the real value in production's `.env` (a manual step on the server — this repository does not deploy `.env`). Verify from the Netlify URL after the first deploy: sign in on the web build. A CORS failure shows in the browser console as a blocked preflight, not as a 401.

- [ ] **Step 6: Document it in the README**

Add a section to `README.md` under the existing feature documentation:

````markdown
## Mobile client

An Expo app lives under [`mobile/`](mobile/) and talks to `/api/v1`. It ships
two ways from one codebase:

- **Web:** built by [`.github/workflows/mobile.yml`](.github/workflows/mobile.yml)
  and published to Netlify on every push to `main` that touches `mobile/**`.
- **Android APK:** planned, tag-triggered (see the design doc).

Local development:

```bash
cd mobile
npm install
cp .env.example .env      # point EXPO_PUBLIC_API_URL at your Laravel dev server
npm run web
```

The web build reads its API origin from `EXPO_PUBLIC_API_URL`, inlined at build
time. Production must list the deployed web origin in `CORS_ALLOWED_ORIGINS` or
the browser blocks every request.
````

- [ ] **Step 7: Verify the two CI guards still hold**

This is acceptance criteria 1 and 2, and it is the whole reason `ci-cd.yml` was hardened before any application code was written.

```bash
git add -A && git commit -m "chore(mobile): touch only the mobile app" --allow-empty
git push
gh run list --limit 5
```

Expected: `Mobile CI` ran; `CI / Deploy` did **not** start. Then confirm the reverse with a Laravel-only change, and check that the deploy filter classifies a docs+mobile commit as non-deployable.

- [ ] **Step 8: Commit**

```bash
git add .github/workflows/mobile.yml mobile/netlify.toml .env.example README.md
git commit -m "feat(mobile): publish the web build to Netlify

Static output means every route is real HTML with a shareable URL, so no SPA
catch-all redirect is configured - a 404 should stay a 404. The API origin is
inlined at build time; production must allow the Netlify origin in
CORS_ALLOWED_ORIGINS or the browser blocks every request.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Done

Do not merge from here. Invoke `superpowers:finishing-a-development-branch` to choose between merging to `main` and opening a pull request.

**For the PR body:** this branch adds a new top-level `mobile/` directory and a second workflow. Deploying it changes nothing on the Laravel server, but production needs `CORS_ALLOWED_ORIGINS` set to the Netlify origin before the web build can sign in. Three new GitHub secrets/variables are required: `NETLIFY_AUTH_TOKEN`, `NETLIFY_SITE_ID`, `EXPO_PUBLIC_API_URL`.

**Acceptance criteria covered by this plan:** 1, 2 (Tasks 1 and 10), 6 (Task 10). Criteria 3, 4, 5 and 8 were met by Plan A and are re-verified by `ContractFixturesTest` in Task 2. Criterion 7 (the APK) is Plan C.

## Deferred to Plan C

- EAS Build, the `preview` profile, the `mobile-v*` tag trigger, and the GitHub Release attachment.
- The `EXPO_TOKEN` secret.
