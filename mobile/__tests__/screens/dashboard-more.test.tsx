import { fireEvent, screen, waitFor } from '@testing-library/react-native';
import * as SecureStore from 'expo-secure-store';
import { Alert } from 'react-native';

import MoreScreen from '@/app/(app)/more';
import { DashboardScreen } from '@/features/dashboard/DashboardScreen';
import { KEYS } from '@/storage/storage';

import { type FakeApi, fakeApi, renderApp, SERVER, seedSession } from '../../test-utils/helpers';

let api: FakeApi;
beforeEach(async () => {
  api = fakeApi();
  await seedSession();
});
afterEach(() => {
  api.restore();
  jest.restoreAllMocks();
});

const category = (key: string, label: string, over: object = {}) => ({
  key, label, configured: false, status: 'not_configured', planned: null, actual: 0, remaining: null, overrun: 0, percent: null, required_weekly: null, warnings: [], ...over,
});

const dashboard = (over: object = {}) => ({
  today: '2026-11-10', today_logged: true,
  period: { start: '2026-10-28', end: '2027-07-31', total_days: 277, elapsed_days: 13, remaining_days: 264, percent_elapsed: 4.7, not_started: false, ended: false },
  categories: [
    category('school', 'School', { actual: 1980 }),
    category('tuition', 'Tuition'),
    category('theory', 'Study Theory', { configured: true, status: 'behind', planned: 18000, actual: 450, remaining: 17550, percent: 2.5, required_weekly: 3989, warnings: ['Behind the linear plan by 14 h 30 min.'] }),
    category('papers', 'Write Exam Papers', { configured: true, status: 'on_track', planned: 15000, actual: 1200, remaining: 13800, percent: 8, required_weekly: 3500 }),
  ],
  totals: { configured_count: 2, planned: 33000, actual_all: 3630, remaining: 31350, percent: 5, expected: 1500 },
  consistency: { days_logged: 10, days_missing: 3, days_in_range: 13 },
  recommendations: [{ level: 'warn', text: 'Study Theory is behind plan.' }, { level: 'info', text: 'Combined Mathematics: Not enough data yet.' }, { level: 'good', text: 'IT is consistently strong.' }],
  ...over,
});

describe('<DashboardScreen />', () => {
  test('shows the period, each category honestly, and the advice', async () => {
    api.on('GET', '/dashboard', { status: 200, body: dashboard() });
    await renderApp(<DashboardScreen />);

    expect(await screen.findByTestId('period-text')).toBeTruthy();
    expect(screen.getByText(/264 days left/)).toBeTruthy();
    expect(screen.getByText(/10 days logged · 3 missing \(not counted as zero\)/)).toBeTruthy();

    // not configured: no made-up percentage
    expect(screen.getByText('33.0 h recorded. Planned hours are not set yet.')).toBeTruthy();
    expect(screen.getAllByText('Not configured').length).toBe(2);
    // configured: hours, percentage and a status word
    expect(screen.getByText('7.5 h of 300.0 h (2.5%)')).toBeTruthy();
    expect(screen.getByText('Behind plan')).toBeTruthy();
    expect(screen.getByText('On track')).toBeTruthy();
    expect(screen.getByText(/About 66.5 h per week needed from now/)).toBeTruthy();
    expect(screen.getByText(/Behind the linear plan by 14 h 30 min/)).toBeTruthy();

    expect(screen.getByText('Study Theory is behind plan.')).toBeTruthy();
    expect(screen.getByText(/Not enough data yet/)).toBeTruthy();
    expect(screen.getByText(/consistently strong/)).toBeTruthy();
    expect(screen.getByText(/not a prediction of any result/)).toBeTruthy();
    expect(screen.queryByTestId('today-missing')).toBeNull();
  });

  test('reminds the student when today is not logged', async () => {
    api.on('GET', '/dashboard', { status: 200, body: dashboard({ today_logged: false }) });
    await renderApp(<DashboardScreen />);
    expect(await screen.findByTestId('today-missing')).toBeTruthy();
  });

  test('says so when the study period has ended', async () => {
    api.on('GET', '/dashboard', { status: 200, body: dashboard({ period: { ...dashboard().period, ended: true, remaining_days: 0, percent_elapsed: 100 } }) });
    await renderApp(<DashboardScreen />);
    expect(await screen.findByText(/The study period has ended/)).toBeTruthy();
  });

  test('a failure is explained and can be retried', async () => {
    let up = false;
    api.on('GET', '/dashboard', () => (up ? { status: 200, body: dashboard() } : { status: 502, body: {} }));
    await renderApp(<DashboardScreen />);
    expect(await screen.findByText(/Cannot load the dashboard/)).toBeTruthy();
    up = true;
    await fireEvent.press(screen.getByText('Try again'));
    expect(await screen.findByTestId('period-text')).toBeTruthy();
  });
});

describe('<MoreScreen />', () => {
  test('shows the account and the server', async () => {
    await renderApp(<MoreScreen />);
    expect(screen.getByTestId('account-email').props.children).toBe('student@example.com');
    expect(screen.getByTestId('account-server').props.children).toBe(SERVER);
    expect(screen.getByText(/records are stored on the server/)).toBeTruthy();
  });

  test('"Test connection" reports success and failure', async () => {
    api.on('GET', '/me', { status: 200, body: { user: { name: 'Student', email: 'student@example.com' } } });
    await renderApp(<MoreScreen />);
    await fireEvent.press(screen.getByTestId('test-connection'));
    expect(await screen.findByText(/Connected to the server as student@example.com/)).toBeTruthy();

    api.on('GET', '/me', { status: 0, networkError: true });
    await fireEvent.press(screen.getByTestId('test-connection'));
    expect(await screen.findByText(/Cannot reach the server/)).toBeTruthy();
  });

  test('signing out asks first; cancelling changes nothing', async () => {
    const alert = jest.spyOn(Alert, 'alert').mockImplementation((_t, _m, buttons) => void buttons?.find((b) => b.style === 'cancel')?.onPress?.());
    await renderApp(<MoreScreen />);
    await fireEvent.press(screen.getByTestId('sign-out'));
    await waitFor(() => expect(alert).toHaveBeenCalledTimes(1));
    expect(alert.mock.calls[0]![0]).toBe('Sign out?');
    expect(api.calls.filter((c) => c.path === '/auth/logout')).toHaveLength(0);
    expect(await SecureStore.getItemAsync(KEYS.token)).toBe('stored-token');
  });

  test('confirming signs out: the server is told and the phone forgets the token', async () => {
    jest.spyOn(Alert, 'alert').mockImplementation((_t, _m, buttons) => void buttons?.find((b) => b.style === 'destructive')?.onPress?.());
    api.on('POST', '/auth/logout', { status: 200, body: { message: 'Signed out.' } });
    await renderApp(<MoreScreen />, { when: 'any' });
    await fireEvent.press(screen.getByTestId('sign-out'));
    await waitFor(async () => expect(await SecureStore.getItemAsync(KEYS.token)).toBeNull());
    expect(api.calls.some((c) => c.path === '/auth/logout' && c.headers.Authorization === 'Bearer stored-token')).toBe(true);
  });
});
