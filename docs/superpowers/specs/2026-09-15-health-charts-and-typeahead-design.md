# Health Charts and Make/Model Typeahead — Design

**Date:** 2026-09-15
**Status:** Approved (pending implementation plan)
**Project:** cars-images-api (Laravel 13 + Filament 5 + Expo 57)
**Sub-project:** P5, the last of the programme — see the
[P1 spec's programme table](2026-09-09-mobile-design-system-and-navigation-design.md#programme)

## Goal

Two small things that finish the programme:

1. **Charts on the Health screen**, translating the panel's two dashboard
   widgets — search throughput and failures by kind — into mobile form.
2. **Make and model suggestions** in the search form, from the `car_makes` and
   `car_models` tables the panel's dropdowns already read.

## What exists

**No chart library is installed.** The obvious candidates carry real costs here:
`victory-native` needs Skia and Reanimated, and Reanimated 4 already fails to
load under this Jest setup (P3b's review-card animation was deferred for that
reason); Skia on the web build also means a large WebAssembly download.

**Both panel widgets already compute their data.**
[`SearchThroughputChart::series()`](../../../app/Filament/Widgets/SearchThroughputChart.php)
returns 30 days of completed and failed searches;
[`ErrorsByContextChart::series()`](../../../app/Filament/Widgets/ErrorsByContextChart.php)
returns 14 days of failures per error context, including only contexts that
failed in the window. Both use the `BucketsByDay` trait and **bake hex colours
into their output** — presentation mixed into data.

**Makes and models are a small curated table**: ten makes and about 37 models,
seeded by `CarMakeSeeder`. The panel's
[`CarSearchResource::getMakeOptions()`](../../../app/Filament/Resources/CarSearchResource.php)
also carries a **hard-coded copy of the same list**, used when the table is
empty.

## Decisions

| Decision | Choice | Why |
|---|---|---|
| Chart rendering | **Plain `View`s, no library** | Zero dependencies, nothing new for Jest to load, identical on web, styled with the tokens. |
| Throughput form | **30 daily stacked bars**, not the panel's line | On a phone, 30 thin bars answer "did the pipeline run that day?" at a glance, and an empty day is visibly empty; a 30-point line joins the gaps. |
| Errors-by-context form | **Small multiples, one per kind** — changed from stacked bars after validation | See [Why small multiples](#why-small-multiples). |
| Series endpoint | **One `GET /health/series`** | The Health screen always shows both. |
| Colour in the API | **None — data only** | Colour is presentation; the app's comes from its tokens. |
| Counting | **Extracted into a service** the widgets and the API share | The P3a coverage pattern: one implementation, so panel and app cannot drift. |
| Typeahead | **Suggest, never constrain** | Every search reaches Wikimedia; limiting input to the ten seeded makes would block searching anything else. |
| Fallback list | **Not copied a third time** | The API returns only what is in the table. |

## Colour, validated

Chart colour was decided with the `dataviz` skill's validator run against the
app's real chart surface — `#0f172a`, the raised card colour — not a reference
surface, and not by eye.

### The two charts do different colour jobs

The skill's collision rule: *when a series means good or bad it wears status
colours; when it is just identity it wears categorical — never both in one
chart.*

- **Throughput** plots completed against failed. That *means* good against
  bad, so it takes **status** colours: the app's existing `success` and
  `danger` tokens.
- **Failures by kind** plots only failures; its job is telling *which kind*.
  That is identity, so it is a **categorical** job — not five shades of red.

### Why small multiples

The first design stacked the five error contexts in one bar. Two sound rules
make that impossible to colour:

1. **Colour follows the entity, never its rank.** Each context keeps one colour
   for good, so `wikimedia_block` does not change colour between fortnights as
   other contexts appear and disappear.
2. **Only contexts that failed get a series**, so a quiet week does not bury the
   one that matters under four empty series.

Together they mean *any two* of the five contexts can end up stacked next to
each other. Every pair has to be distinguishable, not just neighbouring slots —
the validator's `--pairs all` mode.

| Candidate, all pairs on `#0f172a` | Result |
|---|---|
| Reference slots 1–5 | **FAIL** — magenta/aqua ΔE **1.6** under deuteranopia; yellow/orange ΔE 10.6 normal vision, below the hard floor of 15 |
| Blue, orange, aqua, magenta, violet (avoiding red and green, which read as the throughput chart's failed and completed, and yellow, which is nearly the amber accent) | **FAIL** — magenta/aqua ΔE **1.6** deuteranopia; blue/violet ΔE 9.8 normal vision |
| Blue, orange, aqua — three only | **PASS** every check |

A ΔE of 1.6 means those two colours are effectively identical to readers with
deuteranopia. The normal-vision failures are below a floor the skill treats as
a hard fail that labels cannot excuse. No reordering helps once every pair
counts; this is the documented limit that only three hues clear all-pairs.

**Small multiples need no categorical palette at all.** Each chart is one
series, titled by its kind, drawn in the first categorical slot — blue
`#3987e5`, which clears 3:1 on the surface. A single series needs no legend
box: the title names it. Colour-follows-entity is then satisfied trivially,
because nothing depends on colour for identity.

**The cost, accepted:** the at-a-glance total of how bad each *whole* day was.
The throughput chart already shows daily failed searches, and each small
multiple shares one y-scale so the kinds remain comparable.

### The throughput status pair

`success #10b981` with `danger #ef4444` on `#0f172a`:

| Check | Result |
|---|---|
| CVD separation (deuteranopia) | **PASS**, ΔE 8.1 — just clearing the 8 target |
| Normal-vision floor | PASS, ΔE 33.8 |
| Contrast vs surface | PASS, both ≥ 3:1 |
| Lightness band | fails for `#10b981` (L 0.696) — a harmony rule for *categorical* series, not applicable to status colours; the skill's own reference status palette sits outside it too |

**8.1 is marginal, so colour does not carry the meaning alone.** The chart
ships a legend labelled *Completed* and *Failed* — required anyway for two
series — and a **2px surface gap** between stacked segments, so the two are
physically separate even where their colours converge.

### Mark specs

From the skill's mark specifications:

- Thin bars; **4px rounded data-ends anchored to the baseline**.
- **2px surface gap** between stacked segments and between adjacent bars.
- **Recessive axes and grid**: `text-muted` labels, `border` hairlines.
- **Text wears text tokens, never the series colour.** Labels, values and
  legend text stay in `text` / `text-secondary` / `text-muted`; a coloured
  swatch beside them carries identity.
- **One y-axis per chart.** Small multiples share a single scale so they can be
  compared.

### Interaction

The skill ships a hover tooltip by default. A phone has no hover, so the
mobile equivalent is **tap a bar to see that day's figures**: a callout above
the chart naming the date and the counts.

Every bar also carries an `accessibilityLabel` — for example *"3 September: 4
completed, 1 failed"* — so a screen reader reads the data a sighted user reads
off the bars.

## API

### `GET /api/v1/health/series`

Ability `errors:read`, the same as `GET /health/summary`.

```json
{
  "throughput": {
    "days": ["2026-08-17", "…", "2026-09-15"],
    "completed": [0, 2, 0],
    "failed": [0, 0, 1]
  },
  "errors_by_context": {
    "days": ["2026-09-02", "…", "2026-09-15"],
    "series": {
      "search_run": [0, 3, 1],
      "wikimedia_block": [0, 0, 2]
    }
  }
}
```

**Every day is listed, including quiet ones**, as `BucketsByDay` does, so a gap
reads as a gap rather than closing up and shifting its neighbours.

**`series` holds only contexts that failed in the window.** An absent context
means zero, and the app draws no chart for it.

### The extraction

`App\Services\Health\PipelineSeries` takes over the counting from both widgets
and returns the shape above. The widgets call it and add their Chart.js colours
on top, exactly as `Results` layers its deep links on `ImportCoverage`.

**Each widget's `series()` keeps its Chart.js shape exactly** — `labels`, and
`datasets` of `label` / `data` plus colours. The service speaks the API's shape
(`days`, `completed`, `series`); each widget translates back rather than
adopting the new keys. This is a hard constraint, not a preference: both widget
tests call `series()` and assert on `labels`, `datasets[].label` and
`datasets[].data` (though not on colours). P3a broke `RunCoverageTest` by
renaming keys at exactly this kind of seam; keeping the widget's shape is what
lets those tests pass unedited here. The SQL
grouping — `DATE(...)` with `COUNT(*)` over an indexed window — moves across
unchanged; the trait's docblock explains why it counts in SQL rather than PHP,
and that reasoning travels with it.

### `GET /api/v1/makes`

Ability `search:read`.

```json
{ "data": [ { "name": "BMW", "models": ["3 Series", "5 Series", "X3", "X5"] } ] }
```

Makes sorted by name; models sorted naturally and case-insensitively, as
`getModelOptionsForMake()` sorts them. Small and rarely changing, so the app
caches it for a long time.

**Returns only what is in the table.** If it is empty, the response is empty and
the search form's fields stay plain text — they still work. The panel's
hard-coded fallback is not copied into a third place.

## Client

### Health

The existing counters stay. Below them:

- **Searches run, last 30 days** — the stacked status bars, with the legend.
- **Failures by kind, last 14 days** — one small multiple per context present,
  each titled with the context's label from `ErrorEvent::contexts()` and sharing
  one y-scale. When no context failed, one line says so rather than an empty
  chart.

Both charts are presentational `View` components taking plain arrays, so they
are mountable in tests.

### Search form

The Make field shows up to five makes whose names start with what has been
typed, case-insensitively, as tappable rows beneath it. Tapping one fills the
field. The Model field does the same from the chosen make's models, and shows
nothing when the make is not in the list. **Nothing is required to match**:
typing a make outside the list and submitting works exactly as it does today.

## Testing

**Laravel**

- `PipelineSeries` returns every day in each window, including empty ones, and
  counts completed and failed separately.
- Only contexts that failed appear in `series`.
- The widgets still render after the extraction (their existing tests pass).
- `GET /health/series` requires `errors:read`; `GET /makes` requires
  `search:read`.
- `GET /makes` returns sorted makes with naturally sorted models, and an empty
  list when the table is empty — not the fallback.

**Mobile**

- The throughput chart renders one bar per day, gives a zero day no filled
  segment, and labels each bar for screen readers.
- The failures chart renders one small multiple per context present, none for
  absent contexts, and a message when there are none.
- Suggestions filter by case-insensitive prefix, cap at five, and fill the field
  when tapped; a make outside the list still submits.

**Step 7 of the skill: render it and look at it.** The validator checks colour,
not layout. Before calling the charts done, screenshot the web build's Health
screen and check for label collisions and overflow.

## Risks

| Risk | Mitigation |
|---|---|
| Production never ran `CarMakeSeeder` | **Check before relying on the typeahead.** If the table is empty, the panel has been showing its hard-coded fallback all along and the suggestions will show nothing. The fix is running the seeder, not copying the list. Recorded as a deploy note. |
| The status pair's red-green separation is marginal (ΔE 8.1) | Legend with labels, and a 2px gap between segments, so colour never carries the meaning alone. |
| Thirty bars are too thin to tap | Tap targets are wider than the drawn bar, per the skill's interaction spec. |
| The extraction changes the panel's charts | Widget tests pass unedited **only because `series()` keeps its Chart.js keys**; verified against the tests, which assert `labels` and `datasets[].label/data`. Renaming them — as P3a did to `coverage()` — would break both. |
| Five contexts become more | A new context gets its own small multiple automatically; no palette slot to run out of. |

## Out of scope

No editing makes or models (cut programme-wide). No line chart. No changes to
the panel's own charts beyond calling the shared service. No chart library. No
restricting the search form to known makes.
