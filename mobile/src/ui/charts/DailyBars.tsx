import { Pressable, Text, View } from 'react-native';

import { dayShort } from './scale';

export interface Segment {
  /** Becomes the segment's testID, `segment-<key>`. */
  key: string;
  value: number;
  /**
   * A whole background class from the tokens, such as `bg-success`, written
   * out in full where it is chosen: Tailwind finds classes by scanning the
   * source, so a class assembled at runtime is never generated.
   */
  className: string;
}

interface Props {
  days: string[];
  /** One stack per day, baseline segment first. */
  stacks: Segment[][];
  /** The axis top. Passed in, not derived, so small multiples can share one. */
  max: number;
  /** Plot height in px. */
  height: number;
  /** What a screen reader hears for a day. */
  describe: (index: number) => string;
  selected: number | null;
  onSelect: (index: number | null) => void;
}

/** The surface gap between stacked segments, and between neighbouring bars. */
const GAP = 2;

/** A non-zero value never draws thinner than this, or one failure beside fifty successes vanishes. */
const MIN_SEGMENT = 2;

/** Rounded at the data end only; the baseline end stays square. */
const DATA_END_RADIUS = 4;

/**
 * Daily bars drawn with plain Views: no chart library, so nothing new for
 * Jest to load and the same rendering on web.
 *
 * Each day's whole column is its tap target, wider and taller than the bar
 * drawn inside it - thirty bars on a phone are too thin to hit otherwise.
 * The axes are recessive: muted labels at the top and foot of one y-axis, the
 * first and last day under the x-axis, hairlines in the border colour.
 */
export function DailyBars({ days, stacks, max, height, describe, selected, onSelect }: Props) {
  // Scaled against the plot less one gap, so the tallest stack still fits
  // with a gap inside it.
  const scale = (height - GAP) / max;

  return (
    <View>
      <View className="flex-row">
        <View className="w-8 justify-between pr-1" style={{ height }}>
          <Text className="text-right text-micro text-text-muted">{max}</Text>
          <Text className="text-right text-micro text-text-muted">0</Text>
        </View>

        <View className="flex-1 border-b border-t border-border">
          <View className="flex-row items-end overflow-hidden" style={{ height }}>
            {stacks.map((stack, index) => {
              const drawn = stack.filter((segment) => segment.value > 0);

              return (
                <Pressable
                  key={days[index] ?? index}
                  accessibilityRole="button"
                  accessibilityLabel={describe(index)}
                  accessibilityState={{ selected: selected === index }}
                  onPress={() => onSelect(selected === index ? null : index)}
                  className="h-full flex-1 justify-end"
                  style={{
                    paddingHorizontal: GAP / 2,
                    opacity: selected === null || selected === index ? 1 : 0.4,
                  }}
                >
                  {/* Top segment first: the column stacks downward, and the
                      baseline segment belongs at the bottom. */}
                  {[...drawn].reverse().map((segment, position) => (
                    <View
                      key={segment.key}
                      testID={`segment-${segment.key}`}
                      className={segment.className}
                      style={{
                        height: Math.max(MIN_SEGMENT, Math.round(segment.value * scale)),
                        marginBottom: position < drawn.length - 1 ? GAP : 0,
                        borderTopLeftRadius: position === 0 ? DATA_END_RADIUS : 0,
                        borderTopRightRadius: position === 0 ? DATA_END_RADIUS : 0,
                      }}
                    />
                  ))}
                </Pressable>
              );
            })}
          </View>
        </View>
      </View>

      {days.length > 0 ? (
        <View className="ml-8 mt-1 flex-row justify-between">
          <Text className="text-micro text-text-muted">{dayShort(days[0] ?? '')}</Text>
          <Text className="text-micro text-text-muted">
            {dayShort(days[days.length - 1] ?? '')}
          </Text>
        </View>
      ) : null}
    </View>
  );
}
