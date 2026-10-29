import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

/**
 * Where the sign-in token lives. On the phone it is encrypted by the Android Keystore (expo-secure-store).
 * The web build (used only for development and browser testing) has no Keystore, so it falls back to the
 * browser's localStorage; the Android app never takes that path.
 */
const onWeb = Platform.OS === 'web';

export const KEYS = {
  token: 'altracker.token',
  user: 'altracker.user',
  server: 'altracker.server',
} as const;

export const storage = {
  async get(key: string): Promise<string | null> {
    try {
      return onWeb ? (globalThis.localStorage?.getItem(key) ?? null) : await SecureStore.getItemAsync(key);
    } catch {
      return null; // unreadable storage is treated as "nothing stored": the person simply signs in again
    }
  },
  async set(key: string, value: string): Promise<void> {
    if (onWeb) globalThis.localStorage?.setItem(key, value);
    else await SecureStore.setItemAsync(key, value);
  },
  async remove(key: string): Promise<void> {
    try {
      if (onWeb) globalThis.localStorage?.removeItem(key);
      else await SecureStore.deleteItemAsync(key);
    } catch {
      /* nothing to remove */
    }
  },
};
