import { useInfiniteQuery, useQuery } from '@tanstack/react-query';

import { apiRequest } from '../client';
import { queryKeys } from '../queryKeys';
import { cursorPage, SearchSchema, single } from '../schemas';
import type { SearchStatus } from '../schemas';

const searchPage = cursorPage(SearchSchema);
const oneSearch = single(SearchSchema);

export function useSearches(status?: SearchStatus) {
  return useInfiniteQuery({
    queryKey: queryKeys.searches(status),
    initialPageParam: null as string | null,
    queryFn: ({ pageParam }) =>
      apiRequest('/searches', {
        query: { status, cursor: pageParam ?? undefined },
        schema: searchPage,
      }),
    getNextPageParam: (lastPage) => lastPage.meta.next_cursor,
  });
}

export function useSearch(id: number) {
  return useQuery({
    queryKey: queryKeys.search(id),
    queryFn: async () => (await apiRequest(`/searches/${id}`, { schema: oneSearch })).data,
  });
}
