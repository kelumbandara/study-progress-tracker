import type { ExpoConfig } from 'expo/config';

/**
 * ezexam, the A/L study tracker (Android). Server address, "may use http" and cleartext are decided at BUILD time:
 *   EXPO_PUBLIC_API_URL      default server address shown on the sign-in screen (e.g. https://tracker.example.com)
 *   EXPO_PUBLIC_ALLOW_HTTP   "1" lets the app use plain http (development only; release builds must use https)
 *   ALLOW_CLEARTEXT          "1" lets the Android build open plain-http connections (development only)
 */
const config: ExpoConfig = {
  name: 'ezexam',
  slug: 'study-progress-tracker', // must match the Expo project name; the app is still called "ezexam" on the phone
  owner: 'sky-smart-technology',
  extra: { eas: { projectId: '2366538d-e0dd-4113-b7ed-5ef0a6393851' } },
  scheme: 'ezexam',
  version: '1.0.0',
  orientation: 'default',
  icon: './assets/icon.png',
  userInterfaceStyle: 'automatic',
  android: {
    package: 'net.edusl.ezexam',
    versionCode: 1,
    adaptiveIcon: {
      backgroundColor: '#E6F4FE',
      foregroundImage: './assets/android-icon-foreground.png',
      backgroundImage: './assets/android-icon-background.png',
      monochromeImage: './assets/android-icon-monochrome.png',
    },
    predictiveBackGestureEnabled: false,
    // The app stores nothing worth backing up: the token lives in the Keystore and all data is on the server.
    allowBackup: false,
  },
  web: { favicon: './assets/favicon.png', bundler: 'metro', output: 'single' },
  plugins: [
    'expo-router',
    'expo-secure-store',
    './plugins/withReleaseSigning',
    ['expo-build-properties', { android: { usesCleartextTraffic: process.env.ALLOW_CLEARTEXT === '1' } }],
  ],
  experiments: { typedRoutes: true },
};

export default config;