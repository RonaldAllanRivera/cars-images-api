import { useLocalSearchParams } from 'expo-router';
import { ActivityIndicator, Text, View } from 'react-native';

import { useSearchImages } from '@/api/hooks/useImages';
import { useSearch } from '@/api/hooks/useSearches';
import { ErrorBanner } from '@/ui/ErrorBanner';
import { ImageCard } from '@/ui/ImageCard';
import { InfiniteGrid } from '@/ui/InfiniteGrid';
import { PageTitle } from '@/ui/PageTitle';
import { Screen } from '@/ui/Screen';
import { StatusBadge } from '@/ui/StatusBadge';

export default function RunDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const searchId = Number(id);
  const search = useSearch(searchId);
  const images = useSearchImages(searchId);

  if (search.isLoading) {
    return (
      <Screen>
        <PageTitle title="Run - Cars Images" />
        <ActivityIndicator className="mt-8" color="#38bdf8" />
      </Screen>
    );
  }

  // Matches search/[id].tsx: a failed lookup must say so rather than render a
  // headerless screen over an empty grid.
  if (search.isError) {
    return (
      <Screen>
        <PageTitle title="Run - Cars Images" />
        <ErrorBanner
          message={search.error instanceof Error ? search.error.message : 'Not found.'}
        />
      </Screen>
    );
  }

  return (
    <Screen>
      <PageTitle title="Run - Cars Images" />
      {search.data ? (
        <View className="mb-3">
          <Text className="text-lg font-bold text-white">
            {search.data.make} {search.data.model ?? ''} · {search.data.from_year}–
            {search.data.to_year}
          </Text>
          <View className="mt-1 flex-row items-center gap-2">
            <StatusBadge status={search.data.status} />
            <Text className="text-xs text-slate-400">{search.data.images_count ?? 0} images</Text>
          </View>
          {search.data.status === 'failed' ? (
            <Text className="mt-2 text-xs text-red-300">
              This run failed. The reason is on the Health tab&apos;s error log.
            </Text>
          ) : null}
        </View>
      ) : null}

      <InfiniteGrid
        query={images}
        numColumns={2}
        keyExtractor={(image) => String(image.id)}
        renderItem={(image) => (
          <ImageCard
            image={image}
            href={{ pathname: '/(app)/runs/image/[id]', params: { id: image.id } }}
          />
        )}
        emptyTitle="This run returned no images"
      />
    </Screen>
  );
}
