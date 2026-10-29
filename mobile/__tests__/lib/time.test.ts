import { digitsOnly, formatDuration, formatHours, joinMinutes, MAX_DAY_MINUTES, parseWholeNumber, splitMinutes } from '@/lib/time';

describe('time', () => {
  test('splits and joins minutes without drift', () => {
    expect(splitMinutes(330)).toEqual({ hours: 5, minutes: 30 });
    expect(splitMinutes(0)).toEqual({ hours: 0, minutes: 0 });
    expect(splitMinutes(1440)).toEqual({ hours: 24, minutes: 0 });
    expect(splitMinutes(59.9)).toEqual({ hours: 0, minutes: 59 });
    expect(splitMinutes(-5)).toEqual({ hours: 0, minutes: 0 });
    for (const t of [0, 1, 59, 60, 61, 645, 1439, 1440]) {
      const { hours, minutes } = splitMinutes(t);
      expect(joinMinutes(hours, minutes)).toBe(t);
    }
    expect(MAX_DAY_MINUTES).toBe(24 * 60);
  });

  test('formats durations for people', () => {
    expect(formatDuration(330)).toBe('5 h 30 min');
    expect(formatDuration(65)).toBe('1 h 05 min');
    expect(formatDuration(0)).toBe('0 h 00 min');
    expect(formatDuration(-90)).toBe('-1 h 30 min');
    expect(formatDuration(null)).toBe('—');
    expect(formatDuration(undefined)).toBe('—');
    expect(formatDuration(Number.NaN)).toBe('—');
  });

  test('formats hours with one decimal', () => {
    expect(formatHours(90)).toBe('1.5 h');
    expect(formatHours(0)).toBe('0.0 h');
    expect(formatHours(7530)).toBe('125.5 h');
    expect(formatHours(null)).toBe('—');
  });

  test('a blank box means 0, digits are accepted, everything else is invalid', () => {
    expect(parseWholeNumber('')).toBe(0);
    expect(parseWholeNumber('   ')).toBe(0);
    expect(parseWholeNumber('7')).toBe(7);
    expect(parseWholeNumber(' 45 ')).toBe(45);
    expect(parseWholeNumber('007')).toBe(7);
    for (const bad of ['1.5', '-3', '+3', '1e2', 'abc', '12345', '1,5', '٣']) expect(parseWholeNumber(bad)).toBeNull();
  });

  test('typing is filtered to digits and limited in length', () => {
    expect(digitsOnly('a1b2c3')).toBe('12');
    expect(digitsOnly('-5')).toBe('5');
    expect(digitsOnly('1.5')).toBe('15');
    expect(digitsOnly('123', 3)).toBe('123');
    expect(digitsOnly('')).toBe('');
  });
});
