import { Image } from 'expo-image';
import { useLocalSearchParams } from 'expo-router';
import { ActivityIndicator, ScrollView, Text, View } from 'react-native';

import { useImage } from '@/api/hooks/useImages';
import { ErrorBanner } from '@/ui/ErrorBanner';
import { Screen } from '@/ui/Screen';
import { StatusBadge } from '@/ui/StatusBadge';

function Row({ label, value }: { label: string; value: string | null }) {
  if (!value) return null;

  return (
    <View className="border-b border-slate-800 py-2">
      <Text className="text-xs uppercase tracking-wide text-slate-500">{label}</Text>
      <Text className="text-sm text-slate-200">{value}</Text>
    </View>
  );
}

export default function ImageDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const query = useImage(Number(id));

  if (query.isLoading) {
    return (
      <Screen>
        <ActivityIndicator className="mt-8" color="#38bdf8" />
      </Screen>
    );
  }

  if (query.isError || !query.data) {
    return (
      <Screen>
        <ErrorBanner message={query.error instanceof Error ? query.error.message : 'Not found.'} />
      </Screen>
    );
  }

  const image = query.data;

  return (
    <Screen>
      <ScrollView>
        <Image
          source={image.source_url}
          style={{ width: '100%', aspectRatio: 4 / 3, borderRadius: 12 }}
          contentFit="contain"
          transition={150}
          cachePolicy="disk"
        />

        <Text className="mt-3 text-lg font-bold text-white">
          {image.make} {image.model ?? ''} {image.year}
        </Text>

        <View className="mt-2 flex-row gap-2">
          <StatusBadge status={image.review_status} />
          <StatusBadge status={image.download_status} />
        </View>

        <View className="mt-4">
          <Row label="Title" value={image.title} />
          <Row label="Description" value={image.description} />
          <Row label="Licence" value={image.license} />
          <Row label="Attribution" value={image.attribution} />
          <Row
            label="Dimensions"
            value={image.width && image.height ? `${image.width} × ${image.height}` : null}
          />
          <Row label="Source" value={image.source_url} />
          <Row
            label="Machine verdict"
            value={`make ${describe(image.make_confirmed)}, year ${describe(image.year_confirmed)}`}
          />
        </View>
      </ScrollView>
    </Screen>
  );
}

/** null is the machine's "unknown" - neither confirmed nor rejected. */
function describe(flag: boolean | null): string {
  if (flag === null) return 'unchecked';

  return flag ? 'confirmed' : 'not confirmed';
}
