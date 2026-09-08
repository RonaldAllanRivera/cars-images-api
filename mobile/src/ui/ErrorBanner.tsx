import { Text, View } from 'react-native';

export function ErrorBanner({ message }: { message: string }) {
  return (
    <View className="mb-3 rounded-lg border border-red-500/40 bg-red-500/10 p-3">
      <Text className="text-sm text-red-200">{message}</Text>
    </View>
  );
}
