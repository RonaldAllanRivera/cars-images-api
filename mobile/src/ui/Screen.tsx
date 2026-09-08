import type { ReactNode } from 'react';
import { View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

export function Screen({ children }: { children: ReactNode }) {
  return (
    <SafeAreaView className="flex-1 bg-slate-900">
      <View className="flex-1 p-4">{children}</View>
    </SafeAreaView>
  );
}
