import { ActivityIndicator, Pressable, ScrollView, Text, View } from 'react-native';

import { useErrors } from '@/api/hooks/useErrors';
import { useHealth } from '@/api/hooks/useHealth';
import { useAuth } from '@/auth/AuthContext';
import { ErrorBanner } from '@/ui/ErrorBanner';
import { PageTitle } from '@/ui/PageTitle';
import { Screen } from '@/ui/Screen';
import { StatTile } from '@/ui/StatTile';

/** Mirrors ErrorEvent::contexts() - keys to the labels Filament shows. */
const CONTEXT_LABELS: Record<string, string> = {
  csv_upload: 'CSV upload',
  csv_row: 'CSV row',
  search_run: 'Search run',
  image_download: 'Image download',
  wikimedia_block: 'Wikimedia block',
};

export default function Health() {
  const health = useHealth();
  const errors = useErrors();
  const { signOut } = useAuth();

  const events = errors.data?.pages.flatMap((page) => page.data) ?? [];

  return (
    <Screen>
      <PageTitle title="Pipeline health - Cars Images" />
      <ScrollView>
        <Text className="mb-3 text-xl font-bold text-white">Pipeline health</Text>

        {health.isError ? (
          <ErrorBanner
            message={health.error instanceof Error ? health.error.message : 'Health unavailable.'}
          />
        ) : null}

        {health.isLoading ? <ActivityIndicator color="#38bdf8" /> : null}

        {health.data ? (
          <>
            <Text className="mb-2 text-xs uppercase tracking-wide text-slate-500">Runs</Text>
            <View className="mb-4 flex-row flex-wrap gap-2">
              {Object.entries(health.data.searches_by_status).map(([status, count]) => (
                <StatTile key={status} label={status} value={count} />
              ))}
            </View>

            <Text className="mb-2 text-xs uppercase tracking-wide text-slate-500">Recent</Text>
            <View className="mb-4 flex-row flex-wrap gap-2">
              <StatTile label="errors, 24h" value={health.data.errors_last_24h} />
              <StatTile label="images, 7d" value={health.data.images_last_7d} />
            </View>

            <Text className="mb-2 text-xs uppercase tracking-wide text-slate-500">
              Errors by context, 7d
            </Text>
            <View className="mb-4 flex-row flex-wrap gap-2">
              {Object.entries(health.data.errors_by_context_last_7d).map(([context, count]) => (
                <StatTile key={context} label={CONTEXT_LABELS[context] ?? context} value={count} />
              ))}
            </View>
          </>
        ) : null}

        <Text className="mb-2 text-xs uppercase tracking-wide text-slate-500">Error log</Text>

        {errors.isError ? (
          <ErrorBanner
            message={errors.error instanceof Error ? errors.error.message : 'Error log unavailable.'}
          />
        ) : null}

        {errors.isLoading ? <ActivityIndicator color="#38bdf8" /> : null}

        {!errors.isLoading && !errors.isError && events.length === 0 ? (
          <Text className="mb-4 text-sm text-slate-400">Nothing logged.</Text>
        ) : (
          events.map((event) => (
            <View key={event.id} className="mb-2 rounded-xl bg-slate-800 p-3">
              <View className="flex-row justify-between">
                <Text className="text-xs font-medium text-sky-300">
                  {CONTEXT_LABELS[event.context] ?? event.context}
                </Text>
                <Text className="text-xs text-slate-500">{event.occurred_at ?? ''}</Text>
              </View>
              <Text className="mt-1 text-sm text-slate-200">{event.message ?? '(no message)'}</Text>
              {event.exception_message ? (
                <Text className="mt-1 text-xs text-slate-400" numberOfLines={2}>
                  {event.exception_class}: {event.exception_message}
                </Text>
              ) : null}
            </View>
          ))
        )}

        {errors.hasNextPage ? (
          <Pressable
            className="mt-2 items-center rounded-lg bg-slate-800 py-3 active:opacity-80"
            onPress={() => errors.fetchNextPage()}
          >
            <Text className="text-sm text-sky-400">
              {errors.isFetchingNextPage ? 'Loading…' : 'Load more'}
            </Text>
          </Pressable>
        ) : null}

        <Pressable className="my-8 items-center py-3" onPress={() => void signOut()}>
          <Text className="text-sm text-red-400">Sign out</Text>
        </Pressable>
      </ScrollView>
    </Screen>
  );
}
