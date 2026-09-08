import { Image } from 'expo-image';
import { Pressable, Text, View } from 'react-native';

import { useReviewImage } from '@/api/hooks/useReviewImage';
import { useReviewQueueImages } from '@/api/hooks/useReviewQueueImages';
import type { Image as CarImage } from '@/api/schemas';
import { ErrorBanner } from '@/ui/ErrorBanner';
import { InfiniteGrid } from '@/ui/InfiniteGrid';
import { PageTitle } from '@/ui/PageTitle';
import { Screen } from '@/ui/Screen';

export default function ReviewQueue() {
  // The queue is exactly the images no human has ruled on yet - and stays
  // that way the instant a verdict lands, not just after the next refetch:
  // see useReviewQueueImages for why the filtering has to happen here.
  const query = useReviewQueueImages();
  const review = useReviewImage();

  return (
    <Screen>
      <PageTitle title="Review queue - Cars Images" />
      <Text className="mb-1 text-xl font-bold text-white">Review queue</Text>
      <Text className="mb-3 text-sm text-slate-400">
        Your verdict is recorded separately from the machine&apos;s, so both stay comparable.
      </Text>

      {review.isError ? (
        <ErrorBanner
          message={
            review.error instanceof Error
              ? `${review.error.message}. The card has been put back.`
              : 'The verdict could not be saved. The card has been put back.'
          }
        />
      ) : null}

      <InfiniteGrid
        query={query}
        keyExtractor={(image) => String(image.id)}
        emptyTitle="Nothing left to review"
        emptyHint="Every image has a verdict."
        renderItem={(image: CarImage) => (
          <View className="mb-3 overflow-hidden rounded-xl bg-slate-800">
            <Image
              source={image.thumbnail_url ?? image.source_url}
              style={{ width: '100%', aspectRatio: 4 / 3 }}
              contentFit="cover"
              transition={150}
              cachePolicy="disk"
            />
            <View className="gap-2 p-3">
              <Text className="text-base font-medium text-white" numberOfLines={1}>
                {image.make} {image.model ?? ''} {image.year}
              </Text>
              <Text className="text-xs text-slate-400" numberOfLines={1}>
                {image.title}
              </Text>
              <View className="mt-1 flex-row gap-2">
                <Pressable
                  className="flex-1 items-center rounded-lg bg-emerald-500 py-2 active:opacity-80"
                  onPress={() => review.mutate({ id: image.id, review_status: 'approved' })}
                >
                  <Text className="text-sm font-semibold text-white">Approve</Text>
                </Pressable>
                <Pressable
                  className="flex-1 items-center rounded-lg bg-red-500 py-2 active:opacity-80"
                  onPress={() => review.mutate({ id: image.id, review_status: 'rejected' })}
                >
                  <Text className="text-sm font-semibold text-white">Reject</Text>
                </Pressable>
              </View>
            </View>
          </View>
        )}
      />
    </Screen>
  );
}
