import { Link } from 'expo-router';
import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { useSearches } from '@/api/hooks/useSearches';
import type { SearchStatus } from '@/api/schemas';
import { InfiniteGrid } from '@/ui/InfiniteGrid';
import { PageTitle } from '@/ui/PageTitle';
import { Screen } from '@/ui/Screen';
import { StatusBadge } from '@/ui/StatusBadge';

const STATUSES: (SearchStatus | 'all')[] = ['all', 'pending', 'running', 'completed', 'failed'];

export default function Runs() {
  const [status, setStatus] = useState<SearchStatus | 'all'>('all');
  const query = useSearches(status === 'all' ? undefined : status);

  return (
    <Screen>
      <PageTitle title="Runs - Cars Images" />
      <View className="mb-3 flex-row flex-wrap gap-2">
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
        keyExtractor={(search) => String(search.id)}
        emptyTitle="No runs yet"
        emptyHint="Start one from the Search tab."
        renderItem={(search) => (
          <Link href={{ pathname: '/(app)/runs/[id]', params: { id: search.id } }} asChild>
            <Pressable className="mb-2 rounded-xl bg-slate-800 p-3 active:opacity-80">
              <Text className="text-base font-medium text-white">
                {search.make} {search.model ?? ''} · {search.from_year}–{search.to_year}
              </Text>
              <View className="mt-1 flex-row items-center gap-2">
                <StatusBadge status={search.status} />
                <Text className="text-xs text-slate-400">{search.images_count ?? 0} images</Text>
              </View>
            </Pressable>
          </Link>
        )}
      />
    </Screen>
  );
}
