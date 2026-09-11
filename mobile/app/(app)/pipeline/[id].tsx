import { Link, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { useImport } from '@/api/hooks/useImports';
import { useSearches } from '@/api/hooks/useSearches';
import type { Search } from '@/api/schemas';
import { CoveragePanel } from '@/ui/CoveragePanel';
import type { CoverageFilter } from '@/ui/CoveragePanel';
import { ErrorBanner } from '@/ui/ErrorBanner';
import { InfiniteGrid } from '@/ui/InfiniteGrid';
import { PageTitle } from '@/ui/PageTitle';
import { Screen } from '@/ui/Screen';
import { Skeleton } from '@/ui/Skeleton';
import { StatusBadge } from '@/ui/StatusBadge';

export default function ImportDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const importId = Number(id);

  // The coverage tile the user tapped, which is also the queries filter. One
  // piece of state, because they are the same choice: "23 not run yet" is both
  // a count and the list of those 23.
  const [coverage, setCoverage] = useState<CoverageFilter | null>(null);

  const csvImport = useImport(importId);
  const queries = useSearches({ source: 'csv', csv_import_id: importId, ...(coverage ? { coverage } : {}) });

  if (csvImport.isLoading) {
    return (
      <Screen>
        <PageTitle title="Import - Cars Images" />
        <Skeleton height={140} />
      </Screen>
    );
  }

  if (csvImport.isError || !csvImport.data) {
    return (
      <Screen>
        <PageTitle title="Import - Cars Images" />
        <ErrorBanner
          message={csvImport.error instanceof Error ? csvImport.error.message : 'Not found.'}
        />
      </Screen>
    );
  }

  const data = csvImport.data;

  return (
    <Screen>
      <PageTitle title="Import - Cars Images" />

      <Text className="text-section text-text" numberOfLines={1}>
        {data.original_filename}
      </Text>
      <Text className="mb-4 text-meta text-text-muted">
        {data.unique_combos ?? 0} queries
        {data.duplicates_skipped ? `, ${data.duplicates_skipped} duplicates skipped` : ''}
      </Text>

      <CoveragePanel coverage={data.coverage ?? null} selected={coverage} onSelect={setCoverage} />

      <InfiniteGrid
        query={queries}
        keyExtractor={(search) => String(search.id)}
        emptyTitle={coverage ? 'No queries match' : 'This import has no queries'}
        emptyHint={coverage ? 'Tap the highlighted count again to see them all.' : undefined}
        renderItem={(search: Search) => (
          <Link
            href={{ pathname: '/(app)/search/runs/[id]', params: { id: search.id } }}
            asChild
          >
            <Pressable className="mb-2 rounded-surface bg-surface-raised p-3 active:opacity-80">
              <Text className="text-body font-medium text-text" numberOfLines={1}>
                {search.make} {search.model ?? ''} · {search.from_year}
              </Text>
              <View className="mt-1 flex-row items-center gap-2">
                <StatusBadge status={search.status} />
                <Text className="text-meta text-text-secondary">
                  {search.images_count ?? 0} images
                </Text>
              </View>
            </Pressable>
          </Link>
        )}
      />
    </Screen>
  );
}
