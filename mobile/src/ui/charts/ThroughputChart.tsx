import { useState } from 'react';
import { Text, View } from 'react-native';

import { DailyBars } from './DailyBars';
import { dayLong, niceCeiling } from './scale';

interface Props {
  days: string[];
  completed: number[];
  failed: number[];
}

const HEIGHT = 120;

const sum = (values: number[]) => values.reduce((total, value) => total + value, 0);

/**
 * Searches run per day: completed at the base, failed stacked on top.
 *
 * Thirty bars rather than the panel's line. On a phone, bars answer "did the
 * pipeline run that day?" at a glance, and an empty day is visibly empty
 * where a line would join straight across it.
 *
 * Status colours, because completed against failed means good against bad.
 * Their red-green separation is marginal under deuteranopia (ΔE 8.1), so
 * colour never carries the meaning alone: the legend names both series, and
 * a surface gap divides the two segments.
 */
export function ThroughputChart({ days, completed, failed }: Props) {
  const [selected, setSelected] = useState<number | null>(null);

  const completedOn = (i: number) => completed[i] ?? 0;
  const failedOn = (i: number) => failed[i] ?? 0;
  const describe = (i: number) =>
    `${dayLong(days[i] ?? '')}: ${completedOn(i)} completed, ${failedOn(i)} failed`;

  const max = niceCeiling(Math.max(0, ...days.map((_, i) => completedOn(i) + failedOn(i))));

  return (
    <View className="mb-6 rounded-surface bg-surface-raised p-3">
      {/* The tap callout. It always holds a line - the window's totals until
          a bar is tapped - so the chart does not jump when one is. */}
      <Text className="mb-2 text-meta text-text-secondary">
        {selected === null
          ? `${sum(completed)} completed, ${sum(failed)} failed in ${days.length} days`
          : describe(selected)}
      </Text>

      <DailyBars
        days={days}
        stacks={days.map((_, i) => [
          { key: 'completed', value: completedOn(i), className: 'bg-success' },
          { key: 'failed', value: failedOn(i), className: 'bg-danger' },
        ])}
        max={max}
        height={HEIGHT}
        describe={describe}
        selected={selected}
        onSelect={setSelected}
      />

      <View className="mt-3 flex-row gap-4">
        <LegendItem swatch="bg-success" label="Completed" />
        <LegendItem swatch="bg-danger" label="Failed" />
      </View>
    </View>
  );
}

/** The label wears the text colour; only the swatch beside it carries the series colour. */
function LegendItem({ swatch, label }: { swatch: string; label: string }) {
  return (
    <View className="flex-row items-center gap-1">
      <View className={`h-2 w-2 rounded-sm ${swatch}`} />
      <Text className="text-meta text-text-secondary">{label}</Text>
    </View>
  );
}
