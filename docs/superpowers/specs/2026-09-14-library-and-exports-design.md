# Library and Exports — Design

**Date:** 2026-09-14
**Status:** Approved (pending implementation plan)
**Project:** cars-images-api (Laravel 13 + Filament 5 + Expo 57)
**Sub-project:** P4 of the programme — see the
[P1 spec's programme table](2026-09-09-mobile-design-system-and-navigation-design.md#programme)

## Goal

Bring the mobile Library to parity with the panel's `Results` page, and let the
native app export what the Library is showing — as a CSV or a ZIP — through
short-lived signed links opened in the system browser.

## The workflow this serves

Settled during brainstorming, and it shapes every decision below:

> **CSV uploads happen on desktop, in the Filament panel. Everything after —
> running the queue, reviewing, exporting — happens on the native app.**

So export never needs to happen on the web build. That dissolves the question
of whether the export ability belongs there, and it points at the flow worth
optimising: **review on the phone, filter one import to `approved`, export.**

## What exists

**No signed routes exist anywhere in this app.** P4 introduces the mechanism,
so there is no local pattern to follow and it is worth designing carefully
rather than bolting on.

**The exporters are already reusable.** Both take a collection:

- `BatchCsvExporter::streamTo($handle, Collection $images)`
- `BatchZipBuilder::buildToFile(Collection $images, string $targetPath): int`

So the download route only has to produce the right collection.

**`routes/web.php` says these need no routes:**

> Bulk ZIP / CSV downloads are served directly by the Results Filament page
> (App\Filament\Pages\Results) via Livewire download responses, so they need no
> dedicated routes or controller.

P4 is the change that makes that false. The comment is updated rather than left
contradicting the file beneath it.

**The Library filters only by `make` and `review_status`.** `Results` also
filters by CSV import, make match and year match. `GET /images` already supports
the two verdict filters but not `csv_import_id`.

## Decisions

| Decision | Choice | Why |
|---|---|---|
| `exports:read` on web | **No — native only, as P2 decided** | The workflow never exports from web. Native tokens live in SecureStore, not `localStorage`, so the theft concern that drove the split does not apply to the build that exports. |
| ZIP links | **Single-use** | See [Why ZIP links are single-use](#why-zip-links-are-single-use). |
| CSV links | **Ordinary time-limited signed URLs** | Cheap to generate, and adds nothing `search:read` does not already expose. |
| Selection | **The active filter**, not per-image selection | Settled at programme level. One filtered export also replaces the panel's separate "Download Confirmed as ZIP" action: that is `make_confirmed=1`. |
| Delivery | **`Linking.openURL`, system browser** | Settled at programme level. The browser owns the long request, not the app. |
| Mint route | **`api.php`** | An API call from the app, with its bearer token. |
| Download route | **`web.php`** | A browser navigation. An expired or used link should render a readable page in the tab, not raw JSON. |
| UI gating | **`useCan(ability)`**, not a platform check | See [Gating by capability](#gating-by-capability-not-platform). |

### Re-examining the export risk honestly

P2 described `exports:read` as able to *"exfiltrate the library's metadata in
one request."* Looking at it the same way P3b re-examined `search:run`, that
framing is half right.

**The CSV adds no capability a token lacks.** `GET /images` is on `search:read`
and returns every field, cursor-paginated. "One request" is really "one request
instead of N". Convenience, not capability.

**The ZIP carries a risk P2 did not name.** It is not about data: every ZIP is
up to `bulk_download_max_images` (100) full-resolution Wikimedia fetches plus a
GD resize, synchronously, on shared hosting. The danger is server load and a
direct path to a Wikimedia block — not exfiltration.

Keeping `exports:read` native-only is still right, but for the workflow reason
above. The ZIP's real risk is handled by making its links single-use.

### Why ZIP links are single-use

**Once minted, a signed URL is bearer-free.** The ability gate guards only
*minting*; the link itself works for anyone holding it until it expires.
`Linking.openURL` also puts it in the phone browser's history.

A replayed CSV link costs a database query. A replayed ZIP link costs up to a
hundred Wikimedia fetches. So the ZIP link carries a nonce, stored in the cache
at mint time and consumed on first download.

**The consume is not atomic, and that is acceptable.** The cache store is
`file` (`.env`, `.env.example`), where `Cache::pull()` is a get followed by a
forget. Two requests arriving in the same instant could both pass. The threat
being addressed is a leaked link replayed *later* — after the nonce is gone —
not the legitimate user racing themselves. An atomic claim via `Cache::lock()`
would close the window but guards against a scenario that does not occur in
this flow.

**One failure mode, accepted:** anything that *prefetches* the URL consumes it,
so the real tap would then receive a 410. The mobile flow never prefetches —
`Linking.openURL` is a direct navigation. It only happens if the link is pasted
into something that generates previews, such as a messaging app. Weakening
single-use to protect a flow nobody uses would reopen the replay risk.

### Gating by capability, not platform

P4 is the first feature **deliberately absent from one build.** The export
action must not render on web, where it could only 403.

`Platform.OS === 'web'` would work today and encode the wrong concept. The rule
is *"this token cannot export"*, not *"this is a browser"*, and a platform check
rots silently the moment the ability split changes.

`useCan(ability)` reads `REQUESTED_ABILITIES` for the current build. That is
exact today: the server intersects the request with `TokenAbilities::all()`,
and every requested ability is valid, so what is requested is what is granted.

**The more rigorous version** would persist the `abilities` array from the
login response and read that. P2 noted the client currently parses it and
discards it. That is deferred: it needs a `TokenStore` change and a restore
path, for no difference in behaviour while requested equals granted.

### A defect this fixes

**The web build's Pipeline tab shows an upload screen that can only 403.** P3a
kept `imports:write` off the web build — correctly, since uploads happen in the
panel — but left the entry point visible. It is reached from exactly one place,
the empty-state action in `mobile/app/(app)/pipeline/index.tsx`, plus the route
itself by URL.

P4 gates both on `useCan('imports:write')`: the action does not render, and the
screen explains that uploads happen in the admin panel if reached directly.

## API

### `GET /api/v1/images` — gains `csv_import_id`

`ListImagesRequest` gains `csv_import_id`, applied as
`whereHas('search', fn ($q) => $q->where('csv_import_id', $id))`. It is the
filter the "review one import, export its approved images" flow needs. Absent,
behaviour is unchanged.

### `GET /api/v1/images/count` — new

Returns `{ "count": 63 }` for the same filters as `GET /images`, through the
same `ListImagesRequest::apply()`. Ability `search:read`.

**Added during planning, correcting this spec.** The first draft said the export
section would take its count "from the grid's own query". It cannot: `GET
/images` is cursor-paginated, and Laravel's cursor paginator computes no total —
`meta` carries only `path`, `per_page`, `next_cursor` and `prev_cursor`. That
is deliberate, not an oversight: a total would cost a `COUNT` on every page, and
the original mobile spec chose cursors precisely because they neither skip nor
repeat rows when a harvest inserts mid-scroll. Switching to offset pagination to
get a total would reintroduce both.

`search:read`, not `exports:read`, because "how many images match this filter"
is not an export question. The Library can show the count in its header on
every build; the export section reads the same number on native.

**The count is advisory.** It drives display — the button label and the
disabled-over-cap state — never the decision. `POST /exports` and the download
route each recount, because the set can change between any two of the three.

### `POST /api/v1/exports`

`auth:sanctum`, ability `exports:read`, `CarImagePolicy::export`, throttled.

**The policy check is class-level, and that is correct here.** The P3b chunk
endpoint was flagged by an automated review because it authorized a class while
the client named a specific object, leaving the policy nothing to examine. An
export names no single object: it acts on a *filtered set*, so there is no
instance to pass. Authorizing `CarImage::class` is the honest shape. If
per-image visibility ever arrives, the place to enforce it is the filtered
query itself — scoping what the set can contain — not a policy argument.

**Request:** `format` (`csv` | `zip`) plus any of the `GET /images` filters.
The filters are validated by the same rules, so an export can never be scoped
to something the grid could not show.

**Behaviour:**

1. Count the filtered set.
2. **Zero** → 422: there is nothing to export, and a link to an empty file is a
   worse answer than saying so.
3. **ZIP over `bulk_download_max_images`** → 422 on `format`, naming the count
   and the limit: *"140 images match; a ZIP is limited to 100. Narrow the
   filter."* The CSV has no cap — it does not touch Wikimedia.
4. Mint `URL::temporarySignedRoute('exports.download', now()->addMinutes(5), …)`
   carrying `format` and the filters. For a ZIP, also a random `nonce`, written
   to the cache for the same five minutes.

**Response 201:**

```json
{ "url": "https://…/exports/download?…&signature=…",
  "expires_at": "2026-09-14T16:21:00+00:00",
  "count": 63,
  "format": "zip" }
```

`count` lets the app confirm what it is about to download before opening a
browser the user cannot easily back out of.

### `GET /exports/download`

In `web.php`. **No authentication** — the signature is the credential. `signed`
middleware rejects a tampered or expired URL with 403.

1. **ZIP:** `Cache::pull("export-nonce:{$nonce}")`. Absent → **410 Gone**, with a
   page saying the link has already been used and to export again from the app.
2. Re-apply the filters to build the collection.
3. **Re-check the cap.** Images can arrive in the five minutes between minting
   and downloading, and a ZIP that quietly grew to 140 would exceed
   `max_execution_time`. Over the cap → 422 with the same "narrow the filter"
   message.
4. Stream: the CSV through `BatchCsvExporter::streamTo`; the ZIP through
   `BatchZipBuilder::buildToFile` to a temp file, then
   `response()->download(…)->deleteFileAfterSend()`, exactly as
   `Results::zipDownload()` does today.

**The ZIP still marks `download_status = downloaded`**, as the panel's does.
That is a side effect on a GET, which is ordinarily wrong; single-use is what
makes it tolerable, since it can fire once per mint rather than once per visit.

**A ZIP that fetched nothing** — every Wikimedia request failed — returns an
error page rather than an empty archive, matching the panel's "No images could
be downloaded" notification.

## Client

### Library filters

The Library gains chips for **make match**, **year match** and **CSV import**,
beside the existing make field and review-status chips. The two verdict chips
read `matched` / `not matched`, the same wording `VerdictBadge` uses, so a
filter and the badge it filters by say the same thing.

**Never written from the client:** `make_confirmed` and `year_confirmed` are
filters here, read-only, exactly as everywhere else.

### Export

Rendered only when `useCan('exports:read')`. A section above the grid:

```
63 images match
[ Export CSV ]  [ Export ZIP ]
ZIP is limited to 100 images per download.
```

**The count comes from `GET /images/count`**, fetched for the active filters,
so the button states what it will export before it is pressed. (Not from the
grid's query — that is cursor-paginated and carries no total; see the count
endpoint above.) When a ZIP is over the cap the button is disabled with the
reason beside it — not left pressable so the server can say no.

Pressing either calls `POST /exports`, then `Linking.openURL(url)`. For the ZIP,
a line first says the download may take a minute or two and leaves the app for
the browser — the same honesty P3b applied to leaving the app mid-run.

## Testing

**Laravel**

- `GET /images?csv_import_id=` returns only that import's images.
- `GET /images/count` agrees with `GET /images` for the same filters, including
  `csv_import_id`, and 403s without `search:read`.
- `POST /exports`: 403 without `exports:read`; 422 for an empty set; 422 for a
  ZIP over the cap and none for a CSV of the same size; the response URL carries
  a valid signature; a ZIP mint writes its nonce.
- `GET /exports/download`: 403 for a tampered or expired signature; a CSV
  streams with the right rows; **a ZIP link works once and returns 410 the
  second time**; a set that grew past the cap between mint and download is
  refused; filters travel through the signature and cannot be widened by
  editing the URL.
- **No test reaches Wikimedia.** `Http::fake()` wherever a ZIP is built.

**Mobile**

- `useCan` returns true for a requested ability and false otherwise.
- The export section does not render without `exports:read`.
- The ZIP button is disabled over the cap, with its reason.
- The Pipeline upload action does not render without `imports:write`.

The Library and Pipeline screens use `useRouter` and cannot be mounted; the
export section and filter chips are presentational components that can.

## Risks

| Risk | Mitigation |
|---|---|
| A leaked ZIP link replays 100 Wikimedia fetches | Single-use nonce. The non-atomic consume is accepted and documented above. |
| A signed URL leaks via history or referrer | Five-minute TTL. The CSV link exposes nothing `search:read` does not. |
| The filtered set grows between mint and download | The download re-checks the cap rather than trusting the mint. |
| Someone edits the URL to widen the filter | The filters are signed parameters; any edit fails the signature. |
| A prefetch consumes a ZIP link before the real tap | Accepted. `Linking.openURL` never prefetches; documented above. |
| A ZIP build exceeds `max_execution_time` | Unchanged from the panel: the 100-image cap is the bound, now enforced at both mint and download. |
| `useCan` diverges from what the server granted | Cannot happen while every requested ability is valid. The rigorous fix is recorded as deferred. |

## Out of scope

No export on the web build. No per-image selection. No changes to the panel's
own exports, which keep working exactly as they do. No persisted granted
abilities — `useCan` reads the requested set, and the rigorous version is
deferred above. No atomic nonce claim.
