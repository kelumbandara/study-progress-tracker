import { addDays, formatLongDate, formatShortDate, isAfter, isIsoDate } from '@/lib/dates';

describe('dates', () => {
  test('recognises real calendar dates only', () => {
    expect(isIsoDate('2026-11-09')).toBe(true);
    expect(isIsoDate('2028-02-29')).toBe(true);   // leap day
    expect(isIsoDate('2027-02-29')).toBe(false);  // not a leap year
    expect(isIsoDate('2026-02-30')).toBe(false);
    expect(isIsoDate('2026-13-01')).toBe(false);
    expect(isIsoDate('2026-1-9')).toBe(false);
    expect(isIsoDate('09/11/2026')).toBe(false);
    expect(isIsoDate('')).toBe(false);
    expect(isIsoDate('2026-11-09T00:00:00Z')).toBe(false);
  });

  test('adds and subtracts days across month, year and leap-day boundaries', () => {
    expect(addDays('2026-11-09', 1)).toBe('2026-11-10');
    expect(addDays('2026-11-30', 1)).toBe('2026-12-01');
    expect(addDays('2026-12-31', 1)).toBe('2027-01-01');
    expect(addDays('2027-01-01', -1)).toBe('2026-12-31');
    expect(addDays('2028-02-28', 1)).toBe('2028-02-29');
    expect(addDays('2028-02-29', 1)).toBe('2028-03-01');
    expect(addDays('2027-02-28', 1)).toBe('2027-03-01');
    expect(addDays('2026-11-09', 0)).toBe('2026-11-09');
    expect(addDays('2026-11-09', -400)).toBe('2025-10-05');
  });

  test('a phone in another time zone cannot shift the day (pure calendar arithmetic)', () => {
    const original = process.env.TZ;
    for (const tz of ['Pacific/Kiritimati', 'Pacific/Pago_Pago', 'Asia/Colombo', 'America/Los_Angeles']) {
      process.env.TZ = tz;
      expect(addDays('2026-03-08', 1)).toBe('2026-03-09'); // includes a US DST change day
      expect(addDays('2026-11-01', 1)).toBe('2026-11-02');
      expect(formatLongDate('2026-11-09')).toBe('Mon 9 Nov 2026');
    }
    process.env.TZ = original;
  });

  test('rejects impossible dates instead of rolling over', () => {
    expect(() => addDays('2026-02-30', 1)).toThrow();
    expect(() => formatLongDate('nonsense')).toThrow();
  });

  test('formats dates for display', () => {
    expect(formatLongDate('2026-11-09')).toBe('Mon 9 Nov 2026');
    expect(formatLongDate('2027-07-31')).toBe('Sat 31 Jul 2027');
    expect(formatShortDate('2026-11-09')).toBe('9 Nov');
  });

  test('compares dates as strings', () => {
    expect(isAfter('2026-11-10', '2026-11-09')).toBe(true);
    expect(isAfter('2026-11-09', '2026-11-09')).toBe(false);
    expect(isAfter('2027-01-01', '2026-12-31')).toBe(true);
  });
});

describe('addMonths', () => {
  const { addMonths } = require('@/lib/dates');
  test('moves whole months and keeps to the last day where the month is shorter', () => {
    expect(addMonths('2026-11-10', 1)).toBe('2026-12-10');
    expect(addMonths('2026-12-15', 1)).toBe('2027-01-15');
    expect(addMonths('2027-01-15', -1)).toBe('2026-12-15');
    expect(addMonths('2027-01-31', 1)).toBe('2027-02-28');
    expect(addMonths('2028-01-31', 1)).toBe('2028-02-29');
    expect(addMonths('2026-03-31', -1)).toBe('2026-02-28');
    expect(addMonths('2026-11-10', 0)).toBe('2026-11-10');
  });
});
