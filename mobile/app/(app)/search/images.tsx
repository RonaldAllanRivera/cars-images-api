import { useState } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';

import { useImages } from '@/api/hooks/useImages';
import type { ReviewStatus } from '@/api/schemas';
import { ImageCard } from '@/ui/ImageCard';
import { InfiniteGrid } from '@/ui/InfiniteGrid';
import { Screen } from '@/ui/Screen';

const STATUSES: (ReviewStatus | 'all')[] = ['all', 'pending', 'approved', 'rejected'];

export default function ImageGrid() {
  const [make, setMake] = useState('');
  const [status, setStatus] = useState<ReviewStatus | 'all'>('all');

  const query = useImages({
    make: make.trim() || undefined,
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
        renderItem={(image) => <ImageCard image={image} />}
        emptyTitle="No images match"
        emptyHint="Try a different make, or run a new search."
      />
    </Screen>
  );
}
