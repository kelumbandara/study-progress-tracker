import type { AppUse } from '@/api/types';
import { datesToSync, syncPhoneUse } from '@/features/phoneUse/sync';

describe('datesToSync', () => {
  const today = '2026-11-10';

  test('the first time it sends the last week, today included', () => {
    expect(datesToSync(today, null)).toEqual(['2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08', '2026-11-09', '2026-11-10']);
  });

  test('afterwards it resends the last day sent (it may have been partial) and everything since', () => {
    expect(datesToSync(today, '2026-11-09')).toEqual(['2026-11-09', '2026-11-10']);
    expect(datesToSync(today, '2026-11-10')).toEqual(['2026-11-10']);
  });

  test('never more than a week back, however long the app was not opened', () => {
    const d = datesToSync(today, '2026-09-01');
    expect(d).toHaveLength(7);
    expect(d[0]).toBe('2026-11-04');
  });

  test('a last-sent date in the future (wrong clock) can never produce a future day', () => {
    expect(datesToSync(today, '2027-01-01')).toEqual(['2026-11-10']);
  });
});

describe('syncPhoneUse', () => {
  const apps = (m: number): AppUse[] => [{ package: 'com.whatsapp', label: 'WhatsApp', minutes: m }];

  test('sends each day with what the phone measured for that day, in the server time zone', async () => {
    const getDay = jest.fn(async (date: string) => apps(date.endsWith('10') ? 45 : 20));
    const send = jest.fn(async () => ({}));
    const r = await syncPhoneUse({ getDay, send, today: '2026-11-10', zoneId: 'Asia/Colombo', lastSent: '2026-11-09' });

    expect(r).toEqual({ sent: ['2026-11-09', '2026-11-10'], lastSent: '2026-11-10', error: null });
    expect(getDay).toHaveBeenCalledWith('2026-11-09', 'Asia/Colombo');
    expect(send).toHaveBeenNthCalledWith(1, '2026-11-09', apps(20));
    expect(send).toHaveBeenNthCalledWith(2, '2026-11-10', apps(45));
  });

  test('a failure stops the run, keeps what already arrived, and the next run continues from there', async () => {
    let fail = true;
    const send = jest.fn(async (date: string) => {
      if (date === '2026-11-09' && fail) throw new Error('no connection');
    });
    const getDay = jest.fn(async () => apps(5));

    const first = await syncPhoneUse({ getDay, send, today: '2026-11-10', zoneId: 'Asia/Colombo', lastSent: '2026-11-07' });
    expect(first.sent).toEqual(['2026-11-07', '2026-11-08']);
    expect(first.lastSent).toBe('2026-11-08');
    expect((first.error as Error).message).toBe('no connection');
    expect(send).not.toHaveBeenCalledWith('2026-11-10', expect.anything());

    fail = false;
    const second = await syncPhoneUse({ getDay, send, today: '2026-11-10', zoneId: 'Asia/Colombo', lastSent: first.lastSent });
    expect(second.sent).toEqual(['2026-11-08', '2026-11-09', '2026-11-10']);
    expect(second.error).toBeNull();
  });

  test('a day with no app use is still sent, so the server records it as a real empty day', async () => {
    const send = jest.fn(async () => ({}));
    await syncPhoneUse({ getDay: async () => [], send, today: '2026-11-10', zoneId: 'Asia/Colombo', lastSent: '2026-11-10' });
    expect(send).toHaveBeenCalledWith('2026-11-10', []);
  });
});
