import { act, waitFor } from '@testing-library/react-native';
import * as SecureStore from 'expo-secure-store';
import { Text } from 'react-native';

import { ApiError } from '@/api/errors';
import { useAuth } from '@/auth/AuthContext';
import { KEYS } from '@/storage/storage';

import { type FakeApi, fakeApi, renderApp, SERVER, seedSession } from '../../test-utils/helpers';

let api: FakeApi;
let auth!: ReturnType<typeof useAuth>;

function Probe() {
  auth = useAuth();
  return <Text testID="status">{`${auth.status}|${auth.user?.email ?? ''}|${auth.serverUrl}|${auth.notice ?? ''}`}</Text>;
}

const status = (screen: { getByTestId: (id: string) => any }) => String(screen.getByTestId('status').props.children);

const loginOk = { status: 200, body: { token: 'new-token', token_type: 'Bearer', expires_at: '2027-10-28T00:00:00+05:30', user: { name: 'Student', email: 'student@example.com' } } };

beforeEach(() => {
  api = fakeApi();
});
afterEach(() => api.restore());

describe('AuthProvider', () => {
  test('restores a stored session at start, without any network call', async () => {
    await seedSession();
    const s = await renderApp(<Probe />, { when: 'any' });
    await waitFor(() => expect(status(s)).toBe(`signedIn|student@example.com|${SERVER}|`));
    expect(api.calls).toHaveLength(0);
  });

  test('with nothing stored it is signed out; a remembered server address is kept for the sign-in screen', async () => {
    await SecureStore.setItemAsync(KEYS.server, SERVER);
    const s = await renderApp(<Probe />, { when: 'any' });
    await waitFor(() => expect(status(s)).toBe(`signedOut||${SERVER}|`));
  });

  test('a token without a server address is not a session', async () => {
    await SecureStore.setItemAsync(KEYS.token, 'orphan');
    const s = await renderApp(<Probe />, { when: 'any' });
    await waitFor(() => expect(status(s)).toMatch(/^signedOut/));
  });

  test('a damaged stored user is tolerated', async () => {
    await seedSession();
    await SecureStore.setItemAsync(KEYS.user, '{not json');
    const s = await renderApp(<Probe />, { when: 'any' });
    await waitFor(() => expect(status(s)).toBe(`signedIn||${SERVER}|`));
  });

  test('signing in stores the token, user and server, and sends no token itself', async () => {
    api.on('POST', '/auth/login', loginOk);
    const s = await renderApp(<Probe />, { when: 'any' });
    await waitFor(() => expect(status(s)).toMatch(/^signedOut/));

    await act(async () => {
      await auth.signIn(' tracker.example.com/login ', ' Student@Example.com ', 'a long password 1');
    });

    expect(status(s)).toBe(`signedIn|student@example.com|${SERVER}|`);
    expect(await SecureStore.getItemAsync(KEYS.token)).toBe('new-token');
    expect(await SecureStore.getItemAsync(KEYS.server)).toBe(SERVER);
    expect(JSON.parse((await SecureStore.getItemAsync(KEYS.user)) ?? '{}')).toEqual({ name: 'Student', email: 'student@example.com' });

    const call = api.calls[0]!;
    expect(call.path).toBe('/auth/login');
    expect(call.headers.Authorization).toBeUndefined();
    expect(call.body).toMatchObject({ email: 'Student@Example.com', password: 'a long password 1' });
    expect(call.body.device_name).toMatch(/ezexam/);
  });

  test('a wrong password leaves the phone signed out with nothing stored, and shows the server message', async () => {
    api.on('POST', '/auth/login', { status: 422, body: { message: 'These details do not match our records.', errors: { email: ['These details do not match our records.'] } } });
    const s = await renderApp(<Probe />, { when: 'any' });
    await waitFor(() => expect(status(s)).toMatch(/^signedOut/));

    const err = await act(async () => auth.signIn(SERVER, 'a@b.co', 'nope').catch((e) => e));
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).fieldMessage('email')).toMatch(/do not match/);
    expect(status(s)).toMatch(/^signedOut/);
    expect(await SecureStore.getItemAsync(KEYS.token)).toBeNull();
    expect(await SecureStore.getItemAsync(KEYS.server)).toBeNull(); // a failed attempt must not "remember" the server as connected
  });

  test('a bad server address is refused before any request is made', async () => {
    const s = await renderApp(<Probe />, { when: 'any' });
    await waitFor(() => expect(status(s)).toMatch(/^signedOut/));
    const err = (await act(async () => auth.signIn('not an address', 'a@b.co', 'pw').catch((e) => e))) as ApiError;
    expect(err.kind).toBe('validation');
    expect(err.fieldMessage('server')).toBeTruthy();
    expect(api.calls).toHaveLength(0);
  });

  test('an unreachable server is reported as a connection problem', async () => {
    api.on('POST', '/auth/login', { status: 0, networkError: true });
    const s = await renderApp(<Probe />, { when: 'any' });
    await waitFor(() => expect(status(s)).toMatch(/^signedOut/));
    const err = (await act(async () => auth.signIn(SERVER, 'a@b.co', 'pw').catch((e) => e))) as ApiError;
    expect(err.kind).toBe('network');
    expect(status(s)).toMatch(/^signedOut/);
  });

  test('signing out revokes the token on the server and clears everything on the phone', async () => {
    await seedSession('abc');
    api.on('POST', '/auth/logout', { status: 200, body: { message: 'Signed out.' } });
    const s = await renderApp(<Probe />, { when: 'any' });
    await waitFor(() => expect(status(s)).toMatch(/^signedIn/));

    await act(async () => {
      await auth.signOut();
    });

    expect(status(s)).toBe(`signedOut||${SERVER}|`); // no notice: the person chose to sign out
    expect(api.calls[0]).toMatchObject({ method: 'POST', path: '/auth/logout' });
    expect(api.calls[0]!.headers.Authorization).toBe('Bearer abc');
    expect(await SecureStore.getItemAsync(KEYS.token)).toBeNull();
    expect(await SecureStore.getItemAsync(KEYS.user)).toBeNull();
  });

  test('signing out works even when the server cannot be reached', async () => {
    await seedSession();
    api.on('POST', '/auth/logout', { status: 0, networkError: true });
    const s = await renderApp(<Probe />, { when: 'any' });
    await waitFor(() => expect(status(s)).toMatch(/^signedIn/));
    await act(async () => {
      await auth.signOut();
    });
    expect(status(s)).toMatch(/^signedOut/);
    expect(await SecureStore.getItemAsync(KEYS.token)).toBeNull();
  });

  test('when the server rejects the token the phone signs out and says why', async () => {
    await seedSession();
    api.on('GET', '/dashboard', { status: 401, body: { message: 'Unauthenticated.' } });
    const s = await renderApp(<Probe />, { when: 'any' });
    await waitFor(() => expect(status(s)).toMatch(/^signedIn/));

    const err = (await act(async () => auth.api.dashboard().catch((e) => e))) as ApiError;
    expect(err.kind).toBe('unauthorized');
    await waitFor(() => expect(status(s)).toBe(`signedOut||${SERVER}|Your session has ended. Please sign in again.`));
    expect(await SecureStore.getItemAsync(KEYS.token)).toBeNull();
  });

  test('several calls failing with 401 at once end the session once, without errors', async () => {
    await seedSession();
    api.on('GET', /.*/, { status: 401, body: { message: 'Unauthenticated.' } });
    const s = await renderApp(<Probe />, { when: 'any' });
    await waitFor(() => expect(status(s)).toMatch(/^signedIn/));
    await act(async () => {
      await Promise.all([auth.api.dashboard().catch(() => 0), auth.api.bootstrap().catch(() => 0), auth.api.me().catch(() => 0)]);
    });
    await waitFor(() => expect(status(s)).toMatch(/^signedOut\|\|.*\|Your session has ended/));
  });

  test('signing in again after a rejected session clears the notice', async () => {
    await seedSession();
    api.on('GET', '/me', { status: 401, body: {} });
    api.on('POST', '/auth/login', loginOk);
    const s = await renderApp(<Probe />, { when: 'any' });
    await waitFor(() => expect(status(s)).toMatch(/^signedIn/));
    await act(async () => void (await auth.api.me().catch(() => 0)));
    await waitFor(() => expect(status(s)).toMatch(/Your session has ended/));
    await act(async () => {
      await auth.signIn(SERVER, 'student@example.com', 'a long password 1');
    });
    expect(status(s)).toBe(`signedIn|student@example.com|${SERVER}|`);
  });
});
