import { Stack } from 'expo-router';

/**
 * Without this layout expo-router flattens `search/` into the parent Tabs
 * navigator, so `images` and `[id]` become tabs of their own. A nested Stack
 * collapses the directory into the single `search` tab the Tabs layout
 * already declares, and gives the detail screens a back button.
 *
 * The Stack owns the header here and the Tabs layout hides its own for this
 * tab: only the inner navigator knows which of the three screens is showing,
 * so only it can title the screen and offer the back affordance.
 */
export default function SearchLayout() {
  return (
    <Stack
      screenOptions={{
        headerStyle: { backgroundColor: '#0f172a' },
        headerTintColor: '#f8fafc',
        contentStyle: { backgroundColor: '#0f172a' },
      }}
    >
      <Stack.Screen name="index" options={{ title: 'Search' }} />
      <Stack.Screen name="images" options={{ title: 'All images' }} />
      <Stack.Screen name="[id]" options={{ title: 'Image' }} />
    </Stack>
  );
}
