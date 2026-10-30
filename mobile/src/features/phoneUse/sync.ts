import type { AppUse } from '@/api/types';
import { addDays, isAfter } from '@/lib/dates';

/** How far back the phone can still tell us about (Android keeps a few days of usage events). */
export const MAX_DAYS_BACK = 6;

/**
 * Which days to send. The first time: the last week. After that: from the last day already sent (it may have been
 * partial when it was sent) up to today. Never more than a week back, never a future day.
 */
export function datesToSync(today: string, lastSent: string | null): string[] {
  const earliest = addDays(today, -MAX_DAYS_BACK);
  let from = lastSent === null ? earliest : lastSent;
  if (isAfter(earliest, from)) from = earliest;
  if (isAfter(from, today)) from = today;
  const out: string[] = [];
  for (let d = from; !isAfter(d, today); d = addDays(d, 1)) out.push(d);
  return out;
}

export interface SyncDeps {
  /** Reads one day from the phone. */
  getDay(date: string, zoneId: string): Promise<AppUse[]>;
  /** Sends one day to the server. */
  send(date: string, apps: AppUse[]): Promise<unknown>;
  today: string;
  zoneId: string;
  lastSent: string | null;
}

export interface SyncResult {
  /** Days that reached the server, oldest first. */
  sent: string[];
  /** The newest day that reached the server (to remember for next time), or the previous value if nothing was sent. */
  lastSent: string | null;
  error: unknown;
}

/** Sends each day in turn. Stops at the first failure so that the next attempt continues from there. */
export async function syncPhoneUse(deps: SyncDeps): Promise<SyncResult> {
  const sent: string[] = [];
  let lastSent = deps.lastSent;
  for (const date of datesToSync(deps.today, deps.lastSent)) {
    try {
      const apps = await deps.getDay(date, deps.zoneId);
      await deps.send(date, apps);
      sent.push(date);
      lastSent = date;
    } catch (error) {
      return { sent, lastSent, error };
    }
  }
  return { sent, lastSent, error: null };
}
