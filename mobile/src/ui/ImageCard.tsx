import { Image } from 'expo-image';
import { Link } from 'expo-router';
import type { Href } from 'expo-router';
import { Pressable, Text, View } from 'react-native';

import type { Image as CarImage } from '@/api/schemas';
import { StatusBadge } from './StatusBadge';

const SHELL = 'mb-3 flex-1 overflow-hidden rounded-xl bg-slate-800';

/**
 * Presentational. `href` is supplied by the screen rather than hardcoded here:
 * each tab owns its own stack, so a destination baked into this component
 * would push onto whichever stack it names and yank the user out of the tab
 * they were in. Omit it for a card that should not navigate.
 */
export function ImageCard({ image, href }: { image: CarImage; href?: Href }) {
  const body = (
    <>
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
    </>
  );

  if (!href) {
    return <View className={SHELL}>{body}</View>;
  }

  return (
    <Link href={href} asChild>
      <Pressable className={`${SHELL} active:opacity-80`}>{body}</Pressable>
    </Link>
  );
}
