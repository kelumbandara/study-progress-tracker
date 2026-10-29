import type { DailyLog } from '@/api/types';
import { emptyForm, formFromLog, formsEqual, totalMinutes, validate } from '@/features/dailyLog/form';

const missing: DailyLog = { date: '2026-11-09', logged: false, minutes: null, total_minutes: null, is_rest_day: false, rating: null, notes: null, is_sample: false };
const logged: DailyLog = {
  date: '2026-11-09', logged: true, minutes: { school: 330, tuition: 120, theory: 75, papers: 120 }, total_minutes: 645,
  is_rest_day: true, rating: 4, notes: 'good day', is_sample: false,
};

describe('daily log form', () => {
  test('a missing day starts as zeros (nothing is counted until it is saved)', () => {
    const f = formFromLog(missing);
    expect(f).toEqual(emptyForm());
    expect(totalMinutes(f)).toBe(0);
  });

  test('a saved day is shown as hours and minutes', () => {
    const f = formFromLog(logged);
    expect(f.hours).toEqual({ school: '5', tuition: '2', theory: '1', papers: '2' });
    expect(f.mins).toEqual({ school: '30', tuition: '0', theory: '15', papers: '0' });
    expect(f.isRestDay).toBe(true);
    expect(f.rating).toBe(4);
    expect(f.notes).toBe('good day');
    expect(totalMinutes(f)).toBe(645);
  });

  test('form → API input is lossless', () => {
    const v = validate(formFromLog(logged));
    expect(v.ok).toBe(true);
    if (v.ok) {
      expect(v.input.minutes).toEqual(logged.minutes);
      expect(v.total).toBe(645);
      expect(v.input).toMatchObject({ is_rest_day: true, rating: 4, notes: 'good day' });
    }
  });

  test('blank boxes count as zero, blank notes become null, notes are trimmed', () => {
    const f = emptyForm();
    f.hours.school = '';
    f.mins.school = '';
    f.notes = '   ';
    const v = validate(f);
    expect(v.ok && v.input.minutes.school).toBe(0);
    expect(v.ok && v.input.notes).toBeNull();
    f.notes = '  hi  ';
    const v2 = validate(f);
    expect(v2.ok && v2.input.notes).toBe('hi');
  });

  test('exactly 24 hours is allowed, one minute more is not', () => {
    const f = emptyForm();
    f.hours.school = '24';
    expect(validate(f).ok).toBe(true);
    f.mins.school = '1';
    const v = validate(f);
    expect(v.ok).toBe(false);
    if (!v.ok) expect(v.errors.total).toMatch(/more than 24 hours/);
  });

  test('the total across categories is limited to 24 hours', () => {
    const f = emptyForm();
    f.hours.school = '10';
    f.hours.tuition = '10';
    f.hours.theory = '5';
    const v = validate(f);
    expect(v.ok).toBe(false);
    if (!v.ok) expect(v.errors.total).toBeDefined();
    expect(totalMinutes(f)).toBe(25 * 60);
  });

  test('each box is checked and the message names the category', () => {
    const f = emptyForm();
    f.hours.theory = '25';
    f.mins.papers = '75';
    f.hours.school = '1.5';
    const v = validate(f);
    expect(v.ok).toBe(false);
    if (!v.ok) {
      expect(v.errors.fields.theory).toMatch(/Study Theory.*24/);
      expect(v.errors.fields.papers).toMatch(/Write Exam Papers.*0 to 59/);
      expect(v.errors.fields.school).toMatch(/School.*whole numbers/);
      expect(v.errors.fields.tuition).toBeUndefined();
    }
    expect(totalMinutes(f)).toBeNull(); // an invalid box means there is no total to show
  });

  test('spaces around the notes are not an "unsaved change" (the server trims them)', () => {
    const saved = formFromLog(logged);
    const typed = formFromLog(logged);
    typed.notes = '   good day  ';
    expect(formsEqual(saved, typed)).toBe(true);
    typed.notes = 'good day!';
    expect(formsEqual(saved, typed)).toBe(false);
    const blank = formFromLog(missing);
    blank.notes = '    ';
    expect(formsEqual(formFromLog(missing), blank)).toBe(true);
  });

  test('an empty box is the same amount as a 0, so emptying a 0 is not an unsaved change', () => {
    const saved = formFromLog(missing);
    const emptied = formFromLog(missing);
    emptied.hours.school = '';
    emptied.mins.tuition = '';
    expect(formsEqual(saved, emptied)).toBe(true);
    expect(totalMinutes(emptied)).toBe(0);
    emptied.hours.school = '1';
    expect(formsEqual(saved, emptied)).toBe(false);
  });

  test('"unsaved changes" is a comparison with what the server has', () => {
    const base = formFromLog(logged);
    expect(formsEqual(base, formFromLog(logged))).toBe(true);
    const edited = formFromLog(logged);
    edited.hours.theory = '2';
    expect(formsEqual(base, edited)).toBe(false);
    edited.hours.theory = '1';
    expect(formsEqual(base, edited)).toBe(true); // changing it back is no change
  });
});
