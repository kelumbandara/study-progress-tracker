// A RELEASE build: plain http must be refused. (The other test file runs as a development build, where it is allowed.)
jest.mock('@/config', () => ({
  DEFAULT_SERVER_URL: '',
  ALLOW_HTTP: false,
  API_PREFIX: '/api/v1',
  REQUEST_TIMEOUT_MS: 20000,
}));

import { act, waitFor } from '@testing-library/react-native';
import { Text } from 'react-native';

import type { ApiError } from '@/api/errors';
import { useAuth } from '@/auth/AuthContext';

import { fakeApi, renderApp } from '../../test-utils/helpers';

let auth!: ReturnType<typeof useAuth>;
function Probe() {
  auth = useAuth();
  return <Text testID="status">{auth.status}</Text>;
}

describe('release build', () => {
  test('an explicit http:// server is refused and nothing is sent', async () => {
    const api = fakeApi();
    const s = await renderApp(<Probe />, { when: 'any' });
    await waitFor(() => expect(s.getByTestId('status').props.children).toBe('signedOut'));

    for (const input of ['http://192.168.1.20:8080', 'http://tracker.example.com', 'HTTP://tracker.example.com/login']) {
      const err = (await act(async () => auth.signIn(input, 'a@b.co', 'pw').catch((e) => e))) as ApiError;
      expect(err.kind).toBe('validation');
      expect(err.message).toMatch(/https/i);
    }
    expect(api.calls).toHaveLength(0);
    api.restore();
  });

  test('an address typed without a scheme is treated as https, never downgraded to http', async () => {
    const api = fakeApi(); // no scripted routes: any request fails like an unreachable server
    const s = await renderApp(<Probe />, { when: 'any' });
    await waitFor(() => expect(s.getByTestId('status').props.children).toBe('signedOut'));

    const err = (await act(async () => auth.signIn('192.168.1.20:8080', 'a@b.co', 'pw').catch((e) => e))) as ApiError;
    expect(err.kind).toBe('network');
    expect(api.calls).toHaveLength(1);
    expect(api.calls[0]!.origin).toBe('https://192.168.1.20:8080');
    api.restore();
  });
});