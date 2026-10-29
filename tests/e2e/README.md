# Browser tests (optional)

Real-browser checks of the main workflows, run in Microsoft Edge through Playwright. They are **not** needed to use the app.

`run.js` – 46 steps: sign-in (guest redirect, wrong password, CSRF 419, secure cookies, security headers, logout), settings, syllabus load, papers, daily log, record question (dropdowns + inline registration), mastery rule, theory, reports/exports, dashboard charts, and every main page at phone width (375 px).
`api.js` – 13 steps: a phone-style client (plain `fetch` with a bearer token) exercising the `/api/v1` endpoints, including parallel requests.
`mobile-web.js` / `mobile-web-practice.js` / `mobile-web-more.js` – the phone app's web build in a real browser against the API (17, 10 and 13 steps). The second needs a freshly seeded test database with the syllabus loaded (`php artisan tinker`: `app(App\Services\ImportService::class)->importFile('syllabus', base_path('database/data/nie_syllabus_2017.csv'))`) and a web build: `cd mobile; EXPO_PUBLIC_ALLOW_HTTP=1 npx expo export --platform web --output-dir dist-web`. Screenshots go to `shots-mobile/`.
`conc.js` – fires identical forms in parallel (double-submit / two tabs) and prints the HTTP status codes.

**These tests write data.** Never run them against your real database.

```powershell
# 1. from the project folder: a separate, empty test database + a second server
$env:DB_DATABASE = 'study-progress-tracker_test'
php artisan migrate:fresh --seed
php artisan serve --port=8081          # leave running (it keeps using the test database)

# 2. in another window
cd tests\e2e
npm install
$env:E2E_CONFIRM_TEST_DB = 'yes'
$env:E2E_EMAIL = 'you@example.com'; $env:E2E_PASSWORD = 'the account password'   # an account in the TEST database: php artisan tracker:create-user (with DB_DATABASE set to the test database)
node run.js                            # re-run migrate:fresh first for a clean result
node conc.js
```

Screenshots are written to `tests/e2e/shots/`. Requires Microsoft Edge (installed with Windows) and Node 18+.

## Testing the production setup (Docker + HTTPS)

The same suites can run against the production container behind an HTTPS proxy, which exercises secure cookies, HSTS and the forwarded headers. Use a throw-away database container; the suite writes data. Outline:

```powershell
docker build -t study-tracker:test .
# start MariaDB or MySQL, then the app container (APP_ENV=production, APP_URL=https://localhost:8443,
# SESSION_SECURE_COOKIE=true, DB_* pointing at the throw-away database), then an HTTPS proxy, e.g.
docker run -d --network <net> -p 8443:8443 caddy:2 caddy reverse-proxy --from https://localhost:8443 --to http://<app-container>:8080
docker exec <app-container> php artisan tracker:create-user you@example.com --password="a long password 123"
$env:E2E_BASE = 'https://localhost:8443'; $env:E2E_IGNORE_TLS = 'yes'    # the proxy's certificate is self-signed
node run.js; node conc.js
```