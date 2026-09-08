# Deploying the mobile web build to Netlify

Written for someone who has never used Netlify. It explains what Netlify is
doing in this project, what it is deliberately *not* doing, the one-time
setup, and what to check when something looks wrong.

## What Netlify is for here

This repository ships two things from one codebase:

- **The Laravel app**, deployed to **SiteGround** over SSH by
  [`ci-cd.yml`](../.github/workflows/ci-cd.yml). This is the API and the
  Filament admin panel. Netlify has nothing to do with it.
- **The Expo app under [`mobile/`](../mobile/)**, whose *web* build is a folder
  of plain HTML, CSS and JavaScript. Netlify hosts that folder.

Netlify is a **static file host with a CDN**. That is the whole job. It serves
the files, adds HTTPS, and applies the headers and redirect rules we give it.
It runs no PHP, touches no database, and knows nothing about the API beyond the
URL baked into the JavaScript at build time.

## The important decision: we do NOT link the Git repository

When you add a project, Netlify offers **"Import a Git repository"**. **Do not
use it for this project.**

There are two ways to deploy to Netlify, and this repo deliberately uses the
second:

| | Git-linked (the default Netlify offers) | CI-driven (what we use) |
|---|---|---|
| Who builds the site | Netlify's own build servers | GitHub Actions, in `mobile.yml` |
| What Netlify receives | Your repo, then it runs `npm run build` | A finished `dist/` folder |
| Where build config lives | Netlify's dashboard | `mobile.yml`, in version control |
| Behaviour on a Laravel-only commit | Netlify rebuilds anyway | Nothing happens |

The CI-driven path was chosen because this is a **monorepo**. The mobile app
lives in a subdirectory alongside a Laravel application, and the same commits
that touch `app/` must not trigger a mobile rebuild. `mobile.yml` already
filters on `paths: ['mobile/**']`, so the decision about *when* to build lives
in one place, in Git, reviewable — rather than half in a YAML file and half in
a dashboard setting nobody remembers changing.

So on Netlify's "Add your project" screen: **ignore the Git import buttons and
the AI agent box.** Create an empty site instead (see below).

## One-time setup

### 1. Create an empty Netlify site

In the Netlify dashboard, create a site **without** connecting a repository.
Netlify's wording for this moves around; look for "deploy manually", "drag and
drop", or create it by dragging any placeholder folder onto the drop zone. The
first real deploy from CI will replace whatever you drop.

Once it exists, note two things from **Site configuration → General**:

- The **site ID** (sometimes shown as "API ID") — a UUID-looking string.
- The site's **URL**, e.g. `https://something-something-123456.netlify.app`.
  You can rename this under domain settings; do it now if you want a tidier
  URL, because the API's CORS configuration has to match it exactly.

### 2. Create a Netlify access token

**User settings → Applications → Personal access tokens → New access token.**
Copy it immediately; Netlify shows it once.

### 3. Add three values to GitHub

In the GitHub repository: **Settings → Secrets and variables → Actions.**

| Name | Tab | Value |
|---|---|---|
| `NETLIFY_AUTH_TOKEN` | **Secrets** | the personal access token |
| `NETLIFY_SITE_ID` | **Secrets** | the site ID from step 1 |
| `EXPO_PUBLIC_API_URL` | **Variables** | the production API origin, e.g. `https://api.example.com` |
| `MOBILE_DEPLOY_ENABLED` | **Variables** | `true` |

Two things people get wrong here:

- **`EXPO_PUBLIC_API_URL` goes in the Variables tab, not Secrets.** The
  workflow reads `vars.EXPO_PUBLIC_API_URL`. Put it in Secrets and it resolves
  to an empty string. The workflow now fails loudly if that happens, rather
  than publishing a site whose every request throws — but you would still have
  wasted a deploy.
- **It is not a secret anyway.** Anything prefixed `EXPO_PUBLIC_` is compiled
  into the JavaScript bundle and readable by anyone who opens the site. That is
  by design: the browser needs to know where the API is.

`MOBILE_DEPLOY_ENABLED` is an off switch. Until it is `true`, the deploy job
skips cleanly. That lets `main` carry the mobile code before Netlify is ready.

### 4. Allow the Netlify origin through CORS

The web build runs on `https://<your-site>.netlify.app` and calls the API on a
different origin. Browsers block that by default. On the **production server**,
set in the real `.env`:

```
CORS_ALLOWED_ORIGINS=https://<your-site>.netlify.app
```

Then clear the config cache (`php artisan config:cache`). This repo only ships
[`.env.example`](../.env.example); production's real `.env` is a manual step.

**A CORS failure does not look like an auth failure.** Sign-in appears to do
nothing, and the browser console shows a blocked preflight (an `OPTIONS`
request) — not a 401. If login silently fails after a deploy, check this first.

## How a deploy actually happens

1. You push to `main` with something under `mobile/` changed.
2. `mobile.yml` starts. `ci-cd.yml` does **not** — it has
   `paths-ignore: ['mobile/**']`, so no runner even starts for a mobile-only
   push.
3. The `check` job installs with `npm ci`, then runs typecheck, lint, tests,
   and a web export.
4. The `deploy` job runs only on `main`, with `MOBILE_DEPLOY_ENABLED == 'true'`.
   It rebuilds with the real `EXPO_PUBLIC_API_URL` and uploads `dist/` to
   Netlify.

**To deploy without pushing** — the first deploy after enabling the switch, or
a re-publish after changing `EXPO_PUBLIC_API_URL` — use **Actions → Mobile CI →
Run workflow** on `main`. This matters more than it looks: the API origin is
baked into the bundle at build time, so changing that variable rebuilds nothing
by itself, and no ordinary commit touches `mobile/**` just because the API
moved. Without the manual trigger, the only way to re-publish would be an empty
commit.

The reverse holds too: a Laravel-only push runs `ci-cd.yml` and not
`mobile.yml`. A push touching both runs both, which is correct.

## Verifying a deploy

1. **Open the site.** If the page is blank, see the CSP note below.
2. **Check a deep link** — visit `/runs/1` directly rather than clicking to it.
   This exercises the rewrite rules in
   [`mobile/netlify.toml`](../mobile/netlify.toml). Expo's static export writes
   files with literal bracket names like `runs/[id].html`, and those rewrites
   are what map a real URL onto them.
3. **Sign in.** This is the CORS test.
4. **Confirm the API origin.** In devtools' Network tab, requests should go to
   your production API, not `localhost:8000`. If they go to localhost, the
   build did not receive `EXPO_PUBLIC_API_URL`.

## Troubleshooting

**The page is completely blank, console full of Content-Security-Policy
errors.** `netlify.toml` sets a strict CSP. It pins the hash of Expo's inline
hydration script, so an Expo SDK upgrade that changes that one-line script by
even a byte will break hydration. Symptom: the page loads but stays blank.
Fix: recompute the hash from the emitted HTML and update `netlify.toml`. Also
note that enabling Netlify Analytics injects an inline script the CSP will
block.

**`/runs/42` is a 404 but clicking through to it works.** The rewrites in
`netlify.toml` are not matching. Check the `to =` paths still match the real
filenames in `dist/` after an Expo upgrade.

**Sign-in does nothing, no error.** CORS. See step 4 above.

**Everything 404s / the site is empty.** The deploy uploaded the wrong folder.
The publish directory is `mobile/dist`.

**The deploy job was skipped.** `MOBILE_DEPLOY_ENABLED` is not `true`.

**Local builds ignore a changed `EXPO_PUBLIC_API_URL`.** Metro caches
aggressively and its cache does not key on that variable, so you can get a
bundle with a *previous* run's URL baked in. Export with `--clear`, or
`rm -rf /tmp/metro-cache`. CI is unaffected — every run starts clean. This is
worth knowing because it can make a local verification pass for the wrong
reason.

## Cost

Netlify's free tier comfortably covers a static site of this size — the bundle
is a few hundred KB and there is no server-side execution. The limits that
exist are on bandwidth and build minutes, and this project uses almost no build
minutes on Netlify at all, because **GitHub Actions does the building**.
Netlify only receives finished files.

Check current terms before relying on this; hosting pricing changes.

## Why Netlify rather than the alternatives

**GitHub Pages** serves project sites from a sub-path
(`username.github.io/cars-images-api/`). expo-router generates absolute paths,
so a sub-path breaks routing unless you attach a custom domain. Netlify gives a
root domain on the free tier, so `/runs/42` is a real path.

**Vercel** would work equally well and is not a worse choice. Switching would
mean translating `netlify.toml` into `vercel.json` (rewrites and headers have
direct equivalents) and swapping the deploy step in `mobile.yml`. Perhaps an
hour's work, no architectural difference. Vercel's free tier has historically
carried restrictions around commercial use — read the current terms if this is
career-facing.

The decision that actually mattered was *against* a sub-path host, not between
these two.

## Related files

| File | What it does |
|---|---|
| [`.github/workflows/mobile.yml`](../.github/workflows/mobile.yml) | Builds, tests, and deploys the web build |
| [`.github/workflows/ci-cd.yml`](../.github/workflows/ci-cd.yml) | Deploys the Laravel app to SiteGround. Unrelated to Netlify |
| [`mobile/netlify.toml`](../mobile/netlify.toml) | Publish directory, security headers, dynamic-route rewrites |
| [`mobile/.env.example`](../mobile/.env.example) | Local `EXPO_PUBLIC_API_URL`, and the Metro cache note |
| [`.env.example`](../.env.example) | Laravel's `CORS_ALLOWED_ORIGINS`, with the production note |
