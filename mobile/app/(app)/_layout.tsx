import { Redirect, Tabs } from 'expo-router';
import { ActivityIndicator, View } from 'react-native';

import { useAuth } from '@/auth/AuthContext';
import { PageTitle } from '@/ui/PageTitle';

export default function AppLayout() {
  const { status } = useAuth();

  if (status === 'loading') {
    return (
      <View className="flex-1 items-center justify-center bg-slate-900">
        {/* The static export renders this branch for every route behind the
            guard - whether there is a session is only knowable in the
            browser - so without a title here those pages ship a blank tab.
            Each screen's own <PageTitle> replaces it once it mounts. */}
        <PageTitle title="Cars Images" />
        <ActivityIndicator color="#38bdf8" />
      </View>
    );
  }

  if (status === 'anonymous') {
    return <Redirect href="/login" />;
  }

  return (
    <Tabs
      screenOptions={{
        headerStyle: { backgroundColor: '#0f172a' },
        headerTintColor: '#f8fafc',
        tabBarStyle: { backgroundColor: '#0f172a', borderTopColor: '#1e293b' },
        tabBarActiveTintColor: '#38bdf8',
        tabBarInactiveTintColor: '#94a3b8',
      }}
    >
      {/* headerShown: false - this tab nests a Stack, which owns the header. */}
      <Tabs.Screen name="search" options={{ title: 'Search', headerShown: false }} />
      {/* headerShown: false - this tab nests a Stack, which owns the header. */}
      <Tabs.Screen name="runs" options={{ title: 'Runs', headerShown: false }} />
      <Tabs.Screen name="review" options={{ title: 'Review' }} />
      <Tabs.Screen name="health" options={{ title: 'Health' }} />
    </Tabs>
  );
}
