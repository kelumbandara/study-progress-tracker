import { useCallback, useEffect, useRef, useState } from 'react';
import { AppState } from 'react-native';

import type { AppUse } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { useBootstrap } from '@/features/dailyLog/hooks';
import { KEYS, storage } from '@/storage/storage';

import { getDay, isAccessGranted, phoneUsageSupported } from '../../../modules/phone-usage';
import { syncPhoneUse } from './sync';

/** Don't send more often than this when the app is merely coming back to the foreground. */
const MIN_GAP_MS = 5 * 60_000;

export type SyncState = 'idle' | 'sending' | 'ok' | 'error';

// The "share my phone use" choice is kept in one place so the settings screen and the background sync always agree.
let current: boolean | null = null;
const listeners = new Set<(on: boolean) => void>();

export async function setSharing(on: boolean): Promise<void> {
  current = on;
  if (on) await storage.set(KEYS.phoneShare, '1');
  else await storage.remove(KEYS.phoneShare);
  listeners.forEach((l) => l(on));
}

/** Forgets the remembered choice so it is read from storage again (used by tests and after signing out). */
export function forgetSharingCache(): void {
  current = null;
}

export function useSharingFlag() {
  const [sharing, setSharingState] = useState(current ?? false);
  const [loaded, setLoaded] = useState(current !== null);
  useEffect(() => {
    listeners.add(setSharingState);
    if (current === null) {
      void storage.get(KEYS.phoneShare).then((v) => {
        current = v === '1';
        setSharingState(current);
        setLoaded(true);
      });
    }
    return () => void listeners.delete(setSharingState);
  }, []);
  return { sharing, loaded };
}

/** One sync run: reads the phone, sends to the server, remembers the newest day sent. Safe to call at any time. */
export function usePhoneUseSender() {
  const { api } = useAuth();
  const boot = useBootstrap();
  const busy = useRef(false);
  const lastRun = useRef(0);
  const [state, setState] = useState<SyncState>('idle');
  const [error, setError] = useState<unknown>(null);
  const [lastSentAt, setLastSentAt] = useState<Date | null>(null);

  const run = useCallback(
    async (force: boolean): Promise<boolean> => {
      if (!phoneUsageSupported || !boot.data || busy.current) return false;
      if (!isAccessGranted()) return false;
      if (!force && Date.now() - lastRun.current < MIN_GAP_MS) return false;
      busy.current = true;
      lastRun.current = Date.now();
      setState('sending');
      setError(null);
      try {
        const last = await storage.get(KEYS.phoneLast);
        const result = await syncPhoneUse({
          getDay,
          send: (date: string, apps: AppUse[]) => api.sendPhoneUsage(date, apps),
          today: boot.data.today,
          zoneId: boot.data.timezone,
          lastSent: last,
        });
        if (result.lastSent) await storage.set(KEYS.phoneLast, result.lastSent);
        if (result.error) {
          setState('error');
          setError(result.error);
          return false;
        }
        setState('ok');
        setLastSentAt(new Date());
        return true;
      } finally {
        busy.current = false;
      }
    },
    [api, boot.data],
  );

  return { run, state, error, lastSentAt };
}

/** Runs for the whole signed-in app: sends when the app opens or returns to the foreground, if sharing is on. */
export function useAutoPhoneUseSync() {
  const { sharing, loaded } = useSharingFlag();
  const { run } = usePhoneUseSender();
  useEffect(() => {
    if (!loaded || !sharing || !phoneUsageSupported) return;
    void run(false);
    const sub = AppState.addEventListener('change', (s) => {
      if (s === 'active') void run(false);
    });
    return () => sub.remove();
  }, [loaded, sharing, run]);
}
