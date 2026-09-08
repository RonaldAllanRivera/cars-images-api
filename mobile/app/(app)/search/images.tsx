import { useEffect, useState } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';

import { useImages } from '@/api/hooks/useImages';
import type { ReviewStatus } from '@/api/schemas';
import { ImageCard } from '@/ui/ImageCard';
import { InfiniteGrid } from '@/ui/InfiniteGrid';
import { Screen } from '@/ui/Screen';

const STATUSES: (ReviewStatus | 'all')[] = ['all', 'pending', 'approved', 'rejected'];

export default function ImageGrid() {
  const [make, setMake] = useState('');
  const [debouncedMake, setDebouncedMake] = useState('');
  const [status, setStatus] = useState<ReviewStatus | 'all'>('all');

  // The raw input drives the field; only the settled value drives the query.
  // Without this every keystroke is a new query key with no cached data, so
  // the list tears down to a spinner and refetches once per character - and
  // every authenticated route shares one rate-limit bucket per user, so the
  // burst can throttle endpoints this screen never touches.
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedMake(make), 300);

    return () => clearTimeout(timer);
  }, [make]);

  const query = useImages({
    make: debouncedMake.trim() || undefined,
    review_status: status === 'all' ? undefined : status,
  });

  return (
    <Screen>
      <TextInput
        className="mb-3 rounded-lg bg-slate-800 px-4 py-3 text-white"
        placeholder="Filter by make"
        placeholderTextColor="#94a3b8"
        value={make}
        onChangeText={setMake}
      />

      <View className="mb-3 flex-row gap-2">
        {STATUSES.map((option) => (
          <Pressable
            key={option}
            className={`rounded-full px-3 py-1 ${status === option ? 'bg-sky-500' : 'bg-slate-800'}`}
            onPress={() => setStatus(option)}
          >
            <Text className={`text-xs ${status === option ? 'text-white' : 'text-slate-300'}`}>
              {option}
            </Text>
          </Pressable>
        ))}
      </View>

      <InfiniteGrid
        query={query}
        numColumns={2}
        keyExtractor={(image) => String(image.id)}
        renderItem={(image) => (
          <ImageCard
            image={image}
            href={{ pathname: '/(app)/search/[id]', params: { id: image.id } }}
          />
        )}
        emptyTitle="No images match"
        emptyHint="Try a different make, or run a new search."
      />
    </Screen>
  );
}
