import { useQueryClient } from '@tanstack/react-query';
import Constants from 'expo-constants';
import { createContext, type PropsWithChildren, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { Platform } from 'react-native';

import { createApiClient } from '@/api/client';
import { createEndpoints, type Endpoints } from '@/api/endpoints';
import { ApiError } from '@/api/errors';
import type { User } from '@/api/types';
import { ALLOW_HTTP, DEFAULT_SERVER_URL } from '@/config';
import { normalizeServerUrl } from '@/lib/server-url';
import { KEYS, storage } from '@/storage/storage';

type Status = 'loading' | 'signedOut' | 'signedIn';

interface AuthContextValue {
  status: Status;
  user: User | null;
  /** The server this phone is connected to (kept after sign-out so the sign-in screen can pre-fill it). */
  serverUrl: string;
  /** Set when the session ended on its own (revoked or expired), so the sign-in screen can say why. */
  notice: string | null;
  api: Endpoints;
  signIn(serverInput: string, email: string, password: string): Promise<void>;
  signOut(): Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

function deviceName(): string {
  const name = Platform.OS === 'web' ? 'Web browser' : (Constants.deviceName ?? 'Android phone');
  return `${name} (A/L Tracker)`.slice(0, 100);
}

/** Waits for a promise to finish (success or failure) but never longer than `ms`; the timer is cancelled as soon as it finishes. */
function settleWithin(promise: Promise<unknown>, ms: number): Promise<void> {
  return new Promise((resolve) => {
    const timer = setTimeout(resolve, ms);
    const done = () => {
      clearTimeout(timer);
      resolve();
    };
    promise.then(done, done);
  });
}

/** The sign-in session: restored from secure storage at start, cleared on sign-out or when the server rejects the token. */
export function AuthProvider({ children }: PropsWithChildren) {
  const queryClient = useQueryClient();
  const [status, setStatus] = useState<Status>('loading');
  const [user, setUser] = useState<User | null>(null);
  const [serverUrl, setServerUrl] = useState(DEFAULT_SERVER_URL);
  const [notice, setNotice] = useState<string | null>(null);

  // The API client reads these on every call, so it never uses a stale token.
  const tokenRef = useRef<string | null>(null);
  const baseRef = useRef<string | null>(DEFAULT_SERVER_URL || null);

  const endSession = useCallback(
    async (why?: string) => {
      if (tokenRef.current === null && why) return; // several calls failing together must not repeat this
      tokenRef.current = null;
      await storage.remove(KEYS.token);
      await storage.remove(KEYS.user);
      queryClient.clear();
      setUser(null);
      setStatus('signedOut');
      if (why) setNotice(why);
    },
    [queryClient],
  );

  const api = useMemo(
    () =>
      createEndpoints(
        createApiClient({
          getBaseUrl: () => baseRef.current,
          getToken: () => tokenRef.current,
          onUnauthorized: () => void endSession('Your session has ended. Please sign in again.'),
        }),
      ),
    [endSession],
  );

  // Restore the session when the app opens.
  useEffect(() => {
    let cancelled = false;
    (async () => {
      const [token, savedUser, server] = await Promise.all([storage.get(KEYS.token), storage.get(KEYS.user), storage.get(KEYS.server)]);
      if (cancelled) return;
      if (server) {
        baseRef.current = server;
        setServerUrl(server);
      }
      if (token && server) {
        tokenRef.current = token;
        try {
          setUser(savedUser ? (JSON.parse(savedUser) as User) : null);
        } catch {
          setUser(null);
        }
        setStatus('signedIn');
      } else {
        setStatus('signedOut');
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  const signIn = useCallback(
    async (serverInput: string, email: string, password: string) => {
      const checked = normalizeServerUrl(serverInput, ALLOW_HTTP);
      if (!checked.ok) throw new ApiError('validation', checked.error, 0, { server: [checked.error] });

      const previousBase = baseRef.current;
      baseRef.current = checked.url;
      try {
        const res = await api.login(email.trim(), password, deviceName());
        tokenRef.current = res.token;
        await storage.set(KEYS.token, res.token);
        await storage.set(KEYS.user, JSON.stringify(res.user));
        await storage.set(KEYS.server, checked.url);
        setServerUrl(checked.url);
        setUser(res.user);
        setNotice(null);
        setStatus('signedIn');
      } catch (e) {
        baseRef.current = previousBase; // a failed attempt must not change which server later calls go to
        throw e;
      }
    },
    [api],
  );

  const signOut = useCallback(async () => {
    // Ask the server to revoke this phone's token, but never make the person wait for it: sign out locally regardless.
    await settleWithin(api.logout(), 3000);
    await endSession();
  }, [api, endSession]);

  const value = useMemo(() => ({ status, user, serverUrl, notice, api, signIn, signOut }), [status, user, serverUrl, notice, api, signIn, signOut]);
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>');
  return ctx;
}
