# Mobile Design System and Navigation — Design

**Date:** 2026-09-09
**Status:** Approved (pending implementation plan)
**Project:** cars-images-api (Laravel 13 + Filament 5 + Expo 57)
**Sub-project:** P1 of 5 — see [Programme](#programme)

## Goal

Give the Expo client a design system and a navigation structure that read as a
mobile translation of the Filament panel rather than an unstyled prototype, and
do it **without touching the API**.

Everything here is inside `mobile/`. No endpoint changes, no schema changes, no
new capabilities. What changes is how the four existing capabilities look, how
they are reached, and what the app is made of.

## Programme

This is the first of five sub-projects. The whole is "make the Expo app a
complete mobile client for the Filament panel"; that is far too large for one
spec, so it is decomposed by what unblocks what.

| # | Sub-project | Touches | Why this order |
|---|---|---|---|
| **P1** | **Design system and navigation** | `mobile/` only | No API dependency, so it ships first. Every later screen is assembled from the primitives it creates. |
| P2 | Authorization scoping | Laravel only | Gate in front of every privileged endpoint P3–P5 add. |
| P3 | CSV pipeline (imports, coverage, bulk run) | API + mobile | The highest-value new capability. Adds the fifth tab. |
| P4 | Library and exports | API + mobile | Grid parity with `Results`, plus signed CSV/ZIP export URLs. |
| P5 | Charts and make/model typeahead | API + mobile | Small; wraps widget `series()` methods and the `car_makes` lookup. |

Design-first is a rework argument, not a polish argument. P3 alone adds roughly
eight screens; built against today's primitives — no `Button`, no `Field`, no
`Skeleton` — each would be styled ad hoc and then restyled when the token layer
landed.

### Scope decisions taken during brainstorming

Recorded here because they bind P2–P5, not just this sub-project.

| Question | Decision |
|---|---|
| Admin user CRUD on mobile | **Cut.** Quarterly-frequency capability, highest-risk ability set. |
| Deletes (images, makes, imports, users) | **Cut.** No mobile token may destroy anything. |
| Car makes CRUD | **Cut as a screen**; becomes a read-only typeahead in the search form (P5). `CarMake`/`CarModel` are referenced in exactly one non-CRUD place — the make/model dropdowns at `CarSearchResource.php:207-245`. They are a lookup table for a form. |
| ZIP and CSV export | **Kept**, delivered as short-TTL signed URLs opened via `Linking.openURL` (P4). |
| Export selection model | **The active filter, not a checkbox selection.** |
| Authorization model | **Client-declared scope at login**, intersected server-side. No role column (P2). |
| Navigation | **Five tabs**, reached in two steps: P1 restructures to four, P3 adds the fifth. |
| Palette | **Dark slate shell, amber accent.** |
| Typeface | **Platform system stack.** Inter was considered and declined. |

#### On "tailored endpoints, not generic CRUD"

The [2026-09-05 spec](2026-09-05-react-native-mobile-client-design.md) chose
tailored endpoints because "the grid, the review queue and the health screen
each want a different slice; generic resources over a phone connection are
slow." Cloning an admin panel appears to push the other way. It does not, and
the decision stands — but its threshold should be explicit:

> Endpoints are shaped by the screen that consumes them. Where a table is
> small, flat and slow-changing, "the screen" and "the resource" coincide, and
> a conventional REST shape *is* the tailored shape.

The original justification was **cost**, and cost is real for deep related
resources — which is why `GET /images` is cursor-paginated. It is not real for
`car_makes`: a few hundred flat rows, changing monthly.

Two consequences worth carrying forward:

- **The panel's own tailoring is what parity means.** Three of its seven
  resources are already screen-shaped rather than model-shaped:
  `SearchQueryResource` is `CarSearch` filtered by `whereNotNull('csv_import_id')`
  with a bespoke coverage filter and a chunked bulk-run action; `Results` is a
  `Page`, not a Resource; `CsvImportResource::create` is a custom page wrapping
  an importer. Generic CRUD would clone Filament's scaffolding and lose its
  design.
- **The pull toward generic CRUD was strongest where value was lowest** —
  makes, models and users, all three of which the scope decisions cut or
  demoted. That is not a coincidence.

One clause of the original spec does not survive: *"Visibility: match Filament —
all authenticated users see everything."* Permissive-by-decision is correct for
reads and stays. It is not correct for the privileged verbs P2 introduces.

## What is wrong today

Observed on the live build at https://cars-mobile.netlify.app on 2026-09-09,
compared against the panel at https://cars-search.artworkwebsite.com/admin.

**The panel is light-mode with an amber primary.** `AdminPanelProvider` sets
`'primary' => Color::Amber`; the live panel renders white cards on `#f9fafb`
with `#f59e0b` buttons and labels above every input. The mobile app is slate
with a sky-500 accent. The two clients share no colour.

| Defect | Detail |
|---|---|
| **Tab bar has no icons** | `_layout.tsx` sets only `title` on each `Tabs.Screen`, so React Navigation draws placeholder glyphs. Visible on the live site. |
| **Review actions carry equal weight** | Approve and Reject are both saturated filled blocks of identical width. The destructive action has the same emphasis as the constructive one. |
| **Review cards hide the machine's verdict** | `make_confirmed` and `year_confirmed` are in `ImageResource`, in the Zod schema, and fetched on every card — then discarded. The reviewer judges relevance blind, which defeats the reason `review_status` is a separate column. |
| **Raw Commons syntax reaches the user** | Cards show `File:2001 Audi RS4 B5 Avant - Flickr - The Car Spy (3).jpg`. |
| **Every screen titles itself twice** | Navigator header "Review", then a body heading "Review queue". |
| **Placeholder-as-label** | Login and every filter input. The label vanishes on first keystroke. Filament labels every field. |
| **One surface step** | slate-800 cards on a slate-900 shell. Too little separation for the card density the panel uses. |
| **No type scale** | `text-xs / sm / base / xl` chosen per screen — four sizes, not a scale. 14 and 12 blur together. |
| **All-caps eyebrow labels** | `text-xs uppercase tracking-wide` section headers on Health. Filament's section headings are sentence case. |
| **Bare spinners** | `ActivityIndicator` for grid, health and list loads. |
| **Thin empty states** | Title plus optional hint. No icon, no action. |
| **Colour hardcoded as hex literals** | `#0f172a`, `#38bdf8`, `#f8fafc`, `#94a3b8` appear literally across the three `_layout.tsx` files, `InfiniteGrid` and several screens, because navigator options and some RN props cannot take class names. |

The radius system is **not** among the defects. The app already runs three
tiers — `rounded-lg` on controls, `rounded-xl` on cards, `rounded-full` on
badges. It needs codifying, not inventing. The login screen reads flat because
it has no cards on it, so only the control tier is visible there.

## Architecture

### The token module is TypeScript, and Tailwind imports it

The one structural decision in P1.

Two consumers need colour and only one can use NativeWind. Navigator options
(`headerStyle`, `tabBarActiveTintColor`, `contentStyle`) and several React
Native props (`placeholderTextColor`, `RefreshControl.tintColor`,
`ActivityIndicator.color`) take **values, not class names**. That is why the
hex literals are scattered today.

So:

```
mobile/src/theme/tokens.ts     ← single source of truth, plain TS
        │
        ├── imported by tailwind.config.js  → className consumers
        └── imported by *_layout.tsx, primitives → value consumers
```

`tokens.ts` exports plain objects (`color`, `space`, `radius`, `type`) with no
React and no NativeWind import, so `tailwind.config.js` can `require` it from
Node at build time. Without this, a palette change lands in the classNames and
silently misses every navigator, shipping amber content inside sky-blue chrome.

### Colour

Three surface tiers, mirroring how the light panel raises a white card off a
grey shell.

| Token | Value | Use |
|---|---|---|
| `surface` | slate-950 `#020617` | Screen background |
| `surface-raised` | slate-900 `#0f172a` | Cards, list rows |
| `surface-sunken` | slate-800 `#1e293b` | Inputs, inactive chips |
| `border` | slate-800 `#1e293b` | Hairlines |
| `border-strong` | slate-700 `#334155` | Chip outlines, dividers |
| `text` | slate-50 `#f8fafc` | Primary |
| `text-secondary` | slate-400 `#94a3b8` | Meta, descriptions |
| `text-muted` | slate-500 `#64748b` | Timestamps, hints |
| `accent` | amber-500 `#f59e0b` | Primary action fills |
| `accent-text` | amber-400 `#fbbf24` | Links and accents on dark |
| `accent-fg` | slate-950 `#020617` | Label **on** an amber fill |

`accent-fg` matters: the panel's amber button has dark text. White on amber-500
fails contrast; slate-950 on amber-500 is roughly 9:1.

Semantic colours keep their existing roles from `StatusBadge`: emerald
(approved, completed, downloaded), red (rejected, failed), sky (running,
downloading), slate (pending, neutral). **Sky survives as a status colour and
stops being the accent.**

### Type

Five steps at roughly a 1.2 ratio.

| Step | Size / line / weight | Use |
|---|---|---|
| `title` | 24 / 30 / 700, `-0.02em` | Screen headings |
| `section` | 18 / 24 / 600 | Card titles, section headings |
| `body` | 15 / 22 / 400 | Body copy, input text |
| `meta` | 13 / 18 / 400 | Descriptions, secondary lines |
| `micro` | 11 / 16 / 600, `+0.02em` | Badges, counts |

Body moves 14 → 15 so it separates from meta at 13. Platform system stack
throughout — SF Pro on iOS, Roboto on Android, `system-ui` on web.

All-caps eyebrow labels are **removed**, not restyled. They are absent from
Filament and are a generic template signature; sentence-case `section` headings
in `text-secondary` replace them.

### Spacing and radius

4px base: `1`=4, `2`=8, `3`=12, `4`=16, `6`=24, `8`=32. Screen padding 16, card
padding 12, list gap 8, section gap 24. The values are close to what exists; the
change is that they stop being chosen per screen.

Radius codified as three named tiers rather than raw Tailwind classes:
`radius.control` 8, `radius.surface` 12, `radius.pill` 9999.

### Motion

**One moment. Everything else static.**

No entrance animations, no hover transitions, no shimmer on skeletons. The
single exception is the review card animating out when a verdict lands — that
motion is the receipt that the verdict was recorded, so it carries information
rather than decorating. `prefers-reduced-motion` collapses it to an instant
removal.

## Components

### New primitives

| Component | Responsibility | Depends on |
|---|---|---|
| `Button` | `variant`: `primary` \| `secondary` \| `danger-subtle` \| `ghost`; `size`: `md` \| `lg`; pending state | tokens |
| `Field` | Label above input, optional hint, optional error, `TextInput` styling | tokens |
| `Skeleton` | Static block at a given size or aspect ratio | tokens |
| `SectionHeading` | Sentence-case heading plus optional trailing action | tokens |

`danger-subtle` is the Reject treatment: `bg-red-500/10`, `border-red-500/30`,
`text-red-300`. Filament tints destructive actions rather than filling them.

### Reworked primitives

| Component | Change |
|---|---|
| `Screen` | Takes tokens; `surface` background; optional `title` rendered as the in-body `title` step |
| `StatTile` | Type scale; optional `trend`; optional `onPress` |
| `StatusBadge` | `micro` step; tier-aware radius; unchanged tone map |
| `EmptyState` | Gains `icon` and optional `action` |
| `ErrorBanner` | `radius.surface` — it is a surface, not a control |
| `ImageCard` | Type scale; verdict badges; cleaned title |
| `InfiniteGrid` | `Skeleton` grid replaces `ActivityIndicator`; tokens for `RefreshControl` |

### The review card

The one place boldness is spent. It is the only interaction the panel cannot do
at all, and currently the weakest thing on screen.

```
┌──────────────────────────────────┐
│                                  │
│           image, 4:3             │
│                                  │
│  ⟨make matched⟩ ⟨year unknown⟩   │  machine verdicts, over the image
├──────────────────────────────────┤
│ 2001 Audi A4 Avant quattro       │  section
│ The Car Spy, via Flickr          │  meta / muted
│                                  │
│ ┌────────────────────┐ ┌───────┐ │
│ │      Approve       │ │Reject │ │  ~2:1
│ └────────────────────┘ └───────┘ │
└──────────────────────────────────┘
```

Approve is `variant="primary"` (amber fill, slate-950 label). Reject is
`variant="danger-subtle"`, narrower. The asymmetry is the point.

**Amber Approve produces an emerald "approved" badge, and that is correct.**
Amber is the primary-*action* colour; emerald is the approved-*state* colour.
Filament does exactly this — an amber "Create" button yielding a green
"completed" badge. Colouring the button emerald would collapse action and state
into one hue and leave the app with no primary colour.

**Verdict badges are read-only.** They render `make_confirmed` and
`year_confirmed` as `matched` / `not matched` / `unknown`, using the same
wording as `Results.php`. Nothing in the client ever writes them.

### Title cleaning

`title` arrives as `File:2001 Audi RS4 B5 Avant - Flickr - The Car Spy (3).jpg`.
A pure function in `src/format/` strips the `File:` prefix, the extension and
any trailing ` (n)` disambiguator, then presents the remainder. `attribution`
supplies the byline where present. Pure, so it is directly unit-testable.

## Navigation

Four tabs before, four tabs after — but not the same four.

| Before | After | Note |
|---|---|---|
| Search | **Search** | Form, plus the run list moved into its stack |
| Runs | — | Becomes `search/runs` and `search/runs/[id]` |
| — | **Library** | The image grid, promoted out of its buried text link |
| Review | **Review** | Redesigned card |
| Health | **Health** | Sentence-case sections, skeletons |

The grid is currently reachable only through a "Browse all images" link at the
bottom of the search form. It is the panel's most-used page and it is a
footnote.

The **Pipeline** tab is deliberately *not* created here. P3 adds it together
with the screens that fill it, so no dead tab ever ships. Run lists stay
unfiltered in P1, so CSV-derived runs remain reachable until P3 splits ad-hoc
from CSV-derived — the split the panel already makes between `CarSearchResource`
and `SearchQueryResource`.

Tab icons come from `@expo/vector-icons`, added **explicitly** to
`mobile/package.json` rather than relied on transitively through `expo`. Icon
choices follow the panel's own `$navigationIcon` declarations, so the two
clients share an icon vocabulary: magnifying glass (Search), photo (Library),
check badge (Review), chart bar (Health).

Each tab directory keeps or gains its own `_layout.tsx` with a `<Stack>`. Adding
a route file to a tab directory without one flattens it into the tab bar.

**Which title wins.** A screen is titled once, by its navigator. Where a tab
nests a `<Stack>` the Stack owns the header, and the Tabs layout sets
`headerShown: false` for that tab — the pattern `search/_layout.tsx` already
uses. `Screen`'s optional `title` prop is for the body heading on screens that
need one *in addition* to the header, such as a scrolling dashboard whose
heading should scroll away; it is not a second copy of the navigator title.
`PageTitle` remains separate and unchanged: it is the browser tab title for the
web export and renders nothing on native.

## Testing

Constraints that bind, restated so the plan cannot drift from them:

- `@testing-library/react-native` is pinned at 13.3.3 where `render()` is
  synchronous. **Never `await render(...)`.**
- `mobile/jest.config.js` is not modified. A file needing a DOM uses a
  per-file `/** @jest-environment jsdom */` docblock.
- Test `QueryClient`s: `gcTime: 0` for observed queries; `gcTime: Infinity`
  when seeding the cache directly; `mutations: { gcTime: 0 }` separately for
  mutation tests, because the queries default does not reach the MutationCache.
- Screens using `Link` or `useRouter` cannot be mounted — there is no router
  harness. Screens that only call hooks can; see
  `mobile/app/(app)/health/__tests__/health.test.tsx`.

What P1 adds:

| Target | Test |
|---|---|
| `tokens.ts` | Every token Tailwind's config reads is present and is a string |
| `Button` | Each variant renders its label; pending disables press |
| `Field` | Label renders; error renders; label survives typing |
| `EmptyState` | Action fires when supplied; absent when not |
| `StatusBadge` | Unknown status falls back to neutral (existing behaviour) |
| Title cleaner | `File:` prefix, extension and ` (n)` suffix all stripped |
| Verdict badges | `true` / `false` / `null` each render their wording |
| `AppLayout` | `layout.test.tsx` updated for the new tab set |

`InfiniteGrid`'s existing test covers the error-versus-empty distinction and
must keep passing against the skeleton loading branch.

The review card is a presentational component taking `image` and two callbacks,
extracted from the screen so it is mountable without a router.

## Risks

| Risk | Mitigation |
|---|---|
| Tailwind cannot import a TS module from `tailwind.config.js` | `tokens.ts` stays plain data with no imports. If the toolchain refuses TS at config time, the fallback is a `.js` token module with a `.d.ts` beside it — decided during implementation, not designed around now. |
| The four-tab reshuffle breaks route links | `router.push('/(app)/runs/[id]')` appears in `search/index.tsx`. Every literal route string is grepped and updated in the same commit as the move. |
| Surface darkening reduces contrast on existing screens | slate-950/900/800 tiers were contrast-checked; card text is slate-50 on slate-900 throughout. |
| Losing the Runs tab hides CSV-derived runs | The run list moves into the Search stack rather than being removed, and stays unfiltered until P3. |
| Amber reads as "warning" next to red failure badges | Amber is confined to actions and links; status badges keep emerald/red/sky/slate. No status is ever amber. |

## Out of scope

No API change, no new endpoint, no schema change. Nothing writes
`make_confirmed` or `year_confirmed` — they are the relevance checker's verdict,
and human review lives in `review_status` precisely so the machine-versus-human
comparison stays answerable. No dependency is added to the repository-root
`package.json`. The Pipeline tab, imports, bulk run, exports, charts and the
make/model typeahead are all P3–P5.
