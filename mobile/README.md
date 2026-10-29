# A/L Tracker – phone app (Expo / React Native)

Android app for the A/L Study Progress Tracker. It talks to the Laravel app through the token API (`/api/v1`, see `../docs/API.md`). One shared account; sign in with e-mail and password.

## Status
- Phase B: sign-in, secure token storage, API client, tabs (Today, Dashboard, Practice, More), complete Daily Log screen.
- Phase C: Practice tab with **Record a question** (Subject → Year → Paper → Question, papers and questions can be added on the spot, topics chosen from the server's syllabus), **Theory study** and **Mistakes to review**.
- Phase D (under the More tab): **Syllabus** (every topic and its status; Mastered is decided by the server), **Reports** (weekly/monthly, same numbers as the website; Excel/CSV export and printing stay on the website) and **History and corrections** (earlier question attempts and theory entries can be corrected, never deleted).
- Not built yet: pre-filling Record a question from a Mistakes row, APK.

## Run it
```
cd mobile
npm install
npx expo start          # scan the QR code with Expo Go, or press "a" for an emulator
```
The first time, enter the server address on the sign-in screen (for example `https://tracker.example.com`).
For a server on your own computer, run `php artisan serve --host=0.0.0.0 --port=8080`, create a user with `php artisan tracker:create-user`, and use `http://<your-PC-IP>:8080`. On the Android emulator the PC is `http://10.0.2.2:8081`.

**Android 17:** reaching a private address (home Wi-Fi, 10.0.2.2) needs the "local network" permission. If "Test connection" times out in Expo Go, grant it in the app's permissions, or on an emulator:
`adb shell pm grant host.exp.exponent android.permission.ACCESS_LOCAL_NETWORK`.
This only affects local development; a public HTTPS domain is not affected.

Plain `http://` is accepted only in development builds. Release builds require HTTPS.

## Environment variables
| Variable | Purpose |
|---|---|
| `EXPO_PUBLIC_API_URL` | Pre-fills the server address on the sign-in screen |
| `EXPO_PUBLIC_ALLOW_HTTP=1` | Allow `http://` outside development |
| `ALLOW_CLEARTEXT=1` | Build-time: allow cleartext traffic on Android (dev only) |

## Checks
```
npm test            # 144 unit/screen tests
npm run typecheck
npm run doctor
npm run export:android   # proves the Hermes bundle builds
```
End-to-end (use a throw-away test database, these scripts WRITE data):
- Web build in a real browser: `tests/e2e/mobile-web.js` (Today, Dashboard, More) and `tests/e2e/mobile-web-practice.js` (Practice screens; needs a freshly seeded test database with the NIE syllabus loaded). and `tests/e2e/mobile-web-more.js` (Syllabus, Reports, History). All need `E2E_CONFIRM_TEST_DB=yes`.
- Android emulator/phone: `scripts/android-smoke.ps1` (Today and tabs) and `scripts/android-practice-smoke.ps1` (Practice screens); `scripts/android-more-smoke.ps1` (Syllabus, Reports, History); all share `scripts/android-lib.ps1`. The scripts expect the emulator in portrait. They need the app open in Expo Go and `adb`. A brand-new emulator may show a keyboard "Try out your stylus" dialog first; dismiss it or typing is swallowed.

## Known notes
- Not yet tried on a physical phone, or against a real Coolify server. Steps for the phone test: `PHONE_TESTING.md`.
- `npm audit` reports a `zod` advisory inside `@expo/cli` (a development tool, not shipped in the app).
