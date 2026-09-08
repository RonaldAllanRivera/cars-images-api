import { Image } from 'expo-image';
import { Link } from 'expo-router';
import { Pressable, Text, View } from 'react-native';

import type { Image as CarImage } from '@/api/schemas';
import { StatusBadge } from './StatusBadge';

export function ImageCard({ image }: { image: CarImage }) {
  return (
    <Link href={{ pathname: '/(app)/search/[id]', params: { id: image.id } }} asChild>
      <Pressable className="mb-3 flex-1 overflow-hidden rounded-xl bg-slate-800 active:opacity-80">
        <Image
          source={image.thumbnail_url ?? image.source_url}
          style={{ width: '100%', aspectRatio: 4 / 3 }}
          contentFit="cover"
          transition={150}
          cachePolicy="disk"
        />
        <View className="gap-1 p-2">
          <Text className="text-sm font-medium text-white" numberOfLines={1}>
            {image.make} {image.model ?? ''} {image.year}
          </Text>
          <StatusBadge status={image.review_status} />
        </View>
      </Pressable>
    </Link>
  );
}
