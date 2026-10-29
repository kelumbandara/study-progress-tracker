import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react-native';
import * as SecureStore from 'expo-secure-store';
import type { ReactElement } from 'react';

import { AuthProvider, useAuth } from '@/auth/AuthContext';
import { KEYS } from '@/storage/storage';

export const SERVER = 'https://tracker.example.com';

interface Reply {
  status: number;
  body?: unknown;
  /** Reject like a dropped connection instead of answering. */
  networkError?: boolean;
}
type Handler = (url: URL, init: RequestInit, body: any) => Reply | undefined;

export interface FakeApi {
  calls: { origin: string; method: string; path: string; query: URLSearchParams; headers: Record<string, string>; body: any }[];
  on(method: string, path: string | RegExp, handler: Handler | Reply): void;
  restore(): void;
}

/** Replaces the global fetch with a scripted server. Unscripted requests fail loudly so a test cannot pass by accident. */
export function fakeApi(): FakeApi {
  const routes: { method: string; path: string | RegExp; handler: Handler }[] = [];
  const calls: FakeApi['calls'] = [];

  const spy = jest.spyOn(globalThis, 'fetch').mockImplementation((async (input: RequestInfo | URL, init: RequestInit = {}) => {
    const url = new URL(String(input));
    const method = (init.method ?? 'GET').toUpperCase();
    const path = url.pathname.replace('/api/v1', '');
    const body = init.body ? JSON.parse(String(init.body)) : undefined;
    calls.push({ origin: url.origin, method, path, query: url.searchParams, headers: (init.headers ?? {}) as Record<string, string>, body });

    for (const r of [...routes].reverse()) {
      if (r.method !== method) continue;
      if (typeof r.path === 'string' ? r.path !== path : !r.path.test(path)) continue;
      const reply = r.handler(url, init, body);
      if (!reply) continue;
      if (reply.networkError) throw new TypeError('Network request failed');
      const text = reply.body === undefined ? '' : JSON.stringify(reply.body);
      return { ok: reply.status >= 200 && reply.status < 300, status: reply.status, text: async () => text } as unknown as Response;
    }
    throw new Error(`Unscripted request: ${method} ${path}`);
  }) as typeof fetch);

  return {
    calls,
    on(method, path, handler) {
      routes.push({ method: method.toUpperCase(), path, handler: typeof handler === 'function' ? handler : () => handler });
    },
    restore: () => spy.mockRestore(),
  };
}

export async function seedSession(token = 'stored-token', server = SERVER) {
  await SecureStore.setItemAsync(KEYS.token, token);
  await SecureStore.setItemAsync(KEYS.server, server);
  await SecureStore.setItemAsync(KEYS.user, JSON.stringify({ name: 'Student', email: 'student@example.com' }));
}

export function makeClient() {
  return new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } } });
}

function Gate({ children, when }: { children: ReactElement; when: 'signedIn' | 'signedOut' | 'any' }) {
  const { status } = useAuth();
  if (status === 'loading') return null;
  if (when !== 'any' && status !== when) return null;
  return children;
}

/** Renders inside the real providers, once the stored session has been read. */
export async function renderApp(ui: ReactElement, { when = 'signedIn' }: { when?: 'signedIn' | 'signedOut' | 'any' } = {}) {
  const client = makeClient();
  const result = await render(
    <QueryClientProvider client={client}>
      <AuthProvider>
        <Gate when={when}>{ui}</Gate>
      </AuthProvider>
    </QueryClientProvider>,
  );
  return { ...result, client };
}

export const flush = () => waitFor(() => undefined);
export { screen };
