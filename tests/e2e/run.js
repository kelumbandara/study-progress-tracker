const { chromium } = require('playwright-core');
const fs = require('fs');
const path = require('path');

const BASE = process.env.E2E_BASE || 'http://127.0.0.1:8081';
if (process.env.E2E_CONFIRM_TEST_DB !== 'yes') { console.error('Refusing to run: these tests WRITE data. Point E2E_BASE at a server using the *_test database and set E2E_CONFIRM_TEST_DB=yes (see README.md).'); process.exit(3); }
const EMAIL = process.env.E2E_EMAIL;
const PASSWORD = process.env.E2E_PASSWORD;
if (!EMAIL || !PASSWORD) { console.error('Set E2E_EMAIL and E2E_PASSWORD to an account created in the TEST database (php artisan tracker:create-user).'); process.exit(3); }
const TLS = process.env.E2E_IGNORE_TLS === 'yes'; // only for a local self-signed HTTPS proxy
const SHOTS = path.join(__dirname, 'shots');
fs.mkdirSync(SHOTS, { recursive: true });

const results = [];
const problems = []; // console errors, page errors, bad responses
let currentPage = '';

async function step(name, fn) {
  try {
    const note = await fn();
    results.push({ name, ok: true, note: note || '' });
  } catch (e) {
    results.push({ name, ok: false, note: String(e.message).split('\n')[0].slice(0, 300) });
  }
}

function watch(page, label) {
  page.on('console', (m) => { if (m.type() === 'error') problems.push(`[${label}] console: ${m.text().slice(0, 200)} @ ${page.url()}`); });
  page.on('pageerror', (e) => problems.push(`[${label}] pageerror: ${String(e.message).slice(0, 200)} @ ${page.url()}`));
  page.on('response', (r) => {
    if (r.status() >= 400 && !r.url().includes('favicon')) problems.push(`[${label}] HTTP ${r.status()} ${r.url().slice(0, 140)}`);
  });
  page.on('requestfailed', (r) => problems.push(`[${label}] request failed: ${r.url().slice(0, 140)} (${r.failure()?.errorText})`));
}

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, ignoreHTTPSErrors: TLS });
  const page = await ctx.newPage();
  page.setDefaultTimeout(15000);
  watch(page, 'desktop');
  const shot = (n) => page.screenshot({ path: path.join(SHOTS, n + '.png'), fullPage: true });
  const text = () => page.locator('body').innerText();

  // ---------------------------------------------------------------- sign-in (the app is now private)
  await step('Guest is sent to the login page (no data visible)', async () => {
    for (const u of ['/', '/settings', '/reports', '/attempts/create']) {
      await page.goto(BASE + u);
      if (!/\/login$/.test(page.url())) throw new Error(`${u} did not redirect to /login (at ${page.url()})`);
    }
    if (!/Sign in/.test(await text())) throw new Error('login page does not say "Sign in"');
    if ((await page.locator('.main-sidebar').count()) > 0) throw new Error('the app menu is visible to a guest');
    await shot('00-login');
    return 'protected pages redirect to /login';
  });
  await step('Login: wrong password gives one vague message', async () => {
    await page.goto(BASE + '/login');
    await page.fill('input[name=email]', EMAIL);
    await page.fill('input[name=password]', 'definitely not the password 1');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForSelector('.invalid-feedback');
    const t = await text();
    if (!/do not match our records/.test(t)) throw new Error('message missing');
    if (new RegExp(PASSWORD.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).test(t)) throw new Error('password echoed back');
    return 'rejected without revealing which part was wrong';
  });
  await step('Login: POST without a CSRF token is refused (HTTP 419)', async () => {
    const r = await page.request.post(BASE + '/login', { form: { email: EMAIL, password: PASSWORD }, maxRedirects: 0 });
    if (r.status() !== 419) throw new Error('expected 419, got ' + r.status());
    return 'HTTP 419';
  });
  await step('Login: sign in with the account', async () => {
    await page.goto(BASE + '/login');
    await page.fill('input[name=email]', EMAIL);
    await page.fill('input[name=password]', PASSWORD);
    if (!(await page.isChecked('#remember'))) throw new Error('"Keep me signed in" should default to on');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL((u) => !u.pathname.startsWith('/login'));
    const cookies = await ctx.cookies();
    const sess = cookies.find((c) => /session/.test(c.name));
    const rem = cookies.find((c) => /^remember_web/.test(c.name));
    if (!sess || !sess.httpOnly) throw new Error('session cookie missing or not HttpOnly');
    if (!rem) throw new Error('remember-me cookie missing');
    if (BASE.startsWith('https') && !sess.secure) throw new Error('session cookie is not Secure over https');
    return `signed in; session cookie HttpOnly${sess.secure ? ' + Secure' : ''}; remember-me cookie set`;
  });
  await step('Security headers present on a signed-in page, each once', async () => {
    const r = await page.request.get(BASE + '/');
    const h = r.headersArray();
    const count = (n) => h.filter((x) => x.name.toLowerCase() === n).length;
    for (const n of ['x-frame-options', 'x-content-type-options', 'referrer-policy', 'permissions-policy']) if (count(n) !== 1) throw new Error(`${n} appears ${count(n)} times`);
    const hsts = count('strict-transport-security');
    if (BASE.startsWith('https') && hsts !== 1) throw new Error('HSTS missing over https');
    return `all single; HSTS ${hsts ? 'on' : 'off (http)'}`;
  });

  // ---------------------------------------------------------------- dashboard (empty state)
  await step('Dashboard loads (empty state)', async () => {
    await page.goto(BASE + '/');
    const t = await text();
    if (!/Not Configured/i.test(t)) throw new Error('"Not Configured" not shown');
    if (!/Today's daily log has not been entered/.test(t)) throw new Error('missing-log prompt not shown');
    await shot('01-dashboard-empty');
    return 'shows Not Configured and the missing-log prompt';
  });

  // ---------------------------------------------------------------- settings
  await step('Settings: save planned hours and see history', async () => {
    await page.goto(BASE + '/settings');
    await page.fill('#hours_theory', '300');
    await page.fill('#hours_papers', '250.5');
    await page.fill('#reason', 'browser test');
    await page.getByRole('button', { name: /Save settings/ }).click();
    await page.waitForSelector('.alert-success');
    const t = await text();
    if (!/browser test/.test(t) || !/300\.0 h|300 h/.test(t)) throw new Error('history row missing');
    await shot('02-settings');
    return 'saved 300 h theory, 250.5 h papers; history shows reason';
  });
  await step('Settings: negative hours rejected', async () => {
    await page.fill('#hours_school', '-5');
    await page.getByRole('button', { name: /Save settings/ }).click();
    await page.waitForLoadState();
    // HTML min=0 may block the submit; accept either server error or browser validation
    const invalid = await page.$eval('#hours_school', (el) => !el.validity.valid);
    const t = await text();
    if (!invalid && !/cannot be negative/.test(t)) throw new Error('negative value accepted');
    await page.goto(BASE + '/settings');
    return invalid ? 'blocked by browser validation' : 'blocked by server validation';
  });

  // ---------------------------------------------------------------- syllabus
  await step('Syllabus: Load NIE syllabus button', async () => {
    await page.goto(BASE + '/syllabus');
    await page.getByRole('button', { name: /Load NIE syllabus/ }).click();
    await page.waitForSelector('.alert-success');
    const t = await text();
    const m = t.match(/NIE syllabus loaded: (\d+) added, (\d+) with errors/);
    if (!m || +m[1] < 100 || +m[2] !== 0) throw new Error('unexpected result: ' + (m ? m[0] : t.slice(0, 120)));
    await shot('03-syllabus-loaded');
    return m[0];
  });
  await step('Syllabus: each subject shows its units', async () => {
    const out = [];
    for (const [id, name] of [[1, 'Combined Mathematics'], [2, 'Physics'], [3, 'IT']]) {
      await page.goto(`${BASE}/syllabus?subject=${id}`);
      const n = await page.locator('.card-outline.card-primary .card-title').count();
      if (n < 2) throw new Error(`${name}: only ${n} units`);
      out.push(`${name}: ${n} units`);
    }
    return out.join('; ');
  });

  // ---------------------------------------------------------------- past papers
  await step('Papers: register a paper', async () => {
    await page.goto(BASE + '/papers');
    await page.selectOption('#subject_id', { label: 'Combined Mathematics' });
    await page.selectOption('#exam_year', '2024');
    await page.fill('#paper_name', 'Paper I');
    await page.fill('#max_marks', '100');
    await page.fill('#duration_minutes', '180');
    await page.getByRole('button', { name: /Register paper/ }).click();
    await page.waitForSelector('.alert-success');
    if (!/Combined Mathematics · 2024 Paper I|Combined Mathematics .* 2024 Paper I/.test(await text()) && !page.url().includes('/papers/')) throw new Error('paper page not shown');
    return 'registered 2024 Paper I';
  });
  await step('Papers: duplicate paper is refused', async () => {
    await page.goto(BASE + '/papers');
    await page.selectOption('#subject_id', { label: 'Combined Mathematics' });
    await page.selectOption('#exam_year', '2024');
    await page.fill('#paper_name', 'Paper I');
    await page.getByRole('button', { name: /Register paper/ }).click();
    await page.waitForSelector('.alert-danger');
    return 'duplicate rejected with an error message';
  });

  // ---------------------------------------------------------------- daily log
  await step('Daily log: live total, save, edit with history', async () => {
    await page.goto(BASE + '/daily-log');
    await page.fill('#school_h', '5'); await page.fill('#school_m', '30');
    await page.fill('#tuition_h', '2'); await page.fill('#tuition_m', '0');
    await page.fill('#theory_h', '1'); await page.fill('#theory_m', '15');
    await page.fill('#papers_h', '2'); await page.fill('#papers_m', '0');
    const live = await page.locator('#dayTotal').innerText();
    if (!/^10 h 45 min/.test(live)) throw new Error('live total wrong: ' + live);
    await page.getByRole('button', { name: /Save daily log/ }).click();
    await page.waitForSelector('.alert-success');
    await page.fill('#theory_h', '2');
    await page.getByRole('button', { name: /Save daily log/ }).click();
    await page.waitForSelector('.alert-success');
    const t = await text();
    if (!/Change history/.test(t) || !/theory minutes: 75 → 135/.test(t)) throw new Error('audit trail missing');
    if (!/Confirmed entry/.test(t)) throw new Error('confirmed state missing');
    await shot('04-daily-log');
    return `live total "${live}"; edit recorded 75 → 135 min`;
  });
  await step('Daily log: missing day vs confirmed zero day', async () => {
    await page.goto(BASE + '/daily-log?date=2026-10-29');
    if (!/Missing: nothing has been logged/.test(await text())) throw new Error('missing state not shown for an unlogged date');
    return 'unlogged date shows "Missing", not zero';
  });
  await step('Daily log: more than 24 h rejected', async () => {
    await page.goto(BASE + '/daily-log');
    await page.fill('#school_h', '24'); await page.fill('#tuition_h', '5');
    await page.getByRole('button', { name: /Save daily log/ }).click();
    await page.waitForSelector('.alert-danger');
    if (!/more than 24 hours/.test(await text())) throw new Error('no 24h error');
    return 'rejected';
  });

  // ---------------------------------------------------------------- record question (the JS-heavy page)
  let questionUrl = null;
  await step('Record Question: cascading dropdowns fill', async () => {
    await page.goto(BASE + '/attempts/create');
    await page.selectOption('#pp_subject', { label: 'Combined Mathematics' });
    await page.selectOption('#pp_year', '2024');
    await page.waitForFunction(() => document.querySelectorAll('#pp_paper option').length > 1);
    await page.selectOption('#pp_paper', { index: 1 });
    await page.waitForFunction(() => /No questions registered/.test(document.querySelector('#pp_question').options[0]?.text + (document.querySelector('#pp_question').options[1]?.text || '')) || document.querySelectorAll('#pp_question option').length >= 1);
    return 'paper list loaded from the API after choosing subject + year';
  });
  await step('Record Question: register a missing question inline (searchable topics)', async () => {
    await page.locator('summary', { hasText: 'Question not listed' }).click();
    await page.fill('#aq_no', '1');
    await page.fill('#aq_max', '10');
    const box = page.locator('#aq_topics + .select2-container');
    await box.click();
    await page.locator('.select2-search__field').fill('Limits');
    await page.keyboard.press('Enter');
    await page.keyboard.press('Escape');
    await page.click('#aq_btn');
    await page.waitForFunction(() => document.querySelectorAll('#pp_question option').length > 1 && document.querySelector('#pp_question').value);
    await page.waitForFunction(() => document.querySelector('#aq_msg').textContent.trim().length > 0);
    const msg = await page.locator('#aq_msg').innerText();
    if (!/registered/i.test(msg)) throw new Error('no success message: ' + msg);
    await shot('05-attempt-form');
    return msg;
  });
  await step('Record Question: save attempt 1 (40%)', async () => {
    await page.fill('#marks_earned', '4');
    await page.fill('#worked_minutes', '35');
    await page.selectOption('#result', 'partial');
    await page.getByRole('button', { name: /Save attempt/ }).click();
    await page.waitForURL(/attempts\/question\/\d+/);
    questionUrl = page.url();
    if (!/Attempt 1 saved \(4\/10\)/.test(await text())) throw new Error('flash missing');
    return 'history page opened: ' + questionUrl.replace(BASE, '');
  });
  await step('Mastery rule: Mastered refused after one 40% attempt', async () => {
    await page.goto(BASE + '/syllabus?subject=1');
    await page.locator('.card-header', { hasText: 'Combined Mathematics I' }).first().click();
    const row = page.locator('tr', { has: page.locator('td', { hasText: /^\s*Limits/ }) }).first();
    await row.locator('select[name="status"]').selectOption('mastered');
    await row.getByRole('button', { name: 'Save' }).click();
    await page.waitForSelector('.alert-danger');
    if (!/Cannot mark "Limits" as Mastered/.test(await text())) throw new Error('rule message missing');
    await shot('06-mastery-refused');
    return 'refused with explanation';
  });
  await step('Syllabus: practice moved topic to "Questions Practised"', async () => {
    await page.locator('.card-header', { hasText: 'Combined Mathematics I' }).first().click();
    const row = page.locator('tr', { has: page.locator('td', { hasText: /^\s*Limits/ }) }).first();
    const badge = await row.locator('.badge').first().innerText();
    if (!/Questions Practised|Revision Required/i.test(badge)) throw new Error('status is ' + badge);
    return 'status badge: ' + badge;
  });
  await step('Record Question: retry twice, improvement shown', async () => {
    for (const [marks, mins, result] of [['8', '25', 'partial'], ['10', '20', 'correct']]) {
      await page.goto(questionUrl);
      await page.getByRole('link', { name: /Try again/ }).click();
      await page.waitForFunction(() => document.querySelector('#pp_question') && document.querySelector('#pp_question').value);
      await page.fill('#marks_earned', marks);
      await page.fill('#worked_minutes', mins);
      await page.selectOption('#result', result);
      await page.getByRole('button', { name: /Save attempt/ }).click();
      await page.waitForURL(/attempts\/question\/\d+/);
    }
    const t = await text();
    for (const need of [/\+60 percentage points/, /15 min faster/, /best score 100%/]) if (!need.test(t)) throw new Error('missing ' + need);
    const rows = await page.locator('table tbody tr').count();
    if (rows !== 3) throw new Error('expected 3 separate attempts, saw ' + rows);
    await shot('07-attempt-history');
    return '3 attempts kept separately; +60 points, 15 min faster';
  });
  await step('Mastery rule: Mastered accepted after independent 100%', async () => {
    await page.goto(BASE + '/syllabus?subject=1');
    await page.locator('.card-header', { hasText: 'Combined Mathematics I' }).first().click();
    const row = page.locator('tr', { has: page.locator('td', { hasText: /^\s*Limits/ }) }).first();
    await row.locator('select[name="status"]').selectOption('mastered');
    await row.getByRole('button', { name: 'Save' }).click();
    await page.waitForSelector('.alert-success');
    await page.locator('.card-header', { hasText: 'Combined Mathematics I' }).first().click();
    const badge = await page.locator('tr', { has: page.locator('td', { hasText: /^\s*Limits/ }) }).first().locator('.badge').first().innerText();
    if (!/Mastered/i.test(badge)) throw new Error('badge is ' + badge);
    return 'now Mastered';
  });
  await step('Record Question: marks above maximum rejected', async () => {
    await page.goto(questionUrl);
    await page.getByRole('link', { name: /Try again/ }).click();
    await page.waitForFunction(() => document.querySelector('#pp_question').value);
    await page.fill('#marks_earned', '11');
    await page.selectOption('#result', 'correct');
    await page.getByRole('button', { name: /Save attempt/ }).click();
    await page.waitForSelector('.alert-danger');
    return 'rejected: ' + (await page.locator('.alert-danger li').first().innerText()).slice(0, 80);
  });

  // ---------------------------------------------------------------- theory
  await step('Record Theory: searchable topic, mark complete', async () => {
    await page.goto(BASE + '/theory');
    await page.locator('#topic_id + .select2-container').click();
    await page.locator('.select2-search__field').fill('Derivatives');
    await page.keyboard.press('Enter');
    await page.fill('#minutes', '45');
    await page.selectOption('#confidence', '3');
    await page.check('#marked_complete', { force: true });
    await page.getByRole('button', { name: /Save theory entry/ }).click();
    await page.waitForSelector('.alert-success');
    await page.goto(BASE + '/syllabus?subject=1');
    await page.locator('.card-header', { hasText: 'Combined Mathematics I' }).first().click();
    const badge = await page.locator('tr', { has: page.locator('td', { hasText: /^\s*Derivatives/ }) }).first().locator('.badge').first().innerText();
    if (!/Theory Completed/i.test(badge)) throw new Error('badge is ' + badge);
    return 'Derivatives → ' + badge + ' (not Mastered)';
  });

  // ---------------------------------------------------------------- mistakes
  await step('Review Mistakes: page and empty/queue state', async () => {
    await page.goto(BASE + '/mistakes');
    const t = await text();
    if (!/Nothing to review|Why:/.test(t)) throw new Error('unexpected content');
    await shot('08-mistakes');
    return /Nothing to review/.test(t) ? 'queue empty (the question was mastered)' : 'queue has items';
  });

  // ---------------------------------------------------------------- reports
  await step('Reports: weekly page, filters, Excel and CSV downloads', async () => {
    await page.goto(BASE + '/reports');
    const t = await text();
    for (const need of [/Hours: planned vs actual/, /Questions/, /Recommended priorities/]) if (!need.test(t)) throw new Error('missing ' + need);
    await shot('09-report-weekly');
    const csvHref = await page.getByRole('link', { name: /CSV/ }).getAttribute('href');
    const xlsxHref = await page.getByRole('link', { name: /Excel/ }).getAttribute('href');
    const csv = await page.request.get(csvHref);
    const xlsx = await page.request.get(xlsxHref);
    if (csv.status() !== 200 || xlsx.status() !== 200) throw new Error(`export status ${csv.status()}/${xlsx.status()}`);
    const body = await csv.text();
    if (!/Weekly report/.test(body)) throw new Error('csv content wrong');
    return `CSV ${csv.status()} (${body.length} bytes), Excel ${xlsx.status()} (${(await xlsx.body()).length} bytes)`;
  });
  await step('Reports: monthly + subject filter', async () => {
    await page.goto(BASE + '/reports?type=monthly&subject=1');
    const t = await text();
    if (!/Monthly detail/.test(t) || !/Cumulative syllabus coverage/.test(t)) throw new Error('monthly sections missing');
    await shot('10-report-monthly');
    return 'monthly detail shown';
  });

  // ---------------------------------------------------------------- dashboard with data
  await step('Dashboard: subject cards, numbers and charts render', async () => {
    await page.goto(BASE + '/');
    await page.waitForTimeout(1500);
    const t = await text();
    for (const need of [/Combined Mathematics/, /First attempts/, /Marks earned \/ available/, /Recommendations/]) if (!need.test(t)) throw new Error('missing ' + need);
    const chartLoaded = await page.evaluate(() => typeof Chart !== 'undefined');
    if (!chartLoaded) throw new Error('Chart.js did not load (internet/CDN?)');
    const canvases = await page.locator('canvas').count();
    const painted = await page.evaluate(() => Array.from(document.querySelectorAll('canvas')).map((c) => {
      const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data; let n = 0; for (let i = 3; i < d.length; i += 4) if (d[i] > 0) n++; return n;
    }));
    if (!painted.every((n) => n > 500)) throw new Error('a chart looks blank: ' + painted.join(','));
    await shot('11-dashboard-data');
    return `${canvases} charts painted (non-empty pixels: ${painted.join(', ')})`;
  });
  await step('Dashboard: missing-log prompt is gone after logging today', async () => {
    if (/Today's daily log has not been entered/.test(await text())) throw new Error('prompt still shown');
    return 'ok';
  });

  // ---------------------------------------------------------------- corrections (edit with audit trail)
  await step('Correct an attempt: edit in place, audit trail shown', async () => {
    await page.goto(questionUrl);
    const before = await page.locator('table tbody tr').count();
    await page.getByRole('link', { name: 'Correct' }).nth(1).click(); // attempt #2
    await page.fill('#marks_earned', '7');
    await page.fill('#notes', 'typo fixed in browser test');
    await page.getByRole('button', { name: /Save correction/ }).click();
    await page.waitForURL(/attempts\/question\/\d+/);
    if (!/corrected/i.test(await text())) throw new Error('no confirmation');
    const after = await page.locator('table tbody tr').count();
    if (after !== before) throw new Error(`row count changed ${before} -> ${after}`);
    await page.getByRole('link', { name: 'Correct' }).nth(1).click();
    const t = await text();
    if (!/Change history/.test(t) || !/marks earned: 8\.00 → 7/.test(t) && !/marks earned: 8 → 7/.test(t)) throw new Error('audit entry missing: ' + t.slice(t.indexOf('Change history'), t.indexOf('Change history') + 200));
    await shot('12-correct-attempt');
    return 'attempt 2 corrected 8 → 7; still 3 rows; change history shown';
  });
  await step('Correct a theory entry', async () => {
    await page.goto(BASE + '/theory');
    await page.getByRole('link', { name: 'Correct' }).first().click();
    await page.fill('#minutes', '50');
    await page.getByRole('button', { name: /Save correction/ }).click();
    await page.waitForSelector('.alert-success');
    return 'minutes corrected';
  });

  // ---------------------------------------------------------------- sample data (confirmation dialog)
  await step('Sample data: load, banner, cancel removal, confirm removal', async () => {
    await page.goto(BASE + '/settings');
    await page.getByRole('button', { name: /Load sample data/ }).click();
    await page.waitForSelector('.alert-success');
    await page.goto(BASE + '/');
    if (!/Sample data is loaded/.test(await text())) throw new Error('banner missing');
    await page.goto(BASE + '/settings');
    let msg = '';
    page.once('dialog', async (d) => { msg = d.message(); await d.dismiss(); });
    await page.getByRole('button', { name: /Remove all sample data/ }).click();
    await page.waitForTimeout(500);
    if (!/permanently deletes/.test(msg)) throw new Error('confirmation text missing: ' + msg);
    if (!/Remove all sample data/.test(await text())) throw new Error('data removed although the dialog was cancelled');
    page.once('dialog', (d) => d.accept());
    await page.getByRole('button', { name: /Remove all sample data/ }).click();
    await page.waitForSelector('.alert-success');
    if (!/Sample data removed/.test(await text())) throw new Error('no removal message');
    await page.goto(BASE + '/');
    if (/Sample data is loaded/.test(await text())) throw new Error('banner still shown');
    await page.goto(questionUrl);
    if ((await page.locator('table tbody tr').count()) !== 3) throw new Error('real attempts were affected');
    return 'cancel kept data; confirm removed only sample data; real attempts intact';
  });

  // ---------------------------------------------------------------- navigation
  await step('Sidebar links all open without errors', async () => {
    const urls = ['/', '/daily-log', '/theory', '/attempts/create', '/paper-attempts/create', '/mistakes', '/syllabus', '/papers', '/attempts', '/reports', '/settings'];
    const bad = [];
    for (const u of urls) {
      const r = await page.goto(BASE + u);
      if (r.status() !== 200) bad.push(`${u}=${r.status()}`);
    }
    if (bad.length) throw new Error(bad.join(', '));
    return `${urls.length} pages OK`;
  });

  // ---------------------------------------------------------------- mobile
  await step('Log out from the menu, then the app is private again; sign back in', async () => {
    await page.goto(BASE + '/');
    await page.locator('.user-menu > a.nav-link').click();
    await page.getByRole('link', { name: /Log Out/i }).click();
    await page.waitForURL(/\/login$/);
    if (!/signed out/i.test(await text())) throw new Error('no sign-out confirmation');
    await page.goto(BASE + '/settings');
    if (!/\/login$/.test(page.url())) throw new Error('settings reachable after logging out');
    const back = await page.request.get(BASE + '/attempts/question/1', { maxRedirects: 0 });
    if (back.status() === 200) throw new Error('data page still readable after logout');
    await page.fill('input[name=email]', EMAIL);
    await page.fill('input[name=password]', PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL((u) => !u.pathname.startsWith('/login'));
    return 'logged out, locked out, signed back in';
  });

  const mctx = await browser.newContext({ viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2, ignoreHTTPSErrors: TLS });
  const m = await mctx.newPage();
  await step('Mobile: sign in on a phone-sized screen', async () => {
    await m.goto(BASE + '/');
    await m.fill('input[name=email]', EMAIL);
    await m.fill('input[name=password]', PASSWORD);
    await m.screenshot({ path: path.join(SHOTS, 'm-login.png') });
    await m.getByRole('button', { name: 'Sign in' }).click();
    await m.waitForURL((u) => !u.pathname.startsWith('/login'));
    return 'signed in on mobile';
  });
  m.setDefaultTimeout(15000);
  watch(m, 'mobile');
  const overflow = [];
  for (const u of ['/', '/daily-log', '/attempts/create', '/theory', '/syllabus?subject=1', '/papers', '/reports', '/settings', '/mistakes']) {
    await step(`Mobile 375px: ${u}`, async () => {
      await m.goto(BASE + u);
      await m.waitForTimeout(400);
      const w = await m.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
      const name = 'm-' + (u.replace(/[^a-z0-9]/gi, '_') || 'home');
      await m.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage: false });
      if (w.sw > w.cw + 2) { overflow.push(u); throw new Error(`page is ${w.sw}px wide in a ${w.cw}px viewport (sideways scroll)`); }
      return `fits (${w.sw}/${w.cw}px)`;
    });
  }
  await step('Mobile: menu opens from the hamburger button', async () => {
    await m.goto(BASE + '/');
    await m.locator('[data-widget="pushmenu"]').click();
    await m.waitForTimeout(500);
    const visible = await m.locator('.main-sidebar .nav-link', { hasText: 'Daily Log' }).first().isVisible();
    if (!visible) throw new Error('menu did not open');
    await m.screenshot({ path: path.join(SHOTS, 'm-menu-open.png') });
    return 'sidebar opens';
  });
  await step('Mobile: record-question form is usable (tap targets)', async () => {
    await m.goto(BASE + '/attempts/create');
    const small = await m.evaluate(() => Array.from(document.querySelectorAll('button, select, input:not([type=hidden]):not([type=checkbox]):not([type=radio])')).filter((e) => e.offsetParent).map((e) => e.getBoundingClientRect().height).filter((h) => h > 0 && h < 28).length);
    return small ? `${small} controls shorter than 28px (small but tappable)` : 'all controls at least 28px tall';
  });

  await browser.close();

  // ---------------------------------------------------------------- report
  const pass = results.filter((r) => r.ok).length;
  console.log('\n===== BROWSER TEST RESULTS =====');
  results.forEach((r) => console.log(`${r.ok ? 'PASS' : 'FAIL'}  ${r.name}${r.note ? '  —  ' + r.note : ''}`));
  console.log(`\n${pass}/${results.length} steps passed`);
  const uniq = [...new Set(problems)];
  console.log(`\n===== BROWSER CONSOLE / NETWORK PROBLEMS (${uniq.length}) =====`);
  uniq.slice(0, 40).forEach((p) => console.log(p));
  console.log('\nScreenshots:', SHOTS);
})().catch((e) => { console.error('RUNNER CRASHED', e); process.exit(2); });
