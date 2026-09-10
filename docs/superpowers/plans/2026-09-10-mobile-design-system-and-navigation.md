# Mobile Design System and Navigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the Expo client a token-driven design system and a four-tab
structure that reads as a mobile translation of the Filament panel, without
touching the API.

**Architecture:** A single plain-TypeScript token module becomes the source of
truth for colour, type, spacing and radius; `tailwind.config.js` imports it for
`className` consumers and the layouts and primitives import it for the value
consumers NativeWind cannot reach. Four new primitives (`Button`, `Field`,
`Skeleton`, `SectionHeading`) join the six that exist, the review card becomes
a mountable presentational component carrying the machine's verdict, and the
tab set changes from Search/Runs/Review/Health to Search/Library/Review/Health.

**Tech Stack:** Expo 57, expo-router 57, React Native 0.86, NativeWind 4,
TailwindCSS 3.4, TypeScript 6 (`strict`), Jest 29 + jest-expo,
@testing-library/react-native 13.3.3.

**Spec:** [`docs/superpowers/specs/2026-09-09-mobile-design-system-and-navigation-design.md`](../specs/2026-09-09-mobile-design-system-and-navigation-design.md)

## Global Constraints

- All work stays inside `mobile/`. **Never add a dependency to the
  repository-root `package.json`.**
- TypeScript `strict` and `noUncheckedIndexedAccess` are both on. No `any`. No
  `@ts-expect-error` without an explanatory comment.
- `@testing-library/react-native` is pinned to 13.3.3, where `render()` is
  synchronous. **Never `await render(...)`.**
- **`mobile/jest.config.js` must not be modified.** A test needing a DOM uses a
  per-file `/** @jest-environment jsdom */` docblock.
- Test `QueryClient`s: `gcTime: 0` for observed queries; `gcTime: Infinity` when
  seeding the cache directly; `mutations: { gcTime: 0 }` **separately** for
  mutation tests — the `queries` default does not reach the MutationCache.
- Screens that use `Link` or `useRouter` cannot be mounted in tests (no router
  harness). Screens that only call hooks can — see
  `mobile/app/(app)/health/__tests__/health.test.tsx`.
- Adding a second route file under a tab directory flattens it into the tab bar
  unless that directory has its own `_layout.tsx` with a `<Stack>`.
- **Never write `make_confirmed` or `year_confirmed` from the client.** They are
  the relevance checker's verdict; human review lives in `review_status`
  precisely so the machine-versus-human comparison stays answerable.
- `EXPO_PUBLIC_API_URL` is inlined at build time. Changing the GitHub variable
  rebuilds nothing — Mobile CI must be re-run manually.
- Laravel tests run in the `cars-ci-php:8.3` container; mobile tests run on the
  host. `vendor/bin/pint --test` is a CI gate.
- Every task ends green on `npm run typecheck && npm run lint && npm test`, run
  from `mobile/`.

## File Structure

**New**

| File | Responsibility |
|---|---|
| `src/theme/tokens.ts` | The only place a colour, size, space or radius is written down. Plain data, no imports. |
| `src/ui/Button.tsx` | Pressable with `variant` and `size`; owns the pending state. |
| `src/ui/Field.tsx` | Label above input, optional hint and error, styled `TextInput`. |
| `src/ui/Skeleton.tsx` | Static placeholder block; `SkeletonGrid` for list loads. |
| `src/ui/SectionHeading.tsx` | Sentence-case heading with an optional trailing action. |
| `src/ui/VerdictBadge.tsx` | Read-only rendering of `make_confirmed` / `year_confirmed`. |
| `src/ui/ReviewCard.tsx` | The review queue's card. Presentational; no router, no hooks. |
| `src/format/imageTitle.ts` | Pure Commons-title cleaner and byline builder. |
| `src/screens/ImageDetailScreen.tsx` | Image detail body, taking `id` as a prop so it is mountable. |
| `app/(app)/library/_layout.tsx` | Stack for the Library tab. |
| `app/(app)/library/index.tsx` | The image grid, promoted from `search/images.tsx`. |
| `app/(app)/library/[id].tsx` | Route shell over `ImageDetailScreen`. |
| `app/(app)/search/runs/index.tsx` | Run list, moved from `runs/index.tsx`. |
| `app/(app)/search/runs/[id].tsx` | Run detail, moved from `runs/[id].tsx`. |

**Deleted**

| File | Why |
|---|---|
| `app/(app)/runs/_layout.tsx` | The Runs tab is gone; `search/_layout.tsx` absorbs its screens. |
| `app/(app)/runs/index.tsx` | Moved to `search/runs/index.tsx`. |
| `app/(app)/runs/[id].tsx` | Moved to `search/runs/[id].tsx`. |
| `app/(app)/runs/image/[id].tsx` | Unnecessary — run images now link to `search/[id]` in the same stack. |
| `app/(app)/search/images.tsx` | Moved to `app/(app)/library/index.tsx`. |

**Modified**

`tailwind.config.js`, `package.json`, `app/(app)/_layout.tsx`,
`app/(app)/search/_layout.tsx`, `app/(app)/search/index.tsx`,
`app/(app)/search/[id].tsx`, `app/(app)/review/index.tsx`,
`app/(app)/health/index.tsx`, `app/login.tsx`, `app/index.tsx`,
`src/ui/Screen.tsx`, `src/ui/StatTile.tsx`, `src/ui/StatusBadge.tsx`,
`src/ui/EmptyState.tsx`, `src/ui/ErrorBanner.tsx`, `src/ui/ImageCard.tsx`,
`src/ui/InfiniteGrid.tsx`, `app/(app)/__tests__/layout.test.tsx`.

---

### Task 1: The token module and its Tailwind wiring

The one structural decision in this plan. Navigator options (`headerStyle`,
`tabBarActiveTintColor`, `contentStyle`) and several RN props
(`placeholderTextColor`, `RefreshControl.tintColor`, `ActivityIndicator.color`)
take **values, not class names** — which is why hex literals are scattered
across three `_layout.tsx` files, `InfiniteGrid` and several screens today.
`tokens.ts` therefore stays plain data with no imports, so
`tailwind.config.js` can `require` it from Node at build time.

**Files:**
- Create: `mobile/src/theme/tokens.ts`
- Create: `mobile/src/theme/__tests__/tokens.test.ts`
- Modify: `mobile/tailwind.config.js`

**Interfaces:**
- Consumes: nothing.
- Produces: `color`, `space`, `radius`, `fontSize` — all `as const` objects.
  `color` keys: `surface`, `surfaceRaised`, `surfaceSunken`, `border`,
  `borderStrong`, `text`, `textSecondary`, `textMuted`, `accent`, `accentText`,
  `accentFg`, `success`, `successText`, `danger`, `dangerText`, `info`,
  `infoText`. Tailwind gains matching class names: `bg-surface`,
  `bg-surface-raised`, `bg-surface-sunken`, `border-border`,
  `border-border-strong`, `text-text`, `text-text-secondary`,
  `text-text-muted`, `bg-accent`, `text-accent-text`, `text-accent-fg`,
  plus `rounded-control` / `rounded-surface` / `rounded-pill` and the
  `text-title` / `text-section` / `text-body` / `text-meta` / `text-micro`
  sizes.

- [ ] **Step 1: Write the failing test**

```ts
// mobile/src/theme/__tests__/tokens.test.ts
import { color, fontSize, radius, space } from '../tokens';

describe('design tokens', () => {
  it('gives every colour a six-digit hex value', () => {
    // tailwind.config.js requires this module from Node at build time and
    // spreads it straight into theme.extend.colors. A non-hex value there is
    // not a type error, it is a class name that silently does nothing.
    for (const [name, value] of Object.entries(color)) {
      expect(`${name}: ${value}`).toMatch(/: #[0-9a-f]{6}$/);
    }
  });

  it('separates the three surface tiers', () => {
    // A single step is what makes the current app read flat; the card must be
    // lighter than the shell and the input lighter again.
    expect(color.surface).not.toBe(color.surfaceRaised);
    expect(color.surfaceRaised).not.toBe(color.surfaceSunken);
  });

  it('puts a dark label on the amber fill', () => {
    // White on amber-500 is ~1.8:1 and fails; slate-950 on it is ~9:1. The
    // panel's own amber button has dark text.
    expect(color.accentFg).toBe(color.surface);
  });

  it('scales type in five ascending steps', () => {
    const steps = [
      fontSize.micro,
      fontSize.meta,
      fontSize.body,
      fontSize.section,
      fontSize.title,
    ].map(([size]) => parseFloat(size));

    expect(steps).toEqual([...steps].sort((a, b) => a - b));
    expect(new Set(steps).size).toBe(steps.length);
  });

  it('keeps spacing on a 4px rhythm', () => {
    for (const value of Object.values(space)) {
      expect(parseFloat(value) % 4).toBe(0);
    }
  });

  it('names three radius tiers', () => {
    expect(radius.control).toBeLessThan(radius.surface);
    expect(radius.pill).toBeGreaterThan(radius.surface);
  });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd mobile && npx jest src/theme --silent`
Expected: FAIL — `Cannot find module '../tokens'`.

- [ ] **Step 3: Write the token module**

```ts
// mobile/src/theme/tokens.ts
/**
 * The single source of truth for colour, type, spacing and radius.
 *
 * Deliberately plain data with no imports: `tailwind.config.js` requires this
 * file from Node at build time, so anything importing React, NativeWind or a
 * path alias would break the Tailwind build rather than fail a type check.
 *
 * Two consumers, one file. Navigator options (headerStyle,
 * tabBarActiveTintColor, contentStyle) and props like placeholderTextColor and
 * RefreshControl.tintColor take values, not class names, so a palette that
 * lived only in the Tailwind config would land in the classNames and miss
 * every navigator - shipping amber content inside sky-blue chrome.
 */

/**
 * Three surface tiers, mirroring how Filament's light panel raises a white
 * card off a grey shell. The app previously had one step (slate-800 on
 * slate-900), which is why its card density read as flat.
 */
export const color = {
  surface: '#020617', // slate-950 - screen background
  surfaceRaised: '#0f172a', // slate-900 - cards, list rows
  surfaceSunken: '#1e293b', // slate-800 - inputs, inactive chips
  border: '#1e293b', // slate-800 - hairlines
  borderStrong: '#334155', // slate-700 - chip outlines, dividers

  text: '#f8fafc', // slate-50
  textSecondary: '#94a3b8', // slate-400
  textMuted: '#64748b', // slate-500

  /*
   * Amber is the panel's configured primary (AdminPanelProvider sets
   * `'primary' => Color::Amber`). It is the ACTION colour only - no status
   * badge is ever amber, so it never competes with the failure red.
   */
  accent: '#f59e0b', // amber-500 - primary action fills
  accentText: '#fbbf24', // amber-400 - links and accents on dark
  accentFg: '#020617', // the label ON an amber fill; white would be ~1.8:1

  /*
   * Status colours keep the roles StatusBadge already gave them. Sky survives
   * here as "running" and stops being the app's accent.
   */
  success: '#10b981', // emerald-500
  successText: '#6ee7b7', // emerald-300
  danger: '#ef4444', // red-500
  dangerText: '#fca5a5', // red-300
  info: '#0ea5e9', // sky-500
  infoText: '#7dd3fc', // sky-300
} as const;

/** 4px rhythm. Screen padding 4, card padding 3, list gap 2, section gap 6. */
export const space = {
  1: '4px',
  2: '8px',
  3: '12px',
  4: '16px',
  6: '24px',
  8: '32px',
} as const;

/** Three tiers: what kind of thing a surface is, encoded as its corner. */
export const radius = {
  control: 8,
  surface: 12,
  pill: 9999,
} as const;

/**
 * Five steps at roughly a 1.2 ratio, as [size, { lineHeight, letterSpacing?,
 * fontWeight }] - the tuple shape Tailwind's fontSize theme key accepts.
 *
 * Body is 15, not 14: at 14 it blurs into meta at 12. The gap to 13 is what
 * makes a card's title and its byline read as different kinds of information.
 */
export const fontSize = {
  micro: ['11px', { lineHeight: '16px', letterSpacing: '0.02em', fontWeight: '600' }],
  meta: ['13px', { lineHeight: '18px', fontWeight: '400' }],
  body: ['15px', { lineHeight: '22px', fontWeight: '400' }],
  section: ['18px', { lineHeight: '24px', fontWeight: '600' }],
  title: ['24px', { lineHeight: '30px', letterSpacing: '-0.02em', fontWeight: '700' }],
} as const;
```

- [ ] **Step 4: Run the test and watch it pass**

Run: `cd mobile && npx jest src/theme --silent`
Expected: PASS, 6 tests.

- [ ] **Step 5: Wire Tailwind to the same module**

Replace `mobile/tailwind.config.js` entirely:

```js
/** @type {import('tailwindcss').Config} */
// Required, not imported: this file is evaluated by Node during the Tailwind
// build, where the `@/` alias and TS syntax do not exist. src/theme/tokens.ts
// is plain data specifically so this line works.
const { color, fontSize, radius, space } = require('./src/theme/tokens');

module.exports = {
  content: ['./app/**/*.{js,jsx,ts,tsx}', './src/**/*.{js,jsx,ts,tsx}'],
  presets: [require('nativewind/preset')],
  theme: {
    extend: {
      colors: {
        surface: { DEFAULT: color.surface, raised: color.surfaceRaised, sunken: color.surfaceSunken },
        border: { DEFAULT: color.border, strong: color.borderStrong },
        text: { DEFAULT: color.text, secondary: color.textSecondary, muted: color.textMuted },
        accent: { DEFAULT: color.accent, text: color.accentText, fg: color.accentFg },
        success: { DEFAULT: color.success, text: color.successText },
        danger: { DEFAULT: color.danger, text: color.dangerText },
        info: { DEFAULT: color.info, text: color.infoText },
      },
      spacing: space,
      borderRadius: {
        control: `${radius.control}px`,
        surface: `${radius.surface}px`,
        pill: `${radius.pill}px`,
      },
      fontSize,
    },
  },
  plugins: [],
};
```

- [ ] **Step 6: Prove Tailwind can read the module from Node**

Run: `cd mobile && node -e "const c=require('./tailwind.config.js'); const x=c.theme.extend.colors; if(x.surface.DEFAULT!=='#020617') throw new Error('tokens not wired'); console.log('ok')"`
Expected: `ok`.

- [ ] **Step 7: Typecheck, lint, commit**

```bash
cd mobile && npm run typecheck && npm run lint && npx jest src/theme --silent
git add mobile/src/theme mobile/tailwind.config.js
git commit -m "feat(mobile): add the design token module and wire Tailwind to it"
```

---

### Task 2: Button

**Files:**
- Create: `mobile/src/ui/Button.tsx`
- Create: `mobile/src/ui/__tests__/Button.test.tsx`

**Interfaces:**
- Consumes: `color`, `radius` from `@/theme/tokens`.
- Produces: `Button({ label, onPress, variant?, size?, pending?, disabled?, flex? })`
  where `variant: 'primary' | 'secondary' | 'danger-subtle' | 'ghost'` (default
  `'primary'`) and `size: 'md' | 'lg'` (default `'md'`). `flex?: number` sets
  `flexGrow` so callers can weight two buttons in a row.

- [ ] **Step 1: Write the failing test**

```tsx
// mobile/src/ui/__tests__/Button.test.tsx
import { fireEvent, render, screen } from '@testing-library/react-native';

import { Button } from '../Button';

describe('<Button />', () => {
  it('renders its label and calls onPress', () => {
    const onPress = jest.fn();
    render(<Button label="Approve" onPress={onPress} />);

    fireEvent.press(screen.getByText('Approve'));

    expect(onPress).toHaveBeenCalledTimes(1);
  });

  it('swallows presses while pending', () => {
    // A double-tapped Approve would fire two mutations for one image. The
    // pending flag has to disable the press, not merely swap the label.
    const onPress = jest.fn();
    render(<Button label="Approve" onPress={onPress} pending />);

    fireEvent.press(screen.getByLabelText('Approve'));

    expect(onPress).not.toHaveBeenCalled();
  });

  it('keeps the label readable to assistive tech while pending', () => {
    render(<Button label="Approve" onPress={jest.fn()} pending />);

    // The visible label is replaced by a spinner, so the accessible name is
    // the only thing left naming the control.
    expect(screen.getByLabelText('Approve')).toBeTruthy();
  });

  it('marks a disabled button as disabled for assistive tech', () => {
    render(<Button label="Approve" onPress={jest.fn()} disabled />);

    expect(screen.getByLabelText('Approve').props.accessibilityState.disabled).toBe(true);
  });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd mobile && npx jest src/ui/__tests__/Button --silent`
Expected: FAIL — `Cannot find module '../Button'`.

- [ ] **Step 3: Implement**

```tsx
// mobile/src/ui/Button.tsx
import { ActivityIndicator, Pressable, Text } from 'react-native';

import { color } from '@/theme/tokens';

export type ButtonVariant = 'primary' | 'secondary' | 'danger-subtle' | 'ghost';

interface Props {
  label: string;
  onPress: () => void;
  variant?: ButtonVariant;
  size?: 'md' | 'lg';
  pending?: boolean;
  disabled?: boolean;
  /** flexGrow, so a row can weight Approve against Reject. */
  flex?: number;
}

/*
 * `danger-subtle` rather than a filled red: Filament tints destructive actions
 * and fills primary ones, so a filled Reject would carry the same weight as
 * Approve. The asymmetry is the design, not an oversight.
 */
const SHELL: Record<ButtonVariant, string> = {
  primary: 'bg-accent',
  secondary: 'bg-surface-sunken border border-border-strong',
  'danger-subtle': 'bg-danger/10 border border-danger/30',
  ghost: 'bg-transparent',
};

const LABEL: Record<ButtonVariant, string> = {
  primary: 'text-accent-fg',
  secondary: 'text-text',
  'danger-subtle': 'text-danger-text',
  ghost: 'text-accent-text',
};

/** The spinner has to contrast with the shell it sits on, not with the page. */
const SPINNER: Record<ButtonVariant, string> = {
  primary: color.accentFg,
  secondary: color.text,
  'danger-subtle': color.dangerText,
  ghost: color.accentText,
};

export function Button({
  label,
  onPress,
  variant = 'primary',
  size = 'md',
  pending = false,
  disabled = false,
  flex,
}: Props) {
  const inert = pending || disabled;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled: inert, busy: pending }}
      disabled={inert}
      onPress={onPress}
      style={flex === undefined ? undefined : { flexGrow: flex, flexBasis: 0 }}
      className={`items-center justify-center rounded-control ${SHELL[variant]} ${
        size === 'lg' ? 'px-6 py-4' : 'px-4 py-3'
      } ${inert ? 'opacity-60' : 'active:opacity-80'}`}
    >
      {pending ? (
        <ActivityIndicator color={SPINNER[variant]} />
      ) : (
        <Text className={`text-body font-semibold ${LABEL[variant]}`}>{label}</Text>
      )}
    </Pressable>
  );
}
```

- [ ] **Step 4: Run the test and watch it pass**

Run: `cd mobile && npx jest src/ui/__tests__/Button --silent`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
cd mobile && npm run typecheck && npm run lint
git add mobile/src/ui/Button.tsx mobile/src/ui/__tests__/Button.test.tsx
git commit -m "feat(mobile): add the Button primitive with four variants"
```

---

### Task 3: Field

Replaces placeholder-as-label. Filament labels every input above the control;
a placeholder disappears on the first keystroke, taking the label with it.

**Files:**
- Create: `mobile/src/ui/Field.tsx`
- Create: `mobile/src/ui/__tests__/Field.test.tsx`

**Interfaces:**
- Consumes: `color` from `@/theme/tokens`.
- Produces: `Field(props)` where props are
  `{ label: string; value: string; onChangeText: (t: string) => void; hint?: string; error?: string; placeholder?: string }`
  plus any `TextInput` props, forwarded.

- [ ] **Step 1: Write the failing test**

```tsx
// mobile/src/ui/__tests__/Field.test.tsx
import { fireEvent, render, screen } from '@testing-library/react-native';

import { Field } from '../Field';

describe('<Field />', () => {
  it('keeps the label visible after the placeholder is gone', () => {
    // The whole point of the component: a placeholder-as-label vanishes on
    // the first keystroke and the user loses what the box was for.
    const { rerender } = render(
      <Field label="Make" value="" onChangeText={jest.fn()} placeholder="e.g. Toyota" />,
    );
    expect(screen.getByText('Make')).toBeTruthy();

    rerender(<Field label="Make" value="Toyota" onChangeText={jest.fn()} placeholder="e.g. Toyota" />);

    expect(screen.getByText('Make')).toBeTruthy();
    expect(screen.queryByText('e.g. Toyota')).toBeNull();
  });

  it('reports changes', () => {
    const onChangeText = jest.fn();
    render(<Field label="Make" value="" onChangeText={onChangeText} />);

    fireEvent.changeText(screen.getByLabelText('Make'), 'Audi');

    expect(onChangeText).toHaveBeenCalledWith('Audi');
  });

  it('shows the error instead of the hint when both are given', () => {
    // Two lines of small print under one input is noise; the error is the one
    // that needs reading.
    render(
      <Field
        label="From year"
        value="18"
        onChangeText={jest.fn()}
        hint="Four digits"
        error="Enter a four-digit year."
      />,
    );

    expect(screen.getByText('Enter a four-digit year.')).toBeTruthy();
    expect(screen.queryByText('Four digits')).toBeNull();
  });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd mobile && npx jest src/ui/__tests__/Field --silent`
Expected: FAIL — `Cannot find module '../Field'`.

- [ ] **Step 3: Implement**

```tsx
// mobile/src/ui/Field.tsx
import { Text, TextInput, View } from 'react-native';
import type { TextInputProps } from 'react-native';

import { color } from '@/theme/tokens';

interface Props extends Omit<TextInputProps, 'className' | 'placeholderTextColor'> {
  label: string;
  value: string;
  onChangeText: (text: string) => void;
  hint?: string;
  error?: string;
}

export function Field({ label, value, onChangeText, hint, error, ...rest }: Props) {
  return (
    <View className="gap-1">
      <Text className="text-meta font-medium text-text-secondary">{label}</Text>
      <TextInput
        // The label is a sibling <Text>, not a <label for>, so the accessible
        // name has to be set explicitly or the control is announced unnamed.
        accessibilityLabel={label}
        value={value}
        onChangeText={onChangeText}
        placeholderTextColor={color.textMuted}
        className={`rounded-control bg-surface-sunken px-4 py-3 text-body text-text ${
          error ? 'border border-danger/50' : ''
        }`}
        {...rest}
      />
      {error ? (
        <Text className="text-meta text-danger-text">{error}</Text>
      ) : hint ? (
        <Text className="text-meta text-text-muted">{hint}</Text>
      ) : null}
    </View>
  );
}
```

- [ ] **Step 4: Run the test and watch it pass**

Run: `cd mobile && npx jest src/ui/__tests__/Field --silent`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
cd mobile && npm run typecheck && npm run lint
git add mobile/src/ui/Field.tsx mobile/src/ui/__tests__/Field.test.tsx
git commit -m "feat(mobile): add the Field primitive with a persistent label"
```

---

### Task 4: Skeleton and SectionHeading

**Files:**
- Create: `mobile/src/ui/Skeleton.tsx`
- Create: `mobile/src/ui/SectionHeading.tsx`
- Create: `mobile/src/ui/__tests__/Skeleton.test.tsx`

**Interfaces:**
- Produces: `Skeleton({ height?, aspectRatio?, className? })`,
  `SkeletonGrid({ count, numColumns?, aspectRatio? })`,
  `SectionHeading({ title, action? })` where
  `action?: { label: string; onPress: () => void }`.

- [ ] **Step 1: Write the failing test**

```tsx
// mobile/src/ui/__tests__/Skeleton.test.tsx
import { render, screen } from '@testing-library/react-native';

import { Skeleton, SkeletonGrid } from '../Skeleton';

describe('<Skeleton />', () => {
  it('is hidden from assistive tech', () => {
    // A screen reader announcing six empty boxes while a grid loads is worse
    // than silence. The status message below is what should be announced.
    render(<Skeleton height={40} />);

    expect(screen.getByTestId('skeleton').props.accessibilityElementsHidden).toBe(true);
  });

  it('renders one block per requested item', () => {
    render(<SkeletonGrid count={6} />);

    expect(screen.getAllByTestId('skeleton')).toHaveLength(6);
  });

  it('announces that content is loading', () => {
    render(<SkeletonGrid count={6} />);

    expect(screen.getByLabelText('Loading')).toBeTruthy();
  });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd mobile && npx jest src/ui/__tests__/Skeleton --silent`
Expected: FAIL — `Cannot find module '../Skeleton'`.

- [ ] **Step 3: Implement both components**

```tsx
// mobile/src/ui/Skeleton.tsx
import { View } from 'react-native';

/**
 * Static, not shimmering. The design spends its motion budget in exactly one
 * place - the review card leaving when a verdict lands - and a shimmer here
 * would be decoration competing with it.
 */
export function Skeleton({
  height,
  aspectRatio,
  className = '',
}: {
  height?: number;
  aspectRatio?: number;
  className?: string;
}) {
  return (
    <View
      testID="skeleton"
      accessibilityElementsHidden
      importantForAccessibility="no-hide-descendants"
      style={{ height, aspectRatio }}
      className={`rounded-surface bg-surface-raised ${className}`}
    />
  );
}

export function SkeletonGrid({
  count,
  numColumns = 1,
  aspectRatio = 4 / 3,
}: {
  count: number;
  numColumns?: number;
  aspectRatio?: number;
}) {
  return (
    <View
      accessibilityRole="progressbar"
      accessibilityLabel="Loading"
      className="flex-row flex-wrap gap-2"
    >
      {Array.from({ length: count }, (_, index) => (
        <Skeleton
          key={index}
          aspectRatio={aspectRatio}
          className={numColumns > 1 ? 'min-w-[45%] flex-1' : 'w-full'}
        />
      ))}
    </View>
  );
}
```

```tsx
// mobile/src/ui/SectionHeading.tsx
import { Pressable, Text, View } from 'react-native';

/**
 * Sentence case, not tracked-out capitals. The all-caps eyebrow the health
 * screen used is absent from Filament and is a generic template signature;
 * removing it is simultaneously more faithful and less templated.
 */
export function SectionHeading({
  title,
  action,
}: {
  title: string;
  action?: { label: string; onPress: () => void };
}) {
  return (
    <View className="mb-2 flex-row items-center justify-between">
      <Text className="text-section text-text">{title}</Text>
      {action ? (
        <Pressable accessibilityRole="button" onPress={action.onPress} className="active:opacity-80">
          <Text className="text-meta font-medium text-accent-text">{action.label}</Text>
        </Pressable>
      ) : null}
    </View>
  );
}
```

- [ ] **Step 4: Run the test and watch it pass**

Run: `cd mobile && npx jest src/ui/__tests__/Skeleton --silent`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
cd mobile && npm run typecheck && npm run lint
git add mobile/src/ui/Skeleton.tsx mobile/src/ui/SectionHeading.tsx mobile/src/ui/__tests__/Skeleton.test.tsx
git commit -m "feat(mobile): add Skeleton and SectionHeading primitives"
```

---

### Task 5: The Commons title cleaner

`title` arrives as `File:2001 Audi RS4 B5 Avant - Flickr - The Car Spy (3).jpg`.
Raw wiki syntax should not reach a human.

**Files:**
- Create: `mobile/src/format/imageTitle.ts`
- Create: `mobile/src/format/__tests__/imageTitle.test.ts`

**Interfaces:**
- Produces: `cleanTitle(raw: string | null): string | null` and
  `byline(attribution: string | null, title: string | null): string | null`.

- [ ] **Step 1: Write the failing test**

```ts
// mobile/src/format/__tests__/imageTitle.test.ts
import { byline, cleanTitle } from '../imageTitle';

describe('cleanTitle', () => {
  it('strips the File: prefix, the extension and the disambiguator', () => {
    expect(cleanTitle('File:2001 Audi RS4 B5 Avant - Flickr - The Car Spy (3).jpg')).toBe(
      '2001 Audi RS4 B5 Avant - Flickr - The Car Spy',
    );
  });

  it('turns underscores back into spaces', () => {
    // Commons page titles use underscores where the URL form does.
    expect(cleanTitle('File:1998_Toyota_Corolla.jpg')).toBe('1998 Toyota Corolla');
  });

  it('leaves a title that is already clean alone', () => {
    expect(cleanTitle('A red car')).toBe('A red car');
  });

  it('keeps a year in brackets that is not a disambiguator', () => {
    // Only a bare integer in trailing parentheses is Commons' duplicate
    // marker. Stripping every trailing bracket would eat real content.
    expect(cleanTitle('File:Audi at the show (Geneva).jpg')).toBe('Audi at the show (Geneva)');
  });

  it('handles an uppercase extension', () => {
    expect(cleanTitle('File:Audi A4.JPEG')).toBe('Audi A4');
  });

  it('passes null through', () => {
    expect(cleanTitle(null)).toBeNull();
  });

  it('returns null rather than an empty string when nothing survives', () => {
    // An empty string would render as a blank line where a title should be.
    expect(cleanTitle('File:.jpg')).toBeNull();
  });
});

describe('byline', () => {
  it('prefers the attribution when there is one', () => {
    expect(byline('The Car Spy', 'File:Audi.jpg')).toBe('The Car Spy');
  });

  it('falls back to the cleaned title', () => {
    expect(byline(null, 'File:1998_Toyota_Corolla.jpg')).toBe('1998 Toyota Corolla');
  });

  it('is null when there is nothing to say', () => {
    expect(byline(null, null)).toBeNull();
  });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd mobile && npx jest src/format --silent`
Expected: FAIL — `Cannot find module '../imageTitle'`.

- [ ] **Step 3: Implement**

```ts
// mobile/src/format/imageTitle.ts
/**
 * Wikimedia Commons file titles, made fit for a human.
 *
 * The API returns the page title verbatim - `File:` prefix, underscores,
 * extension and Commons' ` (n)` duplicate marker included. Pure functions so
 * they are unit-testable without mounting anything.
 */

/** Commons' duplicate marker is a bare integer; `(Geneva)` is real content. */
const DISAMBIGUATOR = /\s*\(\d+\)$/;
const EXTENSION = /\.(jpe?g|png|gif|webp|tiff?|svg)$/i;
const PREFIX = /^file:/i;

export function cleanTitle(raw: string | null): string | null {
  if (raw === null) return null;

  const cleaned = raw
    .replace(PREFIX, '')
    .replace(EXTENSION, '')
    .replace(DISAMBIGUATOR, '')
    .replace(/_/g, ' ')
    .trim();

  return cleaned === '' ? null : cleaned;
}

/** The line under a card's heading: who took it, else what it is called. */
export function byline(attribution: string | null, title: string | null): string | null {
  const attributed = attribution?.trim();

  if (attributed) return attributed;

  return cleanTitle(title);
}
```

- [ ] **Step 4: Run the test and watch it pass**

Run: `cd mobile && npx jest src/format --silent`
Expected: PASS, 10 tests.

- [ ] **Step 5: Commit**

```bash
cd mobile && npm run typecheck && npm run lint
git add mobile/src/format
git commit -m "feat(mobile): clean Commons file titles for display"
```

---

### Task 6: VerdictBadge, and the existing primitives on tokens

`make_confirmed` and `year_confirmed` are already on the wire and already in the
Zod schema — fetched on every card and discarded. Rendering them is what makes
the reviewer's judgement informed rather than a coin flip.

**Files:**
- Create: `mobile/src/ui/VerdictBadge.tsx`
- Create: `mobile/src/ui/__tests__/VerdictBadge.test.tsx`
- Modify: `mobile/src/ui/StatusBadge.tsx`, `EmptyState.tsx`, `ErrorBanner.tsx`,
  `StatTile.tsx`, `Screen.tsx`, `ImageCard.tsx`

**Interfaces:**
- Produces: `VerdictBadge({ kind, value })` where `kind: 'make' | 'year'` and
  `value: boolean | null`.
- `EmptyState` gains `icon?: ReactNode` and `action?: { label, onPress }`.
- `StatTile` gains `onPress?: () => void`.
- `Screen` gains `title?: string`.

- [ ] **Step 1: Write the failing test**

```tsx
// mobile/src/ui/__tests__/VerdictBadge.test.tsx
import { render, screen } from '@testing-library/react-native';

import { VerdictBadge } from '../VerdictBadge';

describe('<VerdictBadge />', () => {
  it('reads true as a match', () => {
    render(<VerdictBadge kind="make" value={true} />);
    expect(screen.getByText('make matched')).toBeTruthy();
  });

  it('reads false as no match', () => {
    render(<VerdictBadge kind="make" value={false} />);
    expect(screen.getByText('make not matched')).toBeTruthy();
  });

  it('distinguishes unknown from not-matched', () => {
    // null is "the checker never ran", which is a different fact from "the
    // checker ran and said no". Collapsing them would misreport the machine.
    render(<VerdictBadge kind="year" value={null} />);
    expect(screen.getByText('year unknown')).toBeTruthy();
  });

  it('names the year kind', () => {
    render(<VerdictBadge kind="year" value={true} />);
    expect(screen.getByText('year matched')).toBeTruthy();
  });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd mobile && npx jest src/ui/__tests__/VerdictBadge --silent`
Expected: FAIL — `Cannot find module '../VerdictBadge'`.

- [ ] **Step 3: Implement VerdictBadge**

```tsx
// mobile/src/ui/VerdictBadge.tsx
import { Text, View } from 'react-native';

/**
 * The relevance checker's verdict, shown read-only beside the human's.
 *
 * Never writable. `make_confirmed` and `year_confirmed` are written in exactly
 * one place server-side (CarImageSearchService, via MakeRelevanceChecker), and
 * human review lives in `review_status` precisely so the machine-versus-human
 * comparison stays answerable. A client that could write these would destroy
 * the only record of what the machine thought.
 */
type Verdict = 'matched' | 'not matched' | 'unknown';

/*
 * Two maps rather than one combined string, for the reason StatusBadge already
 * documents: the background belongs to the pill and the colour to the label,
 * and applying one combined class to both paints a second background behind
 * the text.
 */
const SHELL: Record<Verdict, string> = {
  matched: 'bg-success/15',
  'not matched': 'bg-danger/15',
  unknown: 'bg-surface-sunken',
};

const LABEL: Record<Verdict, string> = {
  matched: 'text-success-text',
  'not matched': 'text-danger-text',
  unknown: 'text-text-secondary',
};

export function VerdictBadge({ kind, value }: { kind: 'make' | 'year'; value: boolean | null }) {
  // null is not false: it means the checker never reached a conclusion.
  const verdict: Verdict = value === null ? 'unknown' : value ? 'matched' : 'not matched';

  return (
    <View className={`self-start rounded-pill px-2 py-0.5 ${SHELL[verdict]}`}>
      <Text className={`text-micro ${LABEL[verdict]}`}>{`${kind} ${verdict}`}</Text>
    </View>
  );
}
```

- [ ] **Step 4: Run the test and watch it pass**

Run: `cd mobile && npx jest src/ui/__tests__/VerdictBadge --silent`
Expected: PASS, 4 tests.

- [ ] **Step 5: Move the six existing primitives onto tokens**

Mechanical, one file at a time. In every case the class names change and the
behaviour does not, so the existing tests must keep passing untouched.

- `StatusBadge.tsx` — tone map values become `bg-success/15` /
  `text-success-text` and siblings; `rounded-full` becomes `rounded-pill`;
  `text-xs font-medium` becomes `text-micro`. **Keep the unknown-status
  fallback to neutral.**
- `EmptyState.tsx` — add `icon?: ReactNode` above the title and
  `action?: { label: string; onPress: () => void }` rendered as a
  `<Button variant="secondary">` below the hint. Title becomes `text-section
  text-text`, hint `text-meta text-text-secondary`.
- `ErrorBanner.tsx` — `rounded-lg` becomes `rounded-surface` (it is a surface,
  not a control); colours become `border-danger/40 bg-danger/10 text-danger-text`;
  `text-sm` becomes `text-body`. Add `accessibilityRole="alert"`.
- `StatTile.tsx` — `rounded-xl` becomes `rounded-surface`, `bg-slate-800`
  becomes `bg-surface-raised`, value becomes `text-title text-text`, label
  becomes `text-meta text-text-secondary`. Add optional `onPress` wrapping the
  tile in a `Pressable` when supplied.
- `Screen.tsx` — `bg-slate-900` becomes `bg-surface`; padding becomes `p-4`
  (16px, unchanged in value, now on the scale); add optional `title` rendered
  as `<Text className="mb-4 text-title text-text">`.
- `ImageCard.tsx` — shell becomes `rounded-surface bg-surface-raised`; the name
  line becomes `text-body font-medium text-text`; add a `byline` line under it
  using `byline(image.attribution, image.title)` from Task 5, at
  `text-meta text-text-muted`, rendered only when non-null.

- [ ] **Step 6: Run the whole suite**

Run: `cd mobile && npm test`
Expected: PASS. `InfiniteGrid.test.tsx` and `health.test.tsx` must be green
**without being edited** — they assert behaviour, not class names. If either
fails, the change altered behaviour and must be corrected rather than the test.

- [ ] **Step 7: Commit**

```bash
cd mobile && npm run typecheck && npm run lint && npm test
git add mobile/src/ui
git commit -m "feat(mobile): add VerdictBadge and move the primitives onto tokens"
```

---

### Task 7: The review card

The one place boldness is spent: the only interaction the panel cannot do, and
currently the weakest thing on screen. Extracted from the screen so it is
mountable without a router harness.

**Files:**
- Create: `mobile/src/ui/ReviewCard.tsx`
- Create: `mobile/src/ui/__tests__/ReviewCard.test.tsx`
- Modify: `mobile/app/(app)/review/index.tsx`

**Interfaces:**
- Consumes: `VerdictBadge` (Task 6), `Button` (Task 2), `byline`/`cleanTitle`
  (Task 5).
- Produces: `ReviewCard({ image, onApprove, onReject, pending? })` where
  `image: Image` from `@/api/schemas` and the callbacks take no arguments.

- [ ] **Step 1: Write the failing test**

```tsx
// mobile/src/ui/__tests__/ReviewCard.test.tsx
import { fireEvent, render, screen } from '@testing-library/react-native';

import type { Image as CarImage } from '@/api/schemas';
import { ReviewCard } from '../ReviewCard';

const image: CarImage = {
  id: 1,
  car_search_id: 2,
  make: 'Audi',
  model: 'A4 Avant quattro',
  year: 2001,
  color: null,
  title: 'File:2001 Audi RS4 B5 Avant - Flickr - The Car Spy (3).jpg',
  description: null,
  source_url: 'https://upload.wikimedia.org/a.jpg',
  thumbnail_url: null,
  width: 800,
  height: 600,
  license: 'CC BY 2.0',
  attribution: 'The Car Spy',
  make_confirmed: true,
  year_confirmed: null,
  review_status: 'pending',
  reviewed_by: null,
  reviewed_at: null,
  download_status: 'not_downloaded',
  created_at: '2026-09-01T00:00:00+00:00',
};

describe('<ReviewCard />', () => {
  it("shows the machine's verdict so the human can disagree with it", () => {
    // The reason review_status is a separate column is that the two verdicts
    // stay comparable. A reviewer who cannot see the machine's answer is not
    // comparing anything.
    render(<ReviewCard image={image} onApprove={jest.fn()} onReject={jest.fn()} />);

    expect(screen.getByText('make matched')).toBeTruthy();
    expect(screen.getByText('year unknown')).toBeTruthy();
  });

  it('shows a cleaned title, never raw Commons syntax', () => {
    render(<ReviewCard image={image} onApprove={jest.fn()} onReject={jest.fn()} />);

    expect(screen.queryByText(/^File:/)).toBeNull();
    expect(screen.getByText('2001 Audi A4 Avant quattro')).toBeTruthy();
  });

  it('reports each verdict', () => {
    const onApprove = jest.fn();
    const onReject = jest.fn();
    render(<ReviewCard image={image} onApprove={onApprove} onReject={onReject} />);

    fireEvent.press(screen.getByLabelText('Approve'));
    expect(onApprove).toHaveBeenCalledTimes(1);

    fireEvent.press(screen.getByLabelText('Reject'));
    expect(onReject).toHaveBeenCalledTimes(1);
  });

  it('locks both verdicts while one is in flight', () => {
    const onApprove = jest.fn();
    render(<ReviewCard image={image} onApprove={onApprove} onReject={jest.fn()} pending />);

    fireEvent.press(screen.getByLabelText('Approve'));

    expect(onApprove).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd mobile && npx jest src/ui/__tests__/ReviewCard --silent`
Expected: FAIL — `Cannot find module '../ReviewCard'`.

- [ ] **Step 3: Implement**

```tsx
// mobile/src/ui/ReviewCard.tsx
import { Image } from 'expo-image';
import { Text, View } from 'react-native';

import type { Image as CarImage } from '@/api/schemas';
import { byline } from '@/format/imageTitle';
import { Button } from './Button';
import { VerdictBadge } from './VerdictBadge';

/**
 * Presentational on purpose: no hooks, no router, no mutation. That is what
 * makes it mountable in a test, which the screen around it is not.
 *
 * Approve is the primary action and Reject is tinted, so the constructive
 * verdict carries the weight. Approve being amber while the resulting badge is
 * emerald is deliberate: amber is the primary-ACTION colour and emerald is the
 * approved-STATE colour, exactly as the panel's amber "Create" button yields a
 * green "completed" badge.
 */
export function ReviewCard({
  image,
  onApprove,
  onReject,
  pending = false,
}: {
  image: CarImage;
  onApprove: () => void;
  onReject: () => void;
  pending?: boolean;
}) {
  const name = [image.year, image.make, image.model].filter(Boolean).join(' ');
  const credit = byline(image.attribution, image.title);

  return (
    <View className="mb-3 overflow-hidden rounded-surface bg-surface-raised">
      <View>
        <Image
          source={image.thumbnail_url ?? image.source_url}
          style={{ width: '100%', aspectRatio: 4 / 3 }}
          contentFit="cover"
          transition={150}
          cachePolicy="disk"
          accessibilityLabel={name}
        />
        {/* Over the image rather than under the title: the verdict is about
            what is in the picture, and reading it next to the picture is the
            comparison the reviewer is being asked to make. */}
        <View className="absolute bottom-2 left-2 flex-row gap-1">
          <VerdictBadge kind="make" value={image.make_confirmed} />
          <VerdictBadge kind="year" value={image.year_confirmed} />
        </View>
      </View>

      <View className="gap-3 p-3">
        <View className="gap-1">
          <Text className="text-section text-text" numberOfLines={1}>
            {name}
          </Text>
          {credit ? (
            <Text className="text-meta text-text-muted" numberOfLines={1}>
              {credit}
            </Text>
          ) : null}
        </View>

        <View className="flex-row gap-2">
          <Button label="Approve" onPress={onApprove} pending={pending} flex={2} />
          <Button label="Reject" onPress={onReject} variant="danger-subtle" disabled={pending} flex={1} />
        </View>
      </View>
    </View>
  );
}
```

- [ ] **Step 4: Run the test and watch it pass**

Run: `cd mobile && npx jest src/ui/__tests__/ReviewCard --silent`
Expected: PASS, 4 tests.

- [ ] **Step 5: Use it in the review screen**

In `mobile/app/(app)/review/index.tsx`, replace the inline `renderItem` markup
with `<ReviewCard>`, keeping the existing `useReviewQueueImages` / `useReviewImage`
wiring and the existing error banner exactly as they are:

```tsx
renderItem={(image: CarImage) => (
  <ReviewCard
    image={image}
    pending={review.isPending && review.variables?.id === image.id}
    onApprove={() => review.mutate({ id: image.id, review_status: 'approved' })}
    onReject={() => review.mutate({ id: image.id, review_status: 'rejected' })}
  />
)}
```

Replace the screen's heading and description with `<Screen title="Review queue">`
plus a single `text-meta text-text-secondary` line, and delete the now-duplicated
`<Text className="mb-1 text-xl font-bold text-white">`.

- [ ] **Step 6: Run the whole suite**

Run: `cd mobile && npm test`
Expected: PASS. `useReviewImage.test.tsx` and `useReviewQueueImages.test.tsx`
must be untouched and green — the optimistic-removal behaviour they cover is
not changed by this task.

- [ ] **Step 7: Commit**

```bash
cd mobile && npm run typecheck && npm run lint && npm test
git add mobile/src/ui/ReviewCard.tsx mobile/src/ui/__tests__/ReviewCard.test.tsx "mobile/app/(app)/review/index.tsx"
git commit -m "feat(mobile): show the machine verdict on a reweighted review card"
```

---

### Task 8: Skeletons in InfiniteGrid

**Files:**
- Modify: `mobile/src/ui/InfiniteGrid.tsx`
- Modify: `mobile/src/ui/__tests__/InfiniteGrid.test.tsx`

**Interfaces:**
- Consumes: `SkeletonGrid` (Task 4), `color` (Task 1).
- Produces: no signature change. `InfiniteGrid`'s props are unchanged.

- [ ] **Step 1: Add the failing test to the existing file**

```tsx
  it('shows skeletons rather than a bare spinner on first load', () => {
    const { getByLabelText, queryByText } = grid({ ...base, isLoading: true });

    expect(getByLabelText('Loading')).toBeTruthy();
    // The empty state must not flash before the first page arrives.
    expect(queryByText('No images match')).toBeNull();
  });

  it('keeps the skeleton count in step with the column count', () => {
    const { getAllByTestId } = grid({ ...base, isLoading: true });

    // Enough to fill a phone screen; too few reads as a broken layout.
    expect(getAllByTestId('skeleton').length).toBeGreaterThanOrEqual(4);
  });
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd mobile && npx jest src/ui/__tests__/InfiniteGrid --silent`
Expected: FAIL — `Unable to find an element with accessibility label: Loading`.

- [ ] **Step 3: Implement**

In `InfiniteGrid.tsx`, replace

```tsx
  if (query.isLoading) {
    return <ActivityIndicator className="mt-8" color="#38bdf8" />;
  }
```

with

```tsx
  if (query.isLoading) {
    // Shaped like the content it replaces, so the layout does not jump when
    // the first page lands. Six fills a phone screen at either column count.
    return <SkeletonGrid count={6} numColumns={numColumns} />;
  }
```

and replace the two remaining hardcoded `#38bdf8` values with `color.accentText`
(`RefreshControl.tintColor` and the footer `ActivityIndicator`). Import
`SkeletonGrid` from `./Skeleton` and `color` from `@/theme/tokens`.

- [ ] **Step 4: Run the test and watch it pass**

Run: `cd mobile && npx jest src/ui/__tests__/InfiniteGrid --silent`
Expected: PASS, 5 tests — the three existing ones plus the two new.

- [ ] **Step 5: Commit**

```bash
cd mobile && npm run typecheck && npm run lint && npm test
git add mobile/src/ui/InfiniteGrid.tsx mobile/src/ui/__tests__/InfiniteGrid.test.tsx
git commit -m "feat(mobile): replace the grid's loading spinner with skeletons"
```

---

### Task 9: The navigation restructure

Four tabs before, four after — but not the same four. The image grid is
currently reachable only through a text link at the bottom of the search form;
it is the panel's most-used page. The Runs tab's screens move into the Search
stack rather than being deleted, so CSV-derived runs stay reachable until P3
splits ad-hoc from CSV-derived.

**Files:**
- Install: `@expo/vector-icons`
- Create: `mobile/src/screens/ImageDetailScreen.tsx`
- Create: `mobile/app/(app)/library/_layout.tsx`, `library/index.tsx`, `library/[id].tsx`
- Create: `mobile/app/(app)/search/runs/index.tsx`, `search/runs/[id].tsx`
- Delete: `mobile/app/(app)/runs/` (whole directory), `mobile/app/(app)/search/images.tsx`
- Modify: `mobile/app/(app)/_layout.tsx`, `search/_layout.tsx`, `search/[id].tsx`,
  `search/index.tsx`, `app/(app)/__tests__/layout.test.tsx`

**Interfaces:**
- Produces: `ImageDetailScreen({ id }: { id: number })` — the image detail body,
  taking its id as a prop rather than reading `useLocalSearchParams`, so both
  route shells can mount it and a test can too.

- [ ] **Step 1: Install the icon package**

Run: `cd mobile && npx expo install @expo/vector-icons`

`expo install` rather than `npm install` so the version is the one pinned to
SDK 57. Confirm it landed in `mobile/package.json` and **not** in the repository
root's.

Run: `cd /home/allan/code/laravel/cars-images-api && git diff --name-only package.json`
Expected: empty output.

- [ ] **Step 2: Update the layout test for the new tab set**

In `mobile/app/(app)/__tests__/layout.test.tsx`, change the tab list assertion:

```tsx
    for (const tab of ['Search', 'Library', 'Review', 'Health']) {
      expect(screen.getByText(tab)).toBeTruthy();
    }
```

and add:

```tsx
  it('gives every tab an icon', () => {
    // The live build shipped placeholder glyphs because Tabs.Screen carried
    // only a title. A tab bar of four unlabelled triangles is the first thing
    // a visitor sees.
    signedIn('authenticated');

    render(<AppLayout />);

    expect(screen.getAllByTestId('tab-icon')).toHaveLength(4);
  });
```

The `MockTabsScreen` stand-in must be extended to render the icon so this can
be asserted:

```tsx
function MockTabsScreen({
  options,
}: {
  options?: { title?: string; tabBarIcon?: (p: { color: string; focused: boolean }) => ReactNode };
}) {
  return (
    <>
      <Text>{options?.title ?? ''}</Text>
      {options?.tabBarIcon?.({ color: '#ffffff', focused: false })}
    </>
  );
}
```

- [ ] **Step 3: Run it and watch it fail**

Run: `cd mobile && npx jest "app/(app)/__tests__/layout" --silent`
Expected: FAIL — `Unable to find an element with text: Library`.

- [ ] **Step 4: Rewrite the tab layout**

`mobile/app/(app)/_layout.tsx`: replace the four `Tabs.Screen` entries with
Search / Library / Review / Health, give each a `tabBarIcon`, and take every
colour from tokens instead of the hex literals.

```tsx
import Ionicons from '@expo/vector-icons/Ionicons';
import { Redirect, Tabs } from 'expo-router';
import { ActivityIndicator, View } from 'react-native';

import { useAuth } from '@/auth/AuthContext';
import { color } from '@/theme/tokens';
import { PageTitle } from '@/ui/PageTitle';

/*
 * Icon names track the panel's own $navigationIcon declarations, so the two
 * clients share one vocabulary: Results is heroicon-o-photo, the error log is
 * a chart, and so on.
 */
const ICON = {
  search: 'search',
  library: 'images',
  review: 'checkmark-circle',
  health: 'pulse',
} as const;

function TabIcon({ name, color: tint, focused }: { name: keyof typeof ICON; color: string; focused: boolean }) {
  return (
    <Ionicons
      testID="tab-icon"
      name={focused ? ICON[name] : (`${ICON[name]}-outline` as const)}
      size={24}
      color={tint}
    />
  );
}
```

with the navigator itself using
`headerStyle: { backgroundColor: color.surfaceRaised }`,
`headerTintColor: color.text`,
`tabBarStyle: { backgroundColor: color.surfaceRaised, borderTopColor: color.border }`,
`tabBarActiveTintColor: color.accentText`,
`tabBarInactiveTintColor: color.textSecondary`, and the loading branch's
`ActivityIndicator color={color.accentText}`.

Search and Library both nest a Stack, so both keep `headerShown: false`.

- [ ] **Step 5: Extract the image detail screen**

Create `mobile/src/screens/ImageDetailScreen.tsx` holding the body of the
current `app/(app)/search/[id].tsx`, changed in three ways: it takes
`{ id }: { id: number }` instead of calling `useLocalSearchParams`; its `Row`
label drops `uppercase tracking-wide` for `text-meta text-text-secondary`; and
every remaining colour comes from tokens. Keep the existing "Machine verdict"
row — it is already correct — but render it with two `<VerdictBadge>`s instead
of the `describe()` string, and delete `describe()`.

Then both route shells become four lines each:

```tsx
// mobile/app/(app)/search/[id].tsx  and  mobile/app/(app)/library/[id].tsx
import { useLocalSearchParams } from 'expo-router';

import { ImageDetailScreen } from '@/screens/ImageDetailScreen';

/*
 * Two shells over one screen. Each tab owns its own stack, so a card in the
 * Library tab must push onto the Library stack and a run's image onto the
 * Search stack - otherwise the back gesture returns to the wrong tab.
 */
export default function ImageDetailRoute() {
  const { id } = useLocalSearchParams<{ id: string }>();

  return <ImageDetailScreen id={Number(id)} />;
}
```

- [ ] **Step 6: Move the routes**

```bash
cd /home/allan/code/laravel/cars-images-api/mobile
mkdir -p "app/(app)/library" "app/(app)/search/runs"
git mv "app/(app)/search/images.tsx" "app/(app)/library/index.tsx"
git mv "app/(app)/runs/index.tsx" "app/(app)/search/runs/index.tsx"
git mv "app/(app)/runs/[id].tsx" "app/(app)/search/runs/[id].tsx"
git rm -r "app/(app)/runs"
```

`runs/image/[id].tsx` goes with the directory: run images now link to
`/(app)/search/[id]`, which is in the same stack, so the re-export that existed
only to keep the back gesture inside the tab is no longer needed.

Then update every route literal:

| File | Was | Becomes |
|---|---|---|
| `search/runs/index.tsx` | `/(app)/runs/[id]` | `/(app)/search/runs/[id]` |
| `search/runs/[id].tsx` | `/(app)/runs/image/[id]` | `/(app)/search/[id]` |
| `search/index.tsx` | `/(app)/runs/[id]` (twice) | `/(app)/search/runs/[id]` |
| `search/index.tsx` | `router.push('/(app)/search/images')` | delete the link — Library is a tab now |
| `library/index.tsx` | `/(app)/search/[id]` | `/(app)/library/[id]` |

- [ ] **Step 7: Write the two stack layouts**

`mobile/app/(app)/search/_layout.tsx` gains the two moved screens and takes its
colours from tokens:

```tsx
      <Stack.Screen name="index" options={{ title: 'Search' }} />
      <Stack.Screen name="runs/index" options={{ title: 'Runs' }} />
      <Stack.Screen name="runs/[id]" options={{ title: 'Run' }} />
      <Stack.Screen name="[id]" options={{ title: 'Image' }} />
```

`mobile/app/(app)/library/_layout.tsx` is new and mirrors it:

```tsx
import { Stack } from 'expo-router';

import { color } from '@/theme/tokens';

/**
 * Without this layout expo-router flattens `library/` into the parent Tabs
 * navigator and `[id]` becomes a tab of its own.
 */
export default function LibraryLayout() {
  return (
    <Stack
      screenOptions={{
        headerStyle: { backgroundColor: color.surfaceRaised },
        headerTintColor: color.text,
        contentStyle: { backgroundColor: color.surface },
      }}
    >
      <Stack.Screen name="index" options={{ title: 'Library' }} />
      <Stack.Screen name="[id]" options={{ title: 'Image' }} />
    </Stack>
  );
}
```

- [ ] **Step 8: Run the suite and the typecheck**

Run: `cd mobile && npm run typecheck && npm test`
Expected: PASS. A `typecheck` failure naming a route string means a literal in
the table above was missed.

- [ ] **Step 9: Prove the web build still exports**

Run: `cd mobile && npm run build:web`
Expected: exits 0. Then confirm the route manifest has the new shape:

Run: `cd mobile && node -e "const r=require('./dist/_expo/.routes.json'); const p=JSON.stringify(r); if(p.includes('(app)/runs')) throw new Error('stale runs route exported'); if(!p.includes('library')) throw new Error('library route missing'); console.log('routes ok')"`
Expected: `routes ok`.

- [ ] **Step 10: Commit**

```bash
cd mobile && npm run lint
git add -A mobile/app mobile/src/screens mobile/package.json mobile/package-lock.json
git commit -m "feat(mobile): restructure the tabs and give the tab bar icons"
```

---

### Task 10: The remaining screens on tokens

Everything left that still carries a hex literal, an ad-hoc type size or an
all-caps eyebrow.

**Files:**
- Modify: `mobile/app/login.tsx`, `mobile/app/index.tsx`,
  `mobile/app/(app)/search/index.tsx`, `mobile/app/(app)/library/index.tsx`,
  `mobile/app/(app)/search/runs/index.tsx`, `mobile/app/(app)/search/runs/[id].tsx`,
  `mobile/app/(app)/health/index.tsx`

**Interfaces:**
- Consumes: `Button` (2), `Field` (3), `SectionHeading` (4), `Screen`/`EmptyState`/
  `StatTile` (6), `color` (1).
- Produces: nothing new.

- [ ] **Step 1: Confirm no hex literal survives**

Run: `cd mobile && grep -rn "#[0-9a-fA-F]\{6\}" app src --include=*.tsx --include=*.ts | grep -v "src/theme/tokens.ts" | grep -v __tests__`
Expected after this task: empty. Run it **now** to get the worklist.

- [ ] **Step 2: Rework login**

Replace the two `TextInput`s with `<Field label="Email" …>` and
`<Field label="Password" …>`, the `Pressable` with
`<Button label="Sign in" onPress={submit} pending={busy} size="lg" />`, the
heading with `text-title text-text`, and the footnote with
`text-meta text-text-muted`. Keep the `Redirect` guard, the `DEVICE_NAME`
constant and the `ApiValidationError` handling exactly as they are.

- [ ] **Step 3: Rework the landing screen**

`app/index.tsx`: `bg-slate-900` becomes `bg-surface`, the `BUTTON` constant's
`bg-sky-500` becomes `bg-accent` with `text-accent-fg`, the heading becomes
`text-title`, the paragraph `text-body text-text-secondary`, and the
`ActivityIndicator` takes `color.accentText`. Keep the three-way `status`
branching and its comment intact.

- [ ] **Step 4: Rework the search form**

Four `TextInput`s become `<Field>`s with labels Make / Model / From year /
To year, the model field carrying `hint="Optional"`. The submit `Pressable`
becomes `<Button label="Run search" pending={createSearch.isPending} size="lg" />`.
The amber notice keeps its shape but takes `border-accent/40 bg-accent/10` and
`text-accent-text`. Delete the "Browse all images" `Pressable` — Library is a
tab now. Keep every validation branch and its comments unchanged.

- [ ] **Step 5: Rework the filter chips**

In `library/index.tsx` and `search/runs/index.tsx` the chips become
`rounded-pill px-3 py-1` with `bg-accent` + `text-accent-fg` when selected and
`bg-surface-sunken` + `text-text-secondary` when not, label at `text-micro`.
Add `accessibilityRole="button"` and `accessibilityState={{ selected }}`.
The `library/index.tsx` search box becomes a `<Field label="Make">` carrying
the existing exact-match hint as its `hint`.

- [ ] **Step 6: Rework health**

Delete all three `text-xs uppercase tracking-wide` eyebrows and replace them
with `<SectionHeading title="Runs" />`, `title="Recent"`,
`title="Errors by context, 7 days"` and `title="Error log"`. Replace the two
`ActivityIndicator`s with `<SkeletonGrid count={4} numColumns={2} />` and a
`<Skeleton height={72} />` respectively. The "Load more" `Pressable` becomes
`<Button label="Load more" variant="secondary" pending={errors.isFetchingNextPage} />`
and the sign-out `Pressable` becomes
`<Button label="Sign out" variant="ghost" onPress={() => void signOut()} />`.
The error-log rows become `rounded-surface bg-surface-raised p-3` with the
context at `text-micro text-accent-text` and the timestamp at
`text-micro text-text-muted`.

**Do not change the compound loading guard** on the empty-log line — its test
exists precisely to stop that simplification.

- [ ] **Step 7: Rework the run screens**

`search/runs/[id].tsx`: the two `ActivityIndicator` early returns become
`<Skeleton height={96} />`; the heading becomes `text-section text-text`; the
failure note becomes `text-meta text-danger-text`. The row card in
`search/runs/index.tsx` becomes `rounded-surface bg-surface-raised p-3` with the
name at `text-body font-medium text-text`.

- [ ] **Step 8: Re-run the grep from step 1**

Run: `cd mobile && grep -rn "#[0-9a-fA-F]\{6\}" app src --include=*.tsx --include=*.ts | grep -v "src/theme/tokens.ts" | grep -v __tests__`
Expected: empty.

Also confirm the eyebrows are gone:

Run: `cd mobile && grep -rn "uppercase" app src --include=*.tsx | grep -v __tests__`
Expected: empty.

- [ ] **Step 9: Run everything and commit**

```bash
cd mobile && npm run typecheck && npm run lint && npm test
git add -A mobile/app
git commit -m "refactor(mobile): move every screen onto the design tokens"
```

---

### Task 11: Verification and cleanup

**Files:**
- Delete: `.playwright-mcp/`, `mobile-landing.png` (screenshot scratch from the
  design session, untracked and repo-root)
- Modify: `.gitignore`

- [ ] **Step 1: Remove the scratch artefacts and ignore the tool's output**

```bash
cd /home/allan/code/laravel/cars-images-api
rm -rf .playwright-mcp mobile-landing.png
printf '\n# Playwright MCP screenshot scratch\n/.playwright-mcp/\n' >> .gitignore
```

- [ ] **Step 2: Run the full mobile gate**

Run: `cd mobile && npm run typecheck && npm run lint && npm test && npm run build:web`
Expected: all four exit 0.

- [ ] **Step 3: Run the Laravel gate**

This plan touches no PHP, so both must be green without any change having been
made to them. Running them is what proves that.

Run: `cd /home/allan/code/laravel/cars-images-api && vendor/bin/pint --test`
Expected: exit 0.

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test`
Expected: exit 0, no failures.

- [ ] **Step 4: Commit the cleanup**

```bash
git add .gitignore
git commit -m "chore: ignore the Playwright MCP screenshot scratch directory"
```

- [ ] **Step 5: Report**

State plainly which of the five commands in steps 2–3 passed, with output. Do
not claim completion for any that were not run.

---

## Self-Review

**Spec coverage.** Token module → Task 1. Colour, type, spacing, radius tiers →
Task 1. New primitives `Button`/`Field`/`Skeleton`/`SectionHeading` → Tasks 2–4.
Reworked primitives → Task 6. Review card with verdict badges and asymmetric
actions → Task 7. Title cleaning → Task 5. Skeletons replacing spinners →
Tasks 8 and 10. Empty states gaining icon and action → Task 6 step 5. Labels
above inputs → Tasks 3 and 10. All-caps eyebrows deleted → Task 10 steps 6 and 8.
Tab icons → Task 9. Four-tab restructure with Library promoted and runs moved
into the Search stack → Task 9. No screen titling itself twice → Tasks 6
(`Screen` gains `title`), 7 and 10. Motion budget → Task 4's static skeleton
comment; the review card's exit animation is **not** implemented in this plan
and is noted below.

**Gap found and accepted.** The spec's "one motion moment — the review card
animating out when a verdict lands" is not a task here. `useReviewQueueImages`
already removes the card optimistically, so the row disappears immediately; the
animation would be a `Layout` transition from `react-native-reanimated`, which
is not installed and would be a second new dependency in a plan that already
adds one. **Deferred, and the spec's Motion section should be read as
describing the intended end state rather than P1's scope.** Raise it as its own
change once Reanimated arrives with P3's progress bar.

**Type consistency.** `Button`'s `flex` prop is declared in Task 2 and used in
Task 7. `VerdictBadge({ kind, value })` is declared in Task 6 and used in
Tasks 7 and 9. `byline(attribution, title)` is declared in Task 5 and used in
Tasks 6 and 7. `SkeletonGrid({ count, numColumns })` is declared in Task 4 and
used in Tasks 8 and 10. `ImageDetailScreen({ id })` is declared in Task 9 and
used by both route shells in the same task.

**Placeholder scan.** Clean. No `TBD`, no "add error handling", no "similar to
Task N", and every code step carries the code it describes.
