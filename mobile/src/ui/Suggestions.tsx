import { Pressable, Text, View } from 'react-native';

const LIMIT = 5;

/**
 * Up to five options that start with what has been typed, ignoring case.
 *
 * Nothing for an empty field, and never the exact text already in it - which
 * is what a tap leaves behind. A case-only difference is still offered, so
 * "kia" gets the catalogue's "Kia".
 */
export function suggest(options: readonly string[], typed: string): string[] {
  const text = typed.trim();
  const needle = text.toLowerCase();

  if (needle === '') return [];

  return options
    .filter((option) => option !== text && option.toLowerCase().startsWith(needle))
    .slice(0, LIMIT);
}

/**
 * Tappable rows under a field. They suggest and never constrain: every search
 * reaches Wikimedia, so a make outside the catalogue is as valid as one in it.
 */
export function Suggestions({
  items,
  onPick,
}: {
  items: string[];
  onPick: (item: string) => void;
}) {
  if (items.length === 0) return null;

  return (
    <View className="overflow-hidden rounded-control bg-surface-raised">
      {items.map((item, index) => (
        <Pressable
          key={item}
          accessibilityRole="button"
          accessibilityLabel={item}
          accessibilityHint="Fills the field"
          onPress={() => onPick(item)}
          // 44pt tall: the smallest comfortable touch target.
          className={`min-h-[44px] justify-center px-4 active:opacity-80 ${
            index > 0 ? 'border-t border-border' : ''
          }`}
        >
          <Text className="text-body text-text">{item}</Text>
        </Pressable>
      ))}
    </View>
  );
}
