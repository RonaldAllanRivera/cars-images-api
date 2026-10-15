import { useQuery } from '@tanstack/react-query';

import { apiRequest } from '../client';
import { queryKeys } from '../queryKeys';
import { MakesSchema, single } from '../schemas';

const makes = single(MakesSchema);

/**
 * The make catalogue behind the search form's suggestions.
 *
 * Ten curated makes that change only when someone edits them in the panel, so
 * it is held for an hour. A failure is left for the caller to ignore:
 * suggestions are a convenience, and the fields work as plain text without
 * them.
 */
export function useMakes() {
  return useQuery({
    queryKey: queryKeys.makes(),
    queryFn: async () => (await apiRequest('/makes', { schema: makes })).data,
    staleTime: 60 * 60_000,
  });
}
