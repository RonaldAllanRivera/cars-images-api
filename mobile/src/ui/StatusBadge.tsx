import { Text, View } from 'react-native';

interface Tone {
  bg: string;
  text: string;
}

const NEUTRAL: Tone = { bg: 'bg-slate-500/15', text: 'text-slate-300' };
const GOOD: Tone = { bg: 'bg-emerald-500/15', text: 'text-emerald-300' };
const BUSY: Tone = { bg: 'bg-sky-500/15', text: 'text-sky-300' };
const BAD: Tone = { bg: 'bg-red-500/15', text: 'text-red-300' };

/** Keyed by both review_status and download_status - they never collide. */
const TONE: Record<string, Tone> = {
  completed: GOOD,
  approved: GOOD,
  downloaded: GOOD,
  running: BUSY,
  downloading: BUSY,
  pending: NEUTRAL,
  not_downloaded: NEUTRAL,
  failed: BAD,
  rejected: BAD,
};

export function StatusBadge({ status }: { status: string }) {
  // The background belongs to the pill and the colour to the label; applying
  // one combined string to both paints a second background behind the text.
  const tone = TONE[status] ?? NEUTRAL;

  return (
    <View className={`self-start rounded-full px-2 py-0.5 ${tone.bg}`}>
      <Text className={`text-xs font-medium ${tone.text}`}>{status.replace(/_/g, ' ')}</Text>
    </View>
  );
}
