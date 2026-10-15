import { ScrollView, Text, View } from 'react-native';

import { useErrors } from '@/api/hooks/useErrors';
import { useHealth } from '@/api/hooks/useHealth';
import { useHealthSeries } from '@/api/hooks/useHealthSeries';
import { useAuth } from '@/auth/AuthContext';
import { contextLabel } from '@/format/errorContext';
import { Button } from '@/ui/Button';
import { FailuresByKindChart } from '@/ui/charts/FailuresByKindChart';
import { ThroughputChart } from '@/ui/charts/ThroughputChart';
import { ErrorBanner } from '@/ui/ErrorBanner';
import { PageTitle } from '@/ui/PageTitle';
import { Screen } from '@/ui/Screen';
import { SectionHeading } from '@/ui/SectionHeading';
import { Skeleton, SkeletonGrid } from '@/ui/Skeleton';
import { StatTile } from '@/ui/StatTile';

export default function Health() {
  const health = useHealth();
  const series = useHealthSeries();
  const errors = useErrors();
  const { signOut } = useAuth();

  const events = errors.data?.pages.flatMap((page) => page.data) ?? [];

  return (
    <Screen>
      <PageTitle title="Pipeline health - Cars Images" />
      <ScrollView>
        {health.isError ? (
          <ErrorBanner
            message={health.error instanceof Error ? health.error.message : 'Health unavailable.'}
          />
        ) : null}

        {health.isLoading ? <SkeletonGrid count={4} numColumns={2} aspectRatio={2.4} /> : null}

        {health.data ? (
          <>
            <SectionHeading title="Runs" />
            <View className="mb-6 flex-row flex-wrap gap-2">
              {Object.entries(health.data.searches_by_status).map(([status, count]) => (
                <StatTile key={status} label={status} value={count} />
              ))}
            </View>

            <SectionHeading title="Recent" />
            <View className="mb-6 flex-row flex-wrap gap-2">
              <StatTile label="errors, 24h" value={health.data.errors_last_24h} />
              <StatTile label="images, 7d" value={health.data.images_last_7d} />
            </View>

            <SectionHeading title="Errors by context, 7 days" />
            <View className="mb-6 flex-row flex-wrap gap-2">
              {Object.entries(health.data.errors_by_context_last_7d).map(([context, count]) => (
                <StatTile key={context} label={contextLabel(context)} value={count} />
              ))}
            </View>
          </>
        ) : null}

        {/* Its own request, so the counters above render whether or not the
            charts' counts arrive - and a failure here says so rather than
            leaving two headings over nothing. */}
        {series.isError ? (
          <ErrorBanner
            message={series.error instanceof Error ? series.error.message : 'Charts unavailable.'}
          />
        ) : null}

        {series.isLoading ? <Skeleton height={200} className="mb-6" /> : null}

        {series.data ? (
          <>
            <SectionHeading
              title={`Searches run, last ${series.data.throughput.days.length} days`}
            />
            <ThroughputChart
              days={series.data.throughput.days}
              completed={series.data.throughput.completed}
              failed={series.data.throughput.failed}
            />

            <SectionHeading
              title={`Failures by kind, last ${series.data.errors_by_context.days.length} days`}
            />
            <FailuresByKindChart
              days={series.data.errors_by_context.days}
              series={series.data.errors_by_context.series}
            />
          </>
        ) : null}

        <SectionHeading title="Error log" />

        {errors.isError ? (
          <ErrorBanner
            message={errors.error instanceof Error ? errors.error.message : 'Error log unavailable.'}
          />
        ) : null}

        {errors.isLoading ? <Skeleton height={72} /> : null}

        {/* The compound guard is deliberate and has a test of its own: reduced
            to `events.length === 0` this reads "nothing logged" over a log
            that has not arrived yet, and over one that failed to arrive. */}
        {!errors.isLoading && !errors.isError && events.length === 0 ? (
          <Text className="mb-6 text-meta text-text-secondary">Nothing logged.</Text>
        ) : (
          events.map((event) => (
            <View key={event.id} className="mb-2 rounded-surface bg-surface-raised p-3">
              <View className="flex-row justify-between">
                <Text className="text-micro text-accent-text">
                  {contextLabel(event.context)}
                </Text>
                <Text className="text-micro text-text-muted">{event.occurred_at ?? ''}</Text>
              </View>
              <Text className="mt-1 text-body text-text">{event.message ?? '(no message)'}</Text>
              {event.exception_message ? (
                <Text className="mt-1 text-meta text-text-secondary" numberOfLines={2}>
                  {event.exception_class}: {event.exception_message}
                </Text>
              ) : null}
            </View>
          ))
        )}

        {errors.hasNextPage ? (
          <View className="mt-2">
            <Button
              label="Load more"
              variant="secondary"
              pending={errors.isFetchingNextPage}
              onPress={() => errors.fetchNextPage()}
            />
          </View>
        ) : null}

        <View className="my-8">
          <Button label="Sign out" variant="ghost" onPress={() => void signOut()} />
        </View>
      </ScrollView>
    </Screen>
  );
}
