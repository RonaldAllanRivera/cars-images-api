import { useState } from 'react';
import { Text, View } from 'react-native';

import { contextLabel } from '@/format/errorContext';

import { DailyBars } from './DailyBars';
import { dayLong, niceCeiling } from './scale';

interface Props {
  days: string[];
  /** Only the kinds that failed in the window; absent means zero. */
  series: Record<string, number[]>;
}

const HEIGHT = 48;

/**
 * Failures per day, one small multiple per kind.
 *
 * Not one stacked bar: colour has to follow the kind, never its rank, and only
 * kinds that failed get a series - so any two of five kinds can end up side by
 * side, and no five-hue palette keeps every pair apart (magenta and aqua fall
 * to ΔE 1.6 under deuteranopia). Each multiple is a single series titled by
 * its kind, so nothing depends on colour for identity at all.
 *
 * Every multiple shares one y-scale, so the kinds remain comparable.
 */
export function FailuresByKindChart({ days, series }: Props) {
  const [selected, setSelected] = useState<{ context: string; index: number } | null>(null);

  const contexts = Object.keys(series);

  if (contexts.length === 0) {
    return (
      <Text className="mb-6 text-meta text-text-secondary">
        No failures in the last {days.length} days.
      </Text>
    );
  }

  const max = niceCeiling(Math.max(0, ...Object.values(series).flat()));

  return (
    <View className="mb-6 gap-2">
      {contexts.map((context) => {
        const values = series[context] ?? [];
        const label = contextLabel(context);
        const countOn = (i: number) => values[i] ?? 0;
        const active = selected?.context === context ? selected.index : null;
        const total = values.reduce((sum, value) => sum + value, 0);

        return (
          <View key={context} className="rounded-surface bg-surface-raised p-3">
            <View className="mb-2 flex-row justify-between">
              <Text className="text-meta font-medium text-text">{label}</Text>
              <Text className="text-meta text-text-secondary">
                {active === null
                  ? `${total} in ${days.length} days`
                  : `${dayLong(days[active] ?? '')}: ${countOn(active)}`}
              </Text>
            </View>

            <DailyBars
              days={days}
              stacks={values.map((value) => [
                { key: context, value, className: 'bg-chart-series' },
              ])}
              max={max}
              height={HEIGHT}
              describe={(i) => `${label}, ${dayLong(days[i] ?? '')}: ${countOn(i)}`}
              selected={active}
              onSelect={(index) => setSelected(index === null ? null : { context, index })}
            />
          </View>
        );
      })}
    </View>
  );
}
