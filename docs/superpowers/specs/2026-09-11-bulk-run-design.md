# Bulk Run — Design

**Date:** 2026-09-11
**Status:** Approved (pending implementation plan)
**Project:** cars-images-api (Laravel 13 + Filament 5 + Expo 57)
**Sub-project:** P3b of the programme — see the
[P1 spec's programme table](2026-09-09-mobile-design-system-and-navigation-design.md#programme)

## Goal

Let the mobile client run a CSV import's queued searches: the verb
[P3a](2026-09-10-csv-imports-and-coverage-design.md) deliberately withheld.

This is the half with operational risk. It is the third path to a Wikimedia
block, after the search form and the ZIP export, and the only one that runs for
minutes unattended.

## What the panel does, and what translates

```
startBulkRun(ids)              seeds a queue in Livewire component state
wire:poll.keep-alive.1s   →    runNextChunk()
  per request: up to 50 queries OR 10s wall-clock, whichever comes first
    ├─ RunSearchQueryAction::execute()    synchronous Commons call
    ├─ sleep(1) between queries           courtesy pacing
    └─ WikimediaBlockedException → halt, report Retry-After
```

Three properties carry over unchanged, and one does not.

**Carries over: the work is bounded per request.** There is no queue worker on
shared hosting, so a chunk must finish inside `max_execution_time`. Both bounds
stay server-side, read from the same config keys the panel uses.

**Carries over: the driver owns the run.** Run state lives in the client, not a
table. The [original spec](2026-09-05-react-native-mobile-client-design.md)
called this the honest behaviour — *nothing should keep calling Wikimedia once
the browser driving it has gone* — and it is more true on a phone, not less.

**Carries over: a block halts everything.** `WikimediaBlockedException` stops
the run and surfaces `Retry-After`. It is not a per-query failure to skip past.

**Does not carry over: how badly the driver can vanish.**
[`bulk-run-progress.blade.php`](../../../resources/views/filament/bulk-run-progress.blade.php)
uses `wire:poll.keep-alive` because Livewire pauses ordinary polling on a
blurred tab, and notes Chrome still throttles background timers — throttles,
but advances. **Backgrounding an Expo app suspends JS timers outright.** A
mobile run does not slow down when the user switches apps; it stops.

## Decisions

| Decision | Choice | Why |
|---|---|---|
| Chunk selection | **A filter, not an id list** | See below. |
| Chunk response | **Per-id outcomes plus timing** | A failure should read as "1998 Honda Civic — failed", not as a number. Timing lets the client rebuild the panel's observed-pace estimate. |
| Run state | **Client-held; no new table** | The `car_searches` rows already are the state. See [Resume](#resume-is-already-built). |
| `search:run` on web | **Yes** — reverses P2 | See [The ability decision](#the-ability-decision). |
| Screen sleep | **`expo-keep-awake` while running** | A locked screen suspends the timer driving the run. |
| Progress bar | **`react-native-reanimated`** | Already present at 4.6.0, pulled transitively. |

### A filter, not an id list

`POST /searches/run-chunk` takes `{ csv_import_id }` and runs the next slice of
that import's not-yet-run searches. It does **not** take `{ ids: [...] }`,
which is what Filament's "Run Selected" does.

- **The client never needs every id.** With ids, running a 58-query import
  means paginating three cursor pages purely to collect them before starting.
- **Resume is the same request.** "The next N not-yet-run for this import" is
  identical whether it is the first chunk or the fifth after a crash.
- **Selection is miserable on a phone**, the same reasoning that made P4's
  export act on the active filter rather than a checkbox selection.

The server picks rows exactly as the panel's `runnableSearchIds()` does:
`status` in `pending`, `failed`, ordered by `id` — CSV order, so a resumed run
continues where the import created its searches rather than wherever the engine
happens to return them.

### Resume is already built

P3a shipped the resume mechanism without meaning to.

A search is `pending` until it completes, so **the rows are the run state**.
If a run dies after 10 of 23, `coverage.not_run` reports 13, and the import
screen already renders that as a tappable count filtering to exactly those 13.
Tapping Run again picks up precisely where it stopped.

No new schema, no orphaned crawler, and it mirrors the panel's own recovery
path, which the progress callout states in words: *"wait for the Retry-After
window, then use Run all pending to pick up where this left off."*

### The ability decision

[P2](2026-09-10-api-authorization-scoping-design.md) put `search:run` in the
privileged set and kept it out of the web build. **That is reversed here**, and
both that spec and P3a's are amended to say so.

The reasoning that changed:

**`search:run` cannot create work.** It advances `pending` rows that already
exist. A stolen web token cannot invent searches — that needs `search:write`
(already on web, and capped at four years by five images per call) or
`imports:write` (still not on web). The ceiling is *searches you already
queued, run at a time you did not choose*, not *an attacker crawls Wikimedia at
will*.

**The residual risk, stated plainly:** someone holding a stolen `localStorage`
token could drain a large pending queue faster than intended, possibly
colliding with a Wikimedia rate-limit window. Server-side pacing and a tight
route throttle bound the rate; nothing bounds the intent. Accepted, because the
web build *is* the demo and a Pipeline tab whose central verb 403s would be
worse than the risk.

`imports:write` remains native-only. Uploading seeds hundreds of new queries;
running only advances existing ones.

## API

### `POST /api/v1/searches/run-chunk`

Ability `search:run`, policy `CarSearchPolicy::run`, throttled at the same rate
as the other Wikimedia-reaching routes.

**Request:** `{ "csv_import_id": 12 }`

**Response 200:**

```json
{
  "outcomes": [
    { "id": 41, "make": "Toyota", "model": "Corolla", "from_year": 1998, "outcome": "completed" },
    { "id": 42, "make": "Honda",  "model": "Civic",   "from_year": 1999, "outcome": "failed" }
  ],
  "ran_seconds": 8.4,
  "remaining": 21,
  "blocked": null
}
```

`outcome` is `completed`, `failed`, or `skipped` — the last for a row that left
the queue without being work, because it was deleted or already finished. The
panel's loop does the same, and a row that neither runs nor leaves would make
the run undrainable.

**When Wikimedia blocks**, the chunk stops mid-slice and reports it:

```json
{ "outcomes": [...], "ran_seconds": 3.1, "remaining": 21,
  "blocked": { "status": 429, "retry_after_seconds": 600 } }
```

A block is not a per-query failure. `RunSearchQueryAction` has already written
the `wikimedia_block_events` row and the `error_events` row before rethrowing;
this endpoint only has to stop and say so.

**`remaining` is counted after the slice**, so the client always knows the true
figure even if it lost a response.

### Bounds

Read from `config('cars-images.*')`, unchanged from the panel:
`bulk_run_max_queries_per_chunk` (50), `bulk_run_auto_chunk_seconds` (10),
`bulk_run_sleep_seconds_between_queries` (1).

An empty queue returns `{ outcomes: [], ran_seconds: 0, remaining: 0 }` rather
than a 404 or a 422. Asking a finished run to continue is not an error, and the
client's loop terminates on `remaining === 0`.

## Client

One screen — the import detail gains a run section — plus a hook that owns the
loop.

```
useBulkRun(importId)
  status      idle | running | paused | blocked | finished
  processed / failed / total / remaining
  secondsRemaining      from observed pace, as the panel derives it
  lastOutcomes          the current chunk, so a failure names its car
  start() pause() resume()
```

The loop is `while (status === 'running' && remaining > 0)` posting one chunk at
a time. No polling interval: a chunk request already takes up to ten seconds, so
the next goes out when the previous returns. Livewire needs a timer because the
server drives; here the client does.

**Pause takes effect between chunks**, never mid-chunk — the server has no way
to be interrupted, and a chunk it is midway through will finish and be recorded.
The button says so.

**While running:** `expo-keep-awake` holds the screen on, and the screen says
plainly that leaving the app stops the run. That is not a caveat buried in help
text; it is the single most surprising thing about the feature.

**On a block:** the run stops, the panel turns red, and the message carries the
`Retry-After` window and the same instruction the Filament callout gives —
wait, then run again to pick up where this left off.

**Progress** is a reanimated width transition on the same percentage the panel
computes: `(processed + failed) / total`.

## Testing

**Laravel.** The chunk endpoint: runs up to the count bound; stops at the
wall-clock bound; reports per-id outcomes; marks a deleted or already-completed
row `skipped` and still drains it; halts on `WikimediaBlockedException` with the
retry window; returns an empty successful response for a finished import; 403s
without `search:run`. Wikimedia is faked throughout —
`ContractFixturesTest::fakeWikimediaSearchResults()` already establishes the
pattern, and **no test may reach Commons.**

**Mobile.** `useBulkRun` is the unit under test, not the screen: the loop
continues while remaining is positive, stops on pause, stops on block, and
surfaces per-id outcomes. Mutation tests need `mutations: { gcTime: 0 }`
separately. The progress bar and the block callout are presentational and
mountable; the screen using `useRouter` is not.

## Risks

| Risk | Mitigation |
|---|---|
| A run hammers Wikimedia | Unchanged from the panel: server-side pacing between queries, a hard per-chunk bound, and a halt on the first block. The client cannot ask for a bigger slice — the bounds are config, not parameters. |
| The user does not realise leaving the app stops the run | Stated on screen while running, not in help text. `expo-keep-awake` covers the screen locking, which is the commoner case. |
| A dropped chunk response loses track | `remaining` is authoritative and recounted server-side each chunk. The client's counters are display only. |
| Two devices run the same import at once | Both advance the same rows; the second sees them as `skipped` and drains harmlessly. Wikimedia sees two paced streams rather than one — the honest bound is the route throttle. Worth noting, not worth locking. |
| `search:run` in a web `localStorage` token | Accepted, with the reasoning above. It advances existing rows only; it cannot create work. |

## Out of scope

No ad-hoc bulk running — this endpoint takes a `csv_import_id`, and searches
without one are the Search tab's single-run path. No scheduling, no background
execution, no queue worker: there is nowhere to run one, and a run that
continues after its driver has gone is the thing this design refuses. No
per-query cancel; pause is between chunks.

## Amendments to earlier specs

Both are updated in the same commit as this one, so no committed spec
contradicts the code:

- **P2** — `search:run` moves out of the never-on-web set. The reasoning
  ("it cannot create work") is recorded there, not only here.
- **P3a** — the requested-abilities table gains `search:run` for both builds.
