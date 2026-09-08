import { Stack } from 'expo-router';

/** Same nesting as `search/`: keeps `[id]` out of the tab bar. */
export default function RunsLayout() {
  return (
    <Stack
      screenOptions={{
        headerStyle: { backgroundColor: '#0f172a' },
        headerTintColor: '#f8fafc',
        contentStyle: { backgroundColor: '#0f172a' },
      }}
    >
      <Stack.Screen name="index" options={{ title: 'Runs' }} />
      <Stack.Screen name="[id]" options={{ title: 'Run' }} />
      <Stack.Screen name="image/[id]" options={{ title: 'Image' }} />
    </Stack>
  );
}
