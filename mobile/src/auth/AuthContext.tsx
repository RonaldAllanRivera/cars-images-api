import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';

import { ApiError, apiRequest, configureApiClient } from '../api/client';
import { LoginResponseSchema, single, UserSchema } from '../api/schemas';
import type { User } from '../api/schemas';
import { tokenStore } from './TokenStore';

type Status = 'loading' | 'authenticated' | 'anonymous';

interface AuthValue {
  status: Status;
  user: User | null;
  signIn: (email: string, password: string, deviceName: string) => Promise<void>;
  signOut: () => Promise<void>;
}

const AuthContext = createContext<AuthValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<Status>('loading');
  const [user, setUser] = useState<User | null>(null);

  // A ref, not state: the client reads the token synchronously on every
  // request, and a stale closure would send the previous token.
  const tokenRef = useRef<string | null>(null);

  const signOut = useCallback(async () => {
    if (tokenRef.current) {
      // Best effort. If the revoke fails the local token still goes - the
      // alternative is a signed-in shell holding a token the server may
      // already have forgotten.
      await apiRequest('/auth/logout', { method: 'POST' }).catch(() => undefined);
    }

    tokenRef.current = null;
    await tokenStore.clear();
    setUser(null);
    setStatus('anonymous');
  }, []);

  // Configured once, before the restore below can fire a request.
  useEffect(() => {
    configureApiClient({
      getToken: () => tokenRef.current,
      onUnauthorized: () => {
        void signOut();
      },
    });
  }, [signOut]);

  useEffect(() => {
    let cancelled = false;

    const restore = async () => {
      const stored = await tokenStore.get();

      if (!stored) {
        if (!cancelled) setStatus('anonymous');

        return;
      }

      tokenRef.current = stored;

      try {
        const me = await apiRequest('/auth/me', { schema: single(UserSchema) });

        if (cancelled) return;

        setUser(me.data);
        setStatus('authenticated');
      } catch (caught) {
        if (cancelled) return;

        // Only a 401 means the token is dead. Offline, a 500, or a CORS
        // origin the server does not allow all land here too, and throwing
        // the token away for those signs the user out permanently for a
        // transient failure - which on native defeats the point of storing
        // it in SecureStore at all. Keep it and stay anonymous for now.
        if (caught instanceof ApiError && caught.status === 401) {
          tokenRef.current = null;
          await tokenStore.clear();
        }

        setStatus('anonymous');
      }
    };

    void restore();

    return () => {
      cancelled = true;
    };
  }, []);

  const signIn = useCallback(async (email: string, password: string, deviceName: string) => {
    const result = await apiRequest('/auth/login', {
      method: 'POST',
      body: { email, password, device_name: deviceName },
      schema: LoginResponseSchema,
    });

    tokenRef.current = result.token;
    await tokenStore.set(result.token);
    setUser(result.user);
    setStatus('authenticated');
  }, []);

  const value = useMemo<AuthValue>(
    () => ({ status, user, signIn, signOut }),
    [status, user, signIn, signOut],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthValue {
  const value = useContext(AuthContext);

  if (!value) {
    throw new Error('useAuth must be used inside an AuthProvider.');
  }

  return value;
}
