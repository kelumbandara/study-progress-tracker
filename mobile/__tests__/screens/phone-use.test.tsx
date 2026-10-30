import { fireEvent, screen, waitFor } from '@testing-library/react-native';
import { Alert } from 'react-native';

import { forgetSharingCache } from '@/features/phoneUse/usePhoneUse';
import { PhoneUseScreen } from '@/features/phoneUse/PhoneUseScreen';
import { KEYS, storage } from '@/storage/storage';

import { getDay, isAccessGranted, openAccessSettings } from '../../modules/phone-usage';
import { type FakeApi, fakeApi, renderApp, seedSession } from '../../test-utils/helpers';

jest.mock('../../modules/phone-usage', () => ({
  phoneUsageSupported: true,
  isAccessGranted: jest.fn(),
  openAccessSettings: jest.fn(),
  getDay: jest.fn(),
}));

const TODAY = '2026-11-10';
const bootstrap = {
  server_time: '2026-11-10T20:00:00+05:30', today: TODAY, timezone: 'Asia/Colombo',
  study_period: { start: '2026-10-28', end: '2027-07-31', exam_date: null }, paper_years: { from: 2016, to: 2025 },
  subjects: [], options: { categories: [], topic_statuses: [], attempt_results: [], mistake_categories: [] },
  rules: { mastery_pct: 80, min_attempts_for_advice: 5, max_minutes_per_day: 1440 },
};
const apps = [{ package: 'com.whatsapp', label: 'WhatsApp', minutes: 45 }, { package: 'com.google.android.youtube', label: 'YouTube', minutes: 40 }];

let api: FakeApi;
beforeEach(async () => {
  forgetSharingCache();
  jest.mocked(isAccessGranted).mockReturnValue(false);
  jest.mocked(getDay).mockResolvedValue(apps);
  jest.mocked(openAccessSettings).mockClear();
  api = fakeApi();
  await seedSession();
  api.on('GET', '/bootstrap', { status: 200, body: bootstrap });
  api.on('PUT', new RegExp('^/phone-usage/'), { status: 200, body: { date: TODAY, apps_stored: 2, total_minutes: 85, synced_at: '2026-11-10T14:30:00+00:00' } });
  api.on('DELETE', '/phone-usage', { status: 200, body: { deleted_rows: 12 } });
});
afterEach(() => api.restore());

const press = async (id: string) => fireEvent.press(await screen.findByTestId(id));
const turnSharing = async (on: boolean) => fireEvent(await screen.findByTestId('pu-share'), 'valueChange', on);

describe('<PhoneUseScreen />', () => {
  test('explains exactly what is shared, and sharing is OFF until the student turns it on', async () => {
    await renderApp(<PhoneUseScreen />);
    expect((await screen.findByTestId('pu-what')).props.children).toMatch(/no messages, no links, no videos watched/);
    expect(screen.getByTestId('pu-share').props.value).toBe(false);
    expect(screen.queryByTestId('pu-send')).toBeNull();
    expect(api.calls.some((c) => c.path.startsWith('/phone-usage'))).toBe(false);   // nothing is sent while sharing is off
  });

  test('turning sharing on without Android permission asks for it and opens the right settings page', async () => {
    await renderApp(<PhoneUseScreen />);
    await turnSharing(true);
    expect(await screen.findByTestId('pu-need-access')).toBeTruthy();
    expect(screen.queryByTestId('pu-send')).toBeNull();
    await press('pu-open-settings');
    expect(openAccessSettings).toHaveBeenCalledTimes(1);
    expect(await storage.get(KEYS.phoneShare)).toBe('1');
    expect(api.calls.some((c) => c.method === 'PUT')).toBe(false);   // still nothing sent without permission
  });

  test('with permission it shows today so far, and "Send now" sends the day to the server', async () => {
    jest.mocked(isAccessGranted).mockReturnValue(true);
    await renderApp(<PhoneUseScreen />);
    await turnSharing(true);

    expect((await screen.findByTestId('pu-today-total')).props.children).toBe('1 h 25 min');
    expect(screen.getByText('WhatsApp')).toBeTruthy();
    expect(screen.getByText('YouTube')).toBeTruthy();

    await press('pu-send');
    await waitFor(() => expect(api.calls.some((c) => c.method === 'PUT')).toBe(true));
    const put = api.calls.filter((c) => c.method === 'PUT').pop()!;
    expect(put.path).toBe(`/phone-usage/${TODAY}`);
    expect(put.body).toEqual({ apps });   // only names, ids and minutes
    expect(Object.keys(put.body.apps[0]).sort()).toEqual(['label', 'minutes', 'package']);
    expect(await storage.get(KEYS.phoneLast)).toBe(TODAY);
  });

  test('a failed send is shown and nothing is marked as sent', async () => {
    jest.mocked(isAccessGranted).mockReturnValue(true);
    api.on('PUT', new RegExp('^/phone-usage/'), { status: 0, networkError: true });
    await renderApp(<PhoneUseScreen />);
    await turnSharing(true);
    await press('pu-send');
    expect(await screen.findByTestId('pu-send-error')).toBeTruthy();
    expect(await storage.get(KEYS.phoneLast)).toBeNull();
  });

  test('"Stop sharing and erase" deletes on the server, turns sharing off and forgets the last day sent', async () => {
    jest.mocked(isAccessGranted).mockReturnValue(true);
    jest.spyOn(Alert, 'alert').mockImplementation((_t, _m, buttons) => buttons?.[1]?.onPress?.());
    await storage.set(KEYS.phoneLast, TODAY);
    await renderApp(<PhoneUseScreen />);
    await turnSharing(true);
    await press('pu-erase');

    expect(await screen.findByText(/12 saved rows were deleted/)).toBeTruthy();
    expect(api.calls.some((c) => c.method === 'DELETE' && c.path === '/phone-usage')).toBe(true);
    expect(await storage.get(KEYS.phoneShare)).toBeNull();
    expect(await storage.get(KEYS.phoneLast)).toBeNull();
    expect(screen.getByTestId('pu-share').props.value).toBe(false);
  });

  test('cancelling the erase question changes nothing', async () => {
    jest.spyOn(Alert, 'alert').mockImplementation((_t, _m, buttons) => buttons?.[0]?.onPress?.());
    await renderApp(<PhoneUseScreen />);
    await press('pu-erase');
    await waitFor(() => expect(Alert.alert).toHaveBeenCalled());
    expect(api.calls.some((c) => c.method === 'DELETE')).toBe(false);
  });
});
