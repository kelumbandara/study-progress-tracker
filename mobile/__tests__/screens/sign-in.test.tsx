import { fireEvent, screen, waitFor } from '@testing-library/react-native';
import * as SecureStore from 'expo-secure-store';
import { useEffect } from 'react';

import { useAuth } from '@/auth/AuthContext';
import SignInScreen from '@/app/sign-in';
import { KEYS } from '@/storage/storage';

import { type FakeApi, fakeApi, renderApp, SERVER, seedSession } from '../../test-utils/helpers';

let api: FakeApi;
beforeEach(() => {
  api = fakeApi();
});
afterEach(() => api.restore());

const loginOk = { status: 200, body: { token: 'new-token', token_type: 'Bearer', expires_at: '2027-10-28T00:00:00+05:30', user: { name: 'Student', email: 'student@example.com' } } };

async function fill(server: string, email: string, password: string) {
  await fireEvent.changeText(screen.getByTestId('server'), server);
  await fireEvent.changeText(screen.getByTestId('email'), email);
  await fireEvent.changeText(screen.getByTestId('password'), password);
}

describe('<SignInScreen />', () => {
  test('shows what to enter, with the password hidden', async () => {
    await renderApp(<SignInScreen />, { when: 'signedOut' });
    expect(screen.getByText('ezexam')).toBeTruthy();
    expect(screen.getByTestId('password').props.secureTextEntry).toBe(true);
    expect(screen.getByLabelText('Show password')).toBeTruthy();
  });

  test('the password can be shown and hidden again', async () => {
    await renderApp(<SignInScreen />, { when: 'signedOut' });
    await fireEvent.press(screen.getByLabelText('Show password'));
    expect(screen.getByTestId('password').props.secureTextEntry).toBe(false);
    await fireEvent.press(screen.getByLabelText('Hide password'));
    expect(screen.getByTestId('password').props.secureTextEntry).toBe(true);
  });

  test('empty fields are caught on the phone without any network call', async () => {
    await renderApp(<SignInScreen />, { when: 'signedOut' });
    await fireEvent.press(screen.getByTestId('sign-in'));
    expect(await screen.findByText(/Enter the server address/)).toBeTruthy();
    expect(screen.getByText(/Enter your e-mail address/)).toBeTruthy();
    expect(screen.getByText(/Enter your password/)).toBeTruthy();
    expect(api.calls).toHaveLength(0);
  });

  test('an invalid server address is explained under the server field', async () => {
    await renderApp(<SignInScreen />, { when: 'signedOut' });
    await fill('not an address', 'a@b.co', 'secret');
    await fireEvent.press(screen.getByTestId('sign-in'));
    expect(await screen.findByText(/cannot contain spaces/i)).toBeTruthy();
    expect(api.calls).toHaveLength(0);
  });

  test('a wrong password shows the server message and keeps what was typed', async () => {
    api.on('POST', '/auth/login', { status: 422, body: { message: 'These details do not match our records.', errors: { email: ['These details do not match our records.'] } } });
    await renderApp(<SignInScreen />, { when: 'signedOut' });
    await fill(SERVER, 'student@example.com', 'wrong');
    await fireEvent.press(screen.getByTestId('sign-in'));

    expect(await screen.findByText(/do not match our records/)).toBeTruthy();
    expect(screen.getByTestId('email').props.value).toBe('student@example.com');
    expect(screen.getByTestId('server').props.value).toBe(SERVER);
    expect(await SecureStore.getItemAsync(KEYS.token)).toBeNull();
  });

  test('a rate-limited sign-in shows the wait message', async () => {
    api.on('POST', '/auth/login', { status: 422, body: { message: 'x', errors: { email: ['Too many sign-in attempts. Please try again in 42 seconds.'] } } });
    await renderApp(<SignInScreen />, { when: 'signedOut' });
    await fill(SERVER, 'student@example.com', 'x');
    await fireEvent.press(screen.getByTestId('sign-in'));
    expect(await screen.findByText(/try again in 42 seconds/)).toBeTruthy();
  });

  test('an unreachable server shows a connection problem, not a crash', async () => {
    api.on('POST', '/auth/login', { status: 0, networkError: true });
    await renderApp(<SignInScreen />, { when: 'signedOut' });
    await fill(SERVER, 'student@example.com', 'secret');
    await fireEvent.press(screen.getByTestId('sign-in'));
    expect(await screen.findByTestId('signin-error')).toBeTruthy();
    expect(screen.getByText(/Cannot reach the server/)).toBeTruthy();
  });

  test('a successful sign-in sends trimmed details and stores the session', async () => {
    api.on('POST', '/auth/login', loginOk);
    await renderApp(<SignInScreen />, { when: 'any' });
    await fill(`  ${SERVER}/  `, '  student@example.com ', 'a long password 1');
    await fireEvent.press(screen.getByTestId('sign-in'));

    await waitFor(() => expect(api.calls).toHaveLength(1));
    expect(api.calls[0]!.body).toMatchObject({ email: 'student@example.com', password: 'a long password 1' });
    await waitFor(async () => expect(await SecureStore.getItemAsync(KEYS.token)).toBe('new-token'));
    expect(await SecureStore.getItemAsync(KEYS.server)).toBe(SERVER);
  });

  test('the remembered server address is pre-filled', async () => {
    await SecureStore.setItemAsync(KEYS.server, SERVER);
    await renderApp(<SignInScreen />, { when: 'signedOut' });
    expect(screen.getByTestId('server').props.value).toBe(SERVER);
  });

  test('after the server ends the session the sign-in screen says why', async () => {
    await seedSession();
    api.on('GET', '/me', { status: 401, body: { message: 'Unauthenticated.' } });
    function EndSession() {
      const { api: a } = useAuth();
      useEffect(() => void a.me().catch(() => undefined), [a]);
      return null;
    }
    await renderApp(
      <>
        <EndSession />
        <SignInScreen />
      </>,
      { when: 'any' },
    );
    expect(await screen.findByTestId('signin-notice')).toBeTruthy();
    expect(screen.getByText(/Your session has ended/)).toBeTruthy();
  });
});
