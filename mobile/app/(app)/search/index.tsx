import { router } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, Text, TextInput, View } from 'react-native';

import { ApiValidationError } from '@/api/client';
import {
  MAX_IMAGES_PER_YEAR,
  MAX_YEAR_SPAN,
  useCreateSearch,
} from '@/api/hooks/useCreateSearch';
import { ErrorBanner } from '@/ui/ErrorBanner';
import { PageTitle } from '@/ui/PageTitle';
import { Screen } from '@/ui/Screen';

export default function SearchForm() {
  const createSearch = useCreateSearch();
  const [make, setMake] = useState('');
  const [model, setModel] = useState('');
  const [fromYear, setFromYear] = useState('');
  const [toYear, setToYear] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  // The run behind a blocked/failed notice, so the banner can link to it
  // without navigating away from the message the user has to read.
  const [noticeRunId, setNoticeRunId] = useState<number | null>(null);

  const submit = async () => {
    setError(null);
    setNotice(null);
    setNoticeRunId(null);

    const from = Number(fromYear);
    const to = Number(toYear);

    if (!make.trim()) {
      setError('A make is required.');

      return;
    }

    if (!Number.isInteger(from) || !Number.isInteger(to) || from < 1900 || to < 1900) {
      setError('Enter both years as four-digit numbers.');

      return;
    }

    // Checked here as well as server-side: the search runs inline inside the
    // request, so a too-wide span is a limit worth showing before the wait.
    if (Math.abs(to - from) > MAX_YEAR_SPAN) {
      setError(
        `The year range may span at most ${MAX_YEAR_SPAN} years, because the search runs inside the request.`,
      );

      return;
    }

    try {
      const result = await createSearch.mutateAsync({
        make: make.trim(),
        model: model.trim() || undefined,
        from_year: from,
        to_year: to,
        images_per_year: MAX_IMAGES_PER_YEAR,
      });

      if (result.outcome === 'blocked' || result.outcome === 'failed') {
        setNotice(
          result.outcome === 'blocked'
            ? `${result.message ?? 'Wikimedia is rate-limiting this server.'}${
                result.retryAfterSeconds ? ` Try again in ${result.retryAfterSeconds}s.` : ''
              }`
            : (result.message ?? 'The search failed. The reason is in the error log.'),
        );

        // Every outcome produced a real run row, so the user keeps a link to
        // it - but navigating now would cover the notice before it is read,
        // and the run screen only knows the row is `failed`, not that
        // Wikimedia is throttling or for how long. So: stay, and offer the
        // link.
        setNoticeRunId(result.search.id);

        return;
      }

      router.push({ pathname: '/(app)/runs/[id]', params: { id: result.search.id } });
    } catch (caught) {
      setError(
        caught instanceof ApiValidationError
          ? (caught.first('to_year') ?? caught.first('make') ?? caught.message)
          : caught instanceof Error
            ? caught.message
            : 'The search could not be started.',
      );
    }
  };

  return (
    <Screen>
      <PageTitle title="New search - Cars Images" />
      <ScrollView contentContainerClassName="gap-3">
        <Text className="text-xl font-bold text-white">New search</Text>
        <Text className="text-sm text-slate-400">
          Runs immediately against Wikimedia Commons. Up to {MAX_YEAR_SPAN + 1} years,{' '}
          {MAX_IMAGES_PER_YEAR} images per year.
        </Text>

        {error ? <ErrorBanner message={error} /> : null}
        {notice ? (
          <View className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3">
            <Text className="text-sm text-amber-200">{notice}</Text>
            {noticeRunId !== null ? (
              <Pressable
                className="mt-2 self-start"
                onPress={() =>
                  router.push({ pathname: '/(app)/runs/[id]', params: { id: noticeRunId } })
                }
              >
                <Text className="text-sm font-semibold text-sky-400">View the run</Text>
              </Pressable>
            ) : null}
          </View>
        ) : null}

        <TextInput
          className="rounded-lg bg-slate-800 px-4 py-3 text-white"
          placeholder="Make (e.g. Toyota)"
          placeholderTextColor="#94a3b8"
          value={make}
          onChangeText={setMake}
        />
        <TextInput
          className="rounded-lg bg-slate-800 px-4 py-3 text-white"
          placeholder="Model (optional)"
          placeholderTextColor="#94a3b8"
          value={model}
          onChangeText={setModel}
        />
        <View className="flex-row gap-3">
          <TextInput
            className="flex-1 rounded-lg bg-slate-800 px-4 py-3 text-white"
            placeholder="From year"
            placeholderTextColor="#94a3b8"
            keyboardType="number-pad"
            value={fromYear}
            onChangeText={setFromYear}
          />
          <TextInput
            className="flex-1 rounded-lg bg-slate-800 px-4 py-3 text-white"
            placeholder="To year"
            placeholderTextColor="#94a3b8"
            keyboardType="number-pad"
            value={toYear}
            onChangeText={setToYear}
          />
        </View>

        <Pressable
          className="items-center rounded-lg bg-sky-500 px-6 py-3 active:opacity-80"
          disabled={createSearch.isPending}
          onPress={submit}
        >
          {createSearch.isPending ? (
            <ActivityIndicator color="white" />
          ) : (
            <Text className="text-base font-semibold text-white">Run search</Text>
          )}
        </Pressable>

        {createSearch.isPending ? (
          <Text className="text-center text-xs text-slate-500">
            This runs inline and can take several seconds.
          </Text>
        ) : null}

        <Pressable className="mt-4 items-center py-2" onPress={() => router.push('/(app)/search/images')}>
          <Text className="text-sm text-sky-400">Browse all images</Text>
        </Pressable>
      </ScrollView>
    </Screen>
  );
}
