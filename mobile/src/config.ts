/** Build-time settings (see app.config.ts). Expo inlines EXPO_PUBLIC_* values into the app when it is built. */

export const DEFAULT_SERVER_URL: string = process.env.EXPO_PUBLIC_API_URL ?? '';

/** Plain http is for development only: allowed in dev builds, or when explicitly switched on for a test build. */
export const ALLOW_HTTP: boolean = __DEV__ || process.env.EXPO_PUBLIC_ALLOW_HTTP === '1';

export const API_PREFIX = '/api/v1';
export const REQUEST_TIMEOUT_MS = 20000;
