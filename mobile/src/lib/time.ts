/** Durations are whole minutes everywhere; this is the only place they become hours-and-minutes for people. */

export const MAX_DAY_MINUTES = 1440;

export function splitMinutes(total: number): { hours: number; minutes: number } {
  const t = Math.max(0, Math.trunc(total));
  return { hours: Math.floor(t / 60), minutes: t % 60 };
}

export function joinMinutes(hours: number, minutes: number): number {
  return hours * 60 + minutes;
}

/** "5 h 30 min"; "—" when there is no value. */
export function formatDuration(total: number | null | undefined): string {
  if (total === null || total === undefined || Number.isNaN(total)) return '—';
  const sign = total < 0 ? '-' : '';
  const { hours, minutes } = splitMinutes(Math.abs(total));
  return `${sign}${hours} h ${String(minutes).padStart(2, '0')} min`;
}

/** "12.5 h" for dashboards (one decimal). */
export function formatHours(total: number | null | undefined): string {
  if (total === null || total === undefined || Number.isNaN(total)) return '—';
  return `${(total / 60).toFixed(1)} h`;
}

/**
 * Reads a number typed into an hours/minutes box. Blank means 0 (so an untouched box is not an error);
 * anything that is not whole digits is invalid (null).
 */
export function parseWholeNumber(text: string): number | null {
  const t = text.trim();
  if (t === '') return 0;
  return /^\d{1,4}$/.test(t) ? Number(t) : null;
}

/** Keeps only digits as the user types, so letters, spaces and signs never reach a box. */
export function digitsOnly(text: string, maxLength = 2): string {
  return text.replace(/\D/g, '').slice(0, maxLength);
}
