import { useQuery } from '@tanstack/react-query';

import { apiRequest } from '../client';
import { queryKeys } from '../queryKeys';
import { HealthSeriesSchema, single } from '../schemas';

const series = single(HealthSeriesSchema);

export function useHealthSeries() {
  return useQuery({
    queryKey: queryKeys.healthSeries(),
    queryFn: async () => (await apiRequest('/health/series', { schema: series })).data,
    // Daily buckets: a minute is already finer than the smallest unit drawn.
    staleTime: 60_000,
  });
}
