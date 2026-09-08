import type { InfiniteData } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { ActivityIndicator, FlatList, RefreshControl } from 'react-native';

import type { CursorPage } from '@/api/schemas';
import { EmptyState } from './EmptyState';

interface Props<T> {
  query: {
    data?: InfiniteData<CursorPage<T>>;
    isLoading: boolean;
    isRefetching: boolean;
    hasNextPage: boolean;
    isFetchingNextPage: boolean;
    fetchNextPage: () => void;
    refetch: () => void;
  };
  renderItem: (item: T) => ReactElement;
  keyExtractor: (item: T) => string;
  numColumns?: number;
  emptyTitle: string;
  emptyHint?: string;
}

export function InfiniteGrid<T>({
  query,
  renderItem,
  keyExtractor,
  numColumns = 1,
  emptyTitle,
  emptyHint,
}: Props<T>) {
  const items = query.data?.pages.flatMap((page) => page.data) ?? [];

  if (query.isLoading) {
    return <ActivityIndicator className="mt-8" color="#38bdf8" />;
  }

  return (
    <FlatList
      data={items}
      numColumns={numColumns}
      // A multi-column list needs the gap or the cards touch.
      columnWrapperStyle={numColumns > 1 ? { gap: 12 } : undefined}
      keyExtractor={keyExtractor}
      renderItem={({ item }) => renderItem(item)}
      onEndReachedThreshold={0.5}
      onEndReached={() => {
        if (query.hasNextPage && !query.isFetchingNextPage) query.fetchNextPage();
      }}
      refreshControl={
        <RefreshControl refreshing={query.isRefetching} onRefresh={query.refetch} tintColor="#38bdf8" />
      }
      ListEmptyComponent={<EmptyState title={emptyTitle} hint={emptyHint} />}
      ListFooterComponent={
        query.isFetchingNextPage ? <ActivityIndicator className="my-4" color="#38bdf8" /> : null
      }
    />
  );
}
