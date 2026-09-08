import { Link } from 'expo-router';
import { Text, View } from 'react-native';

export default function Landing() {
  return (
    <View className="flex-1 items-center justify-center gap-4 bg-slate-900 p-6">
      <Text className="text-center text-3xl font-bold text-white">Cars Images</Text>
      <Text className="max-w-md text-center text-base text-slate-300">
        Search Wikimedia Commons for high-resolution car photography by make, model and year,
        review what comes back, and watch the harvest pipeline&apos;s health.
      </Text>
      <Link href="/login" className="mt-4 rounded-lg bg-sky-500 px-6 py-3 text-base font-semibold text-white">
        Sign in
      </Link>
    </View>
  );
}
