import { Text, View } from 'react-native';

export function EmptyState({ title, hint }: { title: string; hint?: string }) {
  return (
    <View className="items-center gap-1 p-8">
      <Text className="text-base font-medium text-slate-300">{title}</Text>
      {hint ? <Text className="text-center text-sm text-slate-500">{hint}</Text> : null}
    </View>
  );
}
