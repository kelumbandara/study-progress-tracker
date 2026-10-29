import type { Category, DailyLog, DailyLogInput } from '@/api/types';
import { joinMinutes, MAX_DAY_MINUTES, parseWholeNumber, splitMinutes } from '@/lib/time';

export const CATEGORIES: { key: Category; label: string; hint: string }[] = [
  { key: 'school', label: 'School', hint: 'Time at school' },
  { key: 'tuition', label: 'Tuition', hint: 'Time at tuition class' },
  { key: 'theory', label: 'Study Theory', hint: 'Your own study of lessons and notes' },
  { key: 'papers', label: 'Write Exam Papers', hint: 'Writing past-paper questions and papers' },
];

/** What is typed in the boxes. Everything is a string so a half-typed value never breaks the screen. */
export interface DayForm {
  hours: Record<Category, string>;
  mins: Record<Category, string>;
  isRestDay: boolean;
  rating: number | null;
  notes: string;
}

export interface FormErrors {
  fields: Partial<Record<Category, string>>;
  total?: string;
}

const zeros = (): Record<Category, string> => ({ school: '0', tuition: '0', theory: '0', papers: '0' });

export function emptyForm(): DayForm {
  return { hours: zeros(), mins: zeros(), isRestDay: false, rating: null, notes: '' };
}

/** A missing day starts as zeros in the boxes, but nothing counts until the person saves it. */
export function formFromLog(log: DailyLog): DayForm {
  const form = emptyForm();
  if (log.minutes) {
    for (const { key } of CATEGORIES) {
      const { hours, minutes } = splitMinutes(log.minutes[key]);
      form.hours[key] = String(hours);
      form.mins[key] = String(minutes);
    }
  }
  form.isRestDay = log.is_rest_day;
  form.rating = log.rating;
  form.notes = log.notes ?? '';
  return form;
}

/**
 * Compare forms the way the server would store them: notes are trimmed, and an empty box is the same amount as a 0
 * (tapping into a 0 empties it, and that must not count as an unsaved change).
 */
function canonical(f: DayForm): DayForm {
  const norm = (r: Record<Category, string>) => Object.fromEntries(Object.entries(r).map(([k, v]) => [k, String(parseWholeNumber(v) ?? v)])) as Record<Category, string>;
  return { ...f, hours: norm(f.hours), mins: norm(f.mins), notes: f.notes.trim() };
}

export function formsEqual(a: DayForm, b: DayForm): boolean {
  return JSON.stringify(canonical(a)) === JSON.stringify(canonical(b));
}

/** Minutes typed so far, or null while any box holds something that is not a valid number. */
export function totalMinutes(form: DayForm): number | null {
  let total = 0;
  for (const { key } of CATEGORIES) {
    const h = parseWholeNumber(form.hours[key]);
    const m = parseWholeNumber(form.mins[key]);
    if (h === null || m === null) return null;
    total += joinMinutes(h, m);
  }
  return total;
}

export type Validation = { ok: true; input: DailyLogInput; total: number } | { ok: false; errors: FormErrors };

/** The same limits the server enforces, checked first so mistakes are caught before a network trip. */
export function validate(form: DayForm): Validation {
  const errors: FormErrors = { fields: {} };
  const minutes = { school: 0, tuition: 0, theory: 0, papers: 0 } as Record<Category, number>;

  for (const { key, label } of CATEGORIES) {
    const h = parseWholeNumber(form.hours[key]);
    const m = parseWholeNumber(form.mins[key]);
    if (h === null || m === null) {
      errors.fields[key] = `${label}: use whole numbers only.`;
    } else if (h > 24) {
      errors.fields[key] = `${label}: hours can be at most 24.`;
    } else if (m > 59) {
      errors.fields[key] = `${label}: minutes must be 0 to 59.`;
    } else {
      minutes[key] = joinMinutes(h, m);
    }
  }

  if (Object.keys(errors.fields).length === 0) {
    const total = Object.values(minutes).reduce((a, b) => a + b, 0);
    if (total > MAX_DAY_MINUTES) {
      errors.total = 'The four durations add up to more than 24 hours. Please check them.';
    } else {
      return {
        ok: true,
        total,
        input: { minutes, is_rest_day: form.isRestDay, rating: form.rating, notes: form.notes.trim() === '' ? null : form.notes.trim() },
      };
    }
  }
  return { ok: false, errors };
}
