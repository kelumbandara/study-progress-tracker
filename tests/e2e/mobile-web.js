// End-to-end test of the phone app's screens: the app's WEB build running in a real browser against a live Laravel server.
// It exercises the same screens, API client and sign-in logic as the Android app (React Native Web renders the same components).
// WRITES DATA: use only a server on a throw-away (*_test) database. See README.md.
const { chromium } = require('playwright-core');
const http = require('http');
const fs = require('fs');
const path = require('path');

if (process.env.E2E_CONFIRM_TEST_DB !== 'yes') { console.error('Refusing to run: this test WRITES data. Set E2E_CONFIRM_TEST_DB=yes and point E2E_API at a throw-away server.'); process.exit(3); }
const API = (process.env.E2E_API || 'http://127.0.0.1:8081').replace(/\/$/, '');
const EMAIL = process.env.E2E_EMAIL, PASSWORD = process.env.E2E_PASSWORD;
if (!EMAIL || !PASSWORD) { console.error('Set E2E_EMAIL and E2E_PASSWORD (an account in the TEST database).'); process.exit(3); }
const DIST = path.resolve(process.env.MOBILE_DIST || path.join(__dirname, '..', '..', 'mobile', 'dist-web'));
if (!fs.existsSync(path.join(DIST, 'index.html'))) { console.error(`No web build at ${DIST}. Run: cd mobile; EXPO_PUBLIC_ALLOW_HTTP=1 npx expo export --platform web --output-dir dist-web`); process.exit(3); }
const SHOTS = path.join(__dirname, 'shots-mobile');
fs.mkdirSync(SHOTS, { recursive: true });

// ---- tiny static server with single-page-app fallback ------------------------------------------------------
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.ico': 'image/x-icon', '.ttf': 'font/ttf', '.map': 'application/json' };
const server = http.createServer((req, res) => {
  const urlPath = decodeURIComponent((req.url || '/').split('?')[0]);
  let file = path.join(DIST, urlPath);
  if (!file.startsWith(DIST) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) file = path.join(DIST, 'index.html');
  res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream' });
  fs.createReadStream(file).pipe(res);
});

const results = [];
const problems = [];
async function step(name, fn) {
  try { results.push({ name, ok: true, note: (await fn()) || '' }); } catch (e) { results.push({ name, ok: false, note: String(e.message).split('\n')[0].slice(0, 300) }); }
}
const must = (c, m) => { if (!c) throw new Error(m); };

async function api(method, p, token, body) {
  const r = await fetch(API + '/api/v1' + p, { method, headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}), ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body: body ? JSON.stringify(body) : undefined });
  const text = await r.text();
  return { status: r.status, json: text ? JSON.parse(text) : null };
}
const addDays = (iso, n) => { const d = new Date(iso + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
const longDate = (iso) => { const d = new Date(iso + 'T00:00:00Z'); return `${['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][d.getUTCDay()]} ${d.getUTCDate()} ${['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][d.getUTCMonth()]} ${d.getUTCFullYear()}`; };

(async () => {
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  const APP = `http://127.0.0.1:${server.address().port}`;

  // --disable-web-security only because this test page and the API are on different local ports (the Android app has no CORS).
  const browser = await chromium.launch({ channel: 'msedge', headless: true, args: ['--disable-web-security'] });
  const ctx = await browser.newContext({ viewport: { width: 390, height: 800 } });
  const page = await ctx.newPage();
  page.setDefaultTimeout(15000);
  // The browser logs the app's deliberate wrong-password (422) and revoked-token (401) requests as errors; those are expected here.
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (401|422)/.test(m.text())) problems.push(`console: ${m.text().slice(0, 200)}`); });
  page.on('pageerror', (e) => problems.push(`pageerror: ${String(e.message).slice(0, 200)}`));
  const tid = (id) => page.getByTestId(id);
  const shot = (n) => page.screenshot({ path: path.join(SHOTS, n + '.png') });
  const token = () => page.evaluate(() => localStorage.getItem('altracker.token'));
  // a control counts as disabled if it carries the disabled attribute or aria-disabled=true
  const isDisabled = (id) => tid(id).evaluate((el) => el.disabled === true || el.getAttribute('aria-disabled') === 'true');

  const boot = (await api('GET', '/bootstrap', null)).status; // unauthenticated -> 401, just a reachability check
  must(boot === 401, 'the API is not reachable at ' + API);
  const login = await api('POST', '/auth/login', null, { email: EMAIL, password: PASSWORD, device_name: 'mobile-web.js check' });
  must(login.status === 200, 'cannot sign in to the API with the given account');
  const checkToken = login.json.token;
  const today = (await api('GET', '/bootstrap', checkToken)).json.today;

  await step('Opens on the sign-in screen when signed out', async () => {
    await page.goto(APP);
    await tid('sign-in').waitFor();
    must(await page.getByText('A/L Study Tracker').first().isVisible(), 'title missing');
    await shot('01-sign-in');
    return 'sign-in screen shown';
  });
  await step('Empty form is caught on the phone', async () => {
    await tid('sign-in').click();
    await page.getByText('Enter the server address.').waitFor();
    must(await page.getByText('Enter your e-mail address.').isVisible() && await page.getByText('Enter your password.').isVisible(), 'messages missing');
    return '3 messages, no request';
  });
  await step('Wrong password: clear message, stays signed out', async () => {
    await tid('server').fill(API);
    await tid('email').fill(EMAIL);
    await tid('password').fill('definitely wrong 123');
    await tid('sign-in').click();
    await page.getByText(/do not match our records/).first().waitFor();
    must((await token()) === null, 'a token was stored for a failed sign-in');
    return 'rejected';
  });
  await step('Sign in with the right details lands on Today', async () => {
    await tid('password').fill(PASSWORD);
    await tid('sign-in').click();
    await tid('status-missing').waitFor();
    must(await page.getByText(longDate(today)).first().isVisible(), 'the server\'s date is not shown: ' + longDate(today));
    must((await token()) !== null, 'token not stored');
    await shot('02-today-missing');
    return `today is ${today} (from the server)`;
  });
  await step('A missing day is shown as missing, not zero', async () => {
    must(await page.getByText(/counts as missing, not as zero/).first().isVisible(), 'missing explanation absent');
    must((await tid('day-total').innerText()) === '0 h 00 min', 'total');
    return 'banner + 0 h 00 min in the boxes, nothing saved';
  });
  await step('Typing updates the total; >24 h is refused on the phone', async () => {
    await tid('dur-school-hours').fill('5');
    await tid('dur-school-mins').fill('30');
    await tid('dur-tuition-hours').fill('2');
    await tid('dur-theory-hours').fill('1');
    await tid('dur-theory-mins').fill('15');
    await tid('dur-papers-hours').fill('2');
    must((await tid('day-total').innerText()) === '10 h 45 min', 'total ' + await tid('day-total').innerText());
    await tid('dur-papers-hours').fill('20');
    await tid('total-error').waitFor();
    must(await isDisabled('save-day'), 'save should be disabled');
    await tid('dur-papers-hours').fill('2');
    return 'live total 10 h 45 min; over-24 h blocked';
  });
  await step('Save: the server really has the day (checked independently through the API)', async () => {
    await tid('save-day').click();
    await tid('status-saved').waitFor();
    const r = await api('GET', `/daily-logs/${today}`, checkToken);
    must(r.json.log.logged === true, 'not logged on the server');
    must(JSON.stringify(r.json.log.minutes) === JSON.stringify({ school: 330, tuition: 120, theory: 75, papers: 120 }), 'minutes: ' + JSON.stringify(r.json.log.minutes));
    must((await tid('save-day').innerText()).includes('Save changes'), 'button should now say Save changes');
    await shot('03-today-saved');
    return 'server has 330/120/75/120 minutes';
  });
  await step('Reload: the session is restored and the saved day reappears', async () => {
    await page.reload();
    await tid('status-logged').waitFor();
    must((await tid('dur-school-hours').inputValue()) === '5' && (await tid('dur-school-mins').inputValue()) === '30', 'values not restored');
    must((await tid('day-total').innerText()) === '10 h 45 min', 'total');
    return 'still signed in; values loaded from the server';
  });
  await step('Editing: Save changes only after a change; the edit reaches the server', async () => {
    must(await isDisabled('save-day'), 'should be disabled before editing');
    await tid('dur-theory-hours').fill('2');
    must(!(await isDisabled('save-day')), 'should be enabled after editing');
    await tid('save-day').click();
    await tid('status-saved').waitFor();
    const r = await api('GET', `/daily-logs/${today}`, checkToken);
    must(r.json.log.minutes.theory === 135, 'theory is ' + r.json.log.minutes.theory);
    return 'theory 75 → 135 min on the server';
  });
  await step('Day navigation: previous day, cannot go past today, Go to today', async () => {
    must(await isDisabled('day-next'), 'next should be disabled on today');
    await tid('day-prev').click();
    await page.getByText(longDate(addDays(today, -1))).first().waitFor();
    await tid('status-missing').waitFor();
    must((await tid('dur-school-hours').inputValue()) === '0', 'yesterday should not inherit today\'s values');
    must(!(await isDisabled('day-next')), 'next should be enabled');
    await tid('day-today').click();
    await page.getByText(longDate(today)).first().waitFor();
    return 'works';
  });
  await step('Dashboard shows the period, honest "not configured" and the advice', async () => {
    await tid('tab-dashboard').click();
    await page.getByText('Hours: planned and actual').waitFor();
    must(await page.getByText(/Planned hours are not set yet/).first().isVisible(), 'not-configured wording');
    must(await page.getByText(/Based only on what has been recorded/).isVisible(), 'guidance note');
    await shot('04-dashboard');
    return 'rendered with live data';
  });
  await step('More: account, server, connection test', async () => {
    await tid('tab-more').click();
    must((await tid('account-email').innerText()).toLowerCase() === EMAIL.toLowerCase(), 'email');
    must((await tid('account-server').innerText()) === API, 'server');
    await tid('test-connection').click();
    await page.getByText(/Connected to the server as/).waitFor();
    await shot('05-more');
    return 'connected';
  });
  await step('Sign out asks first, then revokes the token on the server', async () => {
    const t = await token();
    let asked = '';
    page.once('dialog', (d) => { asked = d.message(); d.dismiss(); });
    await tid('sign-out').click();
    await page.waitForTimeout(400);
    must(/Sign out\?/.test(asked), 'no confirmation asked');
    must((await token()) === t, 'cancelling must change nothing');
    page.once('dialog', (d) => d.accept());
    await tid('sign-out').click();
    await tid('sign-in').waitFor();
    must((await token()) === null, 'token still stored after sign-out');
    must((await api('GET', '/me', t)).status === 401, 'the old token still works on the server');
    return 'cancel kept the session; confirm revoked the token';
  });
  await step('When the server ends the session the app says so', async () => {
    await tid('server').fill(API);
    await tid('email').fill(EMAIL);
    await tid('password').fill(PASSWORD);
    await tid('sign-in').click();
    await tid('status-logged').or(tid('status-missing')).or(tid('status-saved')).waitFor();
    const t = await token();
    must((await api('POST', '/auth/logout', t)).status === 200, 'could not revoke');
    await tid('tab-dashboard').click();
    await tid('signin-notice').waitFor();
    must(await page.getByText(/Your session has ended/).first().isVisible(), 'notice');
    must((await token()) === null, 'token should be cleared');
    await shot('06-session-ended');
    return 'signed out with an explanation';
  });

  // ---- layout on other screen sizes -------------------------------------------------------------------------
  for (const [name, w, h] of [['small phone 360x640', 360, 640], ['large phone 412x915', 412, 915], ['tablet 1024x768', 1024, 768]]) {
    await step(`Layout: ${name}`, async () => {
      await page.setViewportSize({ width: w, height: h });
      await page.goto(APP);
      await tid('server').fill(API);
      await tid('email').fill(EMAIL);
      await tid('password').fill(PASSWORD);
      await tid('sign-in').click();
      await tid('save-day').waitFor();
      const out = [];
      for (const tab of ['', 'tab-dashboard', 'tab-practice', 'tab-more']) {
        if (tab) await tid(tab).click();
        await page.waitForTimeout(300);
        const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
        if (m.sw > m.cw + 1) out.push(`${tab || 'today'} overflows (${m.sw} > ${m.cw})`);
      }
      await tid('tab-more').click();
      await page.waitForTimeout(200);
      await tid('sign-out').scrollIntoViewIfNeeded();
      await page.screenshot({ path: path.join(SHOTS, `layout-${w}x${h}.png`) });
      must(out.length === 0, out.join('; '));
      // sign out for the next size
      page.once('dialog', (d) => d.accept());
      await tid('sign-out').click();
      await tid('sign-in').waitFor();
      return 'no sideways scrolling on any tab';
    });
  }

  await browser.close();
  server.close();

  const pass = results.filter((r) => r.ok).length;
  console.log('\n===== PHONE APP (web build) END-TO-END =====');
  results.forEach((r) => console.log(`${r.ok ? 'PASS' : 'FAIL'}  ${r.name}${r.note ? '  —  ' + r.note : ''}`));
  console.log(`\n${pass}/${results.length} steps passed`);
  const uniq = [...new Set(problems)];
  console.log(`\n===== BROWSER CONSOLE PROBLEMS (${uniq.length}) =====`);
  uniq.slice(0, 30).forEach((p) => console.log(p));
  console.log('\nScreenshots:', SHOTS);
  process.exit(pass === results.length && uniq.length === 0 ? 0 : 1);
})().catch((e) => { console.error('CRASH', e); try { server.close(); } catch {} process.exit(2); });
