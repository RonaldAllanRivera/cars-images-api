import { Text, View } from 'react-native';

export function StatTile({ label, value }: { label: string; value: number | string }) {
  return (
    <View className="min-w-[88px] flex-1 rounded-xl bg-slate-800 p-3">
      <Text className="text-2xl font-bold text-white">{value}</Text>
      <Text className="text-xs text-slate-400">{label}</Text>
    </View>
  );
}
