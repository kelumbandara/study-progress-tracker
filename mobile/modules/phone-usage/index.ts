import { requireOptionalNativeModule } from 'expo';

/** One app's use on one day. Only the name, the id and the minutes: never any content. */
export interface AppUse {
  package: string;
  label: string;
  minutes: number;
}

interface Native {
  isAccessGranted(): boolean;
  openAccessSettings(): void;
  getDay(date: string, zoneId: string): Promise<AppUse[]>;
}

// null in Expo Go, on the web and on iOS: only the installed Android app has this module.
const native = requireOptionalNativeModule<Native>('PhoneUsage');

export const phoneUsageSupported = native !== null;

export function isAccessGranted(): boolean {
  return native ? native.isAccessGranted() : false;
}

export function openAccessSettings(): void {
  native?.openAccessSettings();
}

/** Minutes per app for one calendar day ("YYYY-MM-DD") in the given time zone. Empty when access is not granted. */
export async function getDay(date: string, zoneId: string): Promise<AppUse[]> {
  return native ? native.getDay(date, zoneId) : [];
}
