import { router } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Platform, Pressable, Text, TextInput, View } from 'react-native';

import { ApiValidationError } from '@/api/client';
import { useAuth } from '@/auth/AuthContext';
import { ErrorBanner } from '@/ui/ErrorBanner';
import { Screen } from '@/ui/Screen';

/** Names the token so a lost device can be revoked without rotating the rest. */
const DEVICE_NAME = Platform.select({ web: 'web', android: 'android', ios: 'ios' }) ?? 'unknown';

export default function Login() {
  const { signIn } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setBusy(true);
    setError(null);

    try {
      await signIn(email.trim(), password, DEVICE_NAME);
      router.replace('/(app)/search');
    } catch (caught) {
      setError(
        caught instanceof ApiValidationError
          ? (caught.first('email') ?? caught.message)
          : caught instanceof Error
            ? caught.message
            : 'Sign in failed.',
      );
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <View className="flex-1 justify-center gap-3">
        <Text className="mb-2 text-2xl font-bold text-white">Sign in</Text>

        {error ? <ErrorBanner message={error} /> : null}

        <TextInput
          className="rounded-lg bg-slate-800 px-4 py-3 text-white"
          placeholder="Email"
          placeholderTextColor="#94a3b8"
          autoCapitalize="none"
          autoComplete="email"
          keyboardType="email-address"
          value={email}
          onChangeText={setEmail}
        />

        <TextInput
          className="rounded-lg bg-slate-800 px-4 py-3 text-white"
          placeholder="Password"
          placeholderTextColor="#94a3b8"
          autoCapitalize="none"
          secureTextEntry
          value={password}
          onChangeText={setPassword}
          onSubmitEditing={submit}
        />

        <Pressable
          className="mt-2 items-center rounded-lg bg-sky-500 px-6 py-3 active:opacity-80"
          disabled={busy}
          onPress={submit}
        >
          {busy ? (
            <ActivityIndicator color="white" />
          ) : (
            <Text className="text-base font-semibold text-white">Sign in</Text>
          )}
        </Pressable>

        <Text className="mt-4 text-center text-xs text-slate-400">
          Credentials are issued on request. There is no demo account.
        </Text>
      </View>
    </Screen>
  );
}
