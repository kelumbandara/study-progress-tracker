// End-to-end test of the phone app's PHASE D screens (Syllabus, Reports, History and corrections) in the app's WEB build,
// in a real browser against a live Laravel server. Every result is checked independently through the API afterwards.
// WRITES DATA: use only a server on a throw-away (*_test) database, freshly seeded with the syllabus. See README.md.
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

const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.ico': 'image/x-icon', '.ttf': 'font/ttf', '.map': 'application/json' };
const server = http.createServer((req, res) => {
  const urlPath = decodeURIComponent((req.url || '/').split('?')[0]);
  let file = path.join(DIST, urlPath);
  if (!file.startsWith(DIST) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) file = path.join(DIST, 'index.html');
  res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream' });
  fs.createReadStream(file).pipe(res);
});

let shotFail = async () => {};
const results = [];
const problems = [];
async function step(name, fn) {
  try { results.push({ name, ok: true, note: (await fn()) || '' }); } catch (e) { results.push({ name, ok: false, note: String(e.message).split('\n')[0].slice(0, 300) }); try { await shotFail(name); } catch {} }
}
const must = (c, m) => { if (!c) throw new Error(m); };

async function api(method, p, token, body) {
  const r = await fetch(API + '/api/v1' + p, { method, headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}), ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body: body ? JSON.stringify(body) : undefined });
  const text = await r.text();
  return { status: r.status, json: text ? JSON.parse(text) : null };
}

(async () => {
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  const APP = `http://127.0.0.1:${server.address().port}`;
  const browser = await chromium.launch({ channel: 'msedge', headless: true, args: ['--disable-web-security'] });
  const ctx = await browser.newContext({ viewport: { width: 390, height: 900 } });
  const page = await ctx.newPage();
  page.setDefaultTimeout(15000);
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (401|422)/.test(m.text())) problems.push(`console: ${m.text().slice(0, 200)}`); });
  page.on('pageerror', (e) => problems.push(`pageerror: ${String(e.message).slice(0, 200)}`));
  const tid = (id) => page.getByTestId(id);
  const shot = (n) => page.screenshot({ path: path.join(SHOTS, n + '.png'), fullPage: true });
  shotFail = (name) => page.screenshot({ path: path.join(SHOTS, 'FAIL-' + name.replace(/[^a-z0-9]+/gi, '-').slice(0, 40) + '.png'), fullPage: true });
  const text = (id) => tid(id).innerText();
  // Reload the app (the sign-in is kept) and open one of the More screens, so each step starts from a known place.
  const openMore = async (link) => { await page.goto(APP); await tid('tab-more').click(); await tid(link).click(); };

  const login = await api('POST', '/auth/login', null, { email: EMAIL, password: PASSWORD, device_name: 'mobile-web-more.js check' });
  must(login.status === 200, 'cannot sign in to the API with the given account');
  const T = login.json.token;
  const boot = (await api('GET', '/bootstrap', T)).json;
  const today = boot.today;
  const physics = boot.subjects.find((s) => /physics/i.test(s.name));
  must(physics, 'no Physics subject on the server');
  const year = boot.paper_years.to - 1;
  const topics = (await api('GET', `/topics?subject_id=${physics.id}`, T)).json.data;
  must(topics.length > 3, 'the test database has no syllabus topics (seed it first)');
  const topicA = topics[0], topicB = topics[1];
  must((await api('GET', '/attempts', T)).json.meta.total === 0, 'the test database already has attempts; use a freshly seeded one');

  // Data to look at and correct, created through the API (the phone screens for creating it were tested in Phase C).
  const logPut = await api('PUT', `/daily-logs/${today}`, T, { minutes: { school: 300, tuition: 0, theory: 120, papers: 120 }, is_rest_day: false, rating: 3, notes: null });
  must(logPut.status === 200, 'setup: day not saved ' + JSON.stringify(logPut.json));
  const paper = (await api('POST', '/papers', T, { subject_id: physics.id, exam_year: year, paper_name: 'Paper I' })).json.paper;
  const q1 = (await api('POST', `/papers/${paper.id}/questions`, T, { question_no: '1', max_marks: 10, topic_ids: [topicA.id] })).json.question;
  const q2 = (await api('POST', `/papers/${paper.id}/questions`, T, { question_no: '2', max_marks: 10, topic_ids: [topicB.id] })).json.question;
  const a1 = (await api('POST', '/attempts', T, { question_id: q1.id, attempt_date: today, marks_earned: 9, result: 'correct', independent: true, worked_minutes: 30 })).json.attempt;
  const a2 = (await api('POST', '/attempts', T, { question_id: q2.id, attempt_date: today, marks_earned: 4, result: 'wrong', independent: false })).json.attempt;
  must(a1 && a2, 'setup: attempts not saved');
  const th = (await api('POST', '/theory', T, { study_date: today, topic_id: topicB.id, minutes: 40, marked_complete: false, confidence: 2 })).json.entry;
  must(th, 'setup: theory entry not saved');
  const topicStatus = async (id) => (await api('GET', `/topics?subject_id=${physics.id}`, T)).json.data.find((t) => t.id === id).status;
  const logBefore = (await api('GET', `/daily-logs/${today}`, T)).json.log;

  await page.goto(APP);
  await tid('server').fill(API);
  await tid('email').fill(EMAIL);
  await tid('password').fill(PASSWORD);
  await tid('sign-in').click();
  await tid('save-day').waitFor();

  await step('More tab offers Syllabus, Reports and History, and still has the account and sign-out', async () => {
    await tid('tab-more').click();
    await tid('open-syllabus').waitFor();
    must(await tid('open-reports').isVisible() && await tid('open-history').isVisible(), 'entries missing');
    must((await text('account-email')).includes(EMAIL), 'account e-mail missing');
    must(await tid('sign-out').count() === 1, 'sign out missing');
    await shot('d01-more');
    return 'three links + account kept';
  });

  const sylApi = (await api('GET', `/syllabus?subject_id=${physics.id}`, T)).json;
  const unitOf = (topicId) => sylApi.units.find((u) => u.topics.some((t) => t.id === topicId || (t.subtopics || []).some((c) => c.id === topicId)));

  await step('Syllabus: the numbers on screen are the server\'s numbers', async () => {
    await tid('open-syllabus').click();
    await tid(`syl-subject-${physics.id}`).click();
    await tid('syl-stats').waitFor();
    const s = sylApi.stats;
    const want = `${s.total} topics. Theory done: ${s.theory_completed} (${Math.round(s.theory_pct)}%).`;
    const got = await text('syl-stats');
    must(got.includes(want), `screen "${got}" vs server "${want}"`);
    must(await page.locator('[data-testid^="unit-"]').count() === sylApi.units.length, 'unit count differs from the server');
    await shot('d02-syllabus');
    return `${sylApi.units.length} units, ${s.total} topics`;
  });

  await step('Syllabus: Mastered without evidence is refused and nothing changes', async () => {
    const before = await topicStatus(topicB.id);
    await tid(`unit-${unitOf(topicB.id).id}`).click();
    await tid(`topic-${topicB.id}`).click();
    await tid('topic-status-mastered').click();
    await tid('topic-save').click();
    await tid('topic-error').waitFor();
    must(/80/.test(await text('topic-error')), 'the refusal does not mention the 80% rule: ' + await text('topic-error'));
    must(await topicStatus(topicB.id) === before, 'the status changed anyway');
    await shot('d03-mastered-refused');
    return `server kept "${before}"; message shown`;
  });

  await step('Syllabus: a normal status change with confidence reaches the server', async () => {
    await tid('topic-status-in_progress').click();
    await page.getByLabel('Topic confidence: 3').first().click();
    await tid('topic-save').click();
    await tid('topic-saved').waitFor();
    const t = (await api('GET', `/syllabus?subject_id=${physics.id}`, T)).json.units.flatMap((u) => u.topics.flatMap((x) => [x, ...(x.subtopics || [])])).find((x) => x.id === topicB.id);
    must(t.status === 'in_progress' && t.confidence === 3, 'server has ' + JSON.stringify({ status: t.status, confidence: t.confidence }));
    return 'In Progress, confidence 3';
  });

  await step('Syllabus: Mastered with an independent 90% attempt is accepted', async () => {
    const unitId = unitOf(topicA.id).id;
    if (unitId !== unitOf(topicB.id).id) await tid(`unit-${unitId}`).click();
    await tid(`topic-${topicA.id}`).click();
    await tid('topic-status-mastered').click();
    await tid('topic-save').click();
    await tid('topic-saved').waitFor();
    must(await topicStatus(topicA.id) === 'mastered', 'server status is ' + await topicStatus(topicA.id));
    await shot('d04-mastered');
    return 'server shows Mastered';
  });

  await step('Reports: weekly numbers match the server; missing days are not zero', async () => {
    await openMore('open-reports');
    await tid('report-body').waitFor();
    const r = (await api('GET', `/reports?type=weekly&anchor=${today}`, T)).json;
    const shownAttempts = await text('rep-attempts');
    must(shownAttempts.startsWith(`${r.questions.attempts} on ${r.questions.distinct}`), `screen "${shownAttempts}" vs server ${r.questions.attempts}/${r.questions.distinct}`);
    must((await text('rep-days')) === `${r.consistency.days_logged} of ${r.consistency.days_in_range}`, 'days logged differ');
    must(/not counted as zero/.test(await page.getByText(/Days not logged/).locator('xpath=..').innerText()), 'missing days wording');
    must(await text('rep-outstanding') === String(r.outstanding), 'outstanding differs');
    const hrs = await text('hours-theory');
    must(hrs.startsWith('2 h 00 min'), 'theory hours "' + hrs + '"');
    await shot('d05-report-weekly');
    return `${r.questions.attempts} attempts, ${r.consistency.days_logged} of ${r.consistency.days_in_range} days`;
  });

  await step('Reports: Earlier shows the previous week, Later returns; monthly adds its sections', async () => {
    await tid('rep-prev').click();
    await page.waitForFunction(() => /No question attempts/.test(document.body.innerText));
    const range = await text('rep-range');
    await tid('rep-next').click();
    await tid('rep-attempts').waitFor();
    await tid('rep-type-monthly').click();
    await tid('report-monthly').waitFor();
    const m = (await api('GET', `/reports?type=monthly&anchor=${today}`, T)).json;
    must(m.monthly, 'server sent no monthly block');
    must(await page.getByText('Syllabus coverage').count() > 0, 'coverage card missing');
    await shot('d06-report-monthly');
    return `previous week "${range}"; monthly sections shown`;
  });

  await step('History: attempts are listed, and a correction is a correction (same attempt, no new one)', async () => {
    await openMore('open-history');
    await tid(`attempt-row-${a1.id}`).waitFor();
    must(await tid(`attempt-row-${a2.id}`).isVisible(), 'second attempt not listed');
    await shot('d07-history');
    await tid(`attempt-edit-${a1.id}`).click();
    must(await tid('attempt-marks').inputValue() === '9', 'marks not pre-filled');
    await tid('attempt-independent-no').click();
    await tid('attempt-marks').fill('8.5');
    await tid('correct-save').click();
    await tid('attempt-corrected').waitFor();
    const list = (await api('GET', '/attempts', T)).json;
    must(list.meta.total === 2, `attempts on the server: ${list.meta.total} (a correction must not add one)`);
    const x = list.data.find((a) => a.id === a1.id);
    must(x.marks_earned === 8.5 && x.independent === false && x.attempt_no === a1.attempt_no && x.question_id === q1.id, 'attempt on the server: ' + JSON.stringify(x));
    return 'attempt 1 now 8.5/10, with help; still 2 attempts; attempt number and question unchanged';
  });

  await step('Correcting the evidence takes Mastered away again (server rule), and the Syllabus screen shows it', async () => {
    const s = await topicStatus(topicA.id);
    must(s !== 'mastered', `topic still "${s}" after its only independent attempt became "with help"`);
    await openMore('open-syllabus');
    await tid(`syl-subject-${physics.id}`).click();
    await tid(`unit-${unitOf(topicA.id).id}`).click();
    const label = await tid(`topic-${topicA.id}`).getAttribute('aria-label');
    must(!/Mastered/.test(label), 'screen still says ' + label);
    return `server status "${s}"; screen: ${label}`;
  });

  await step('History: an invalid correction is refused before sending', async () => {
    await openMore('open-history');
    await tid(`attempt-edit-${a2.id}`).click();
    await tid('attempt-marks').fill('12');
    await tid('correct-save').click();
    await page.getByText(/cannot be more than the question's maximum of 10/).waitFor();
    const x = (await api('GET', '/attempts', T)).json.data.find((a) => a.id === a2.id);
    must(x.marks_earned === 4, 'server marks changed to ' + x.marks_earned);
    await page.getByRole('button', { name: 'Cancel' }).click();
    return 'server still has 4';
  });

  await step('History: a theory entry can be corrected; the day\'s totals do not change', async () => {
    await openMore('open-history');
    await tid('hist-kind-theory').click();
    await tid(`theory-row-${th.id}`).waitFor();
    await tid(`theory-edit-${th.id}`).click();
    await tid('dur-theory-edit-hours').fill('0');
    await tid('dur-theory-edit-mins').fill('25');
    await tid('correct-save').click();
    await tid('theory-corrected').waitFor();
    const e = (await api('GET', '/theory', T)).json.data.find((x) => x.id === th.id);
    must(e.minutes === 25 && e.topic_id === topicB.id && e.date === today, 'theory on the server: ' + JSON.stringify(e));
    const after = (await api('GET', `/daily-logs/${today}`, T)).json.log;
    must(JSON.stringify(after.minutes) === JSON.stringify(logBefore.minutes), 'the day totals changed: ' + JSON.stringify(after.minutes));
    await shot('d08-theory-corrected');
    return '40 → 25 min; day total unchanged';
  });

  for (const [w, h] of [[360, 740], [820, 1180]]) {
    await step(`Layout at ${w}x${h}: no sideways scrolling on the new screens`, async () => {
      await page.setViewportSize({ width: w, height: h });
      const out = [];
      for (const route of ['open-syllabus', 'open-reports', 'open-history']) {
        await page.goto(APP);
        await tid('tab-more').click();
        await tid(route).click();
        await page.waitForTimeout(800);
        const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
        if (m.sw > m.cw + 1) out.push(`${route} overflows (${m.sw} > ${m.cw})`);
        await page.screenshot({ path: path.join(SHOTS, `d-layout-${route}-${w}x${h}.png`), fullPage: true });
      }
      must(out.length === 0, out.join('; '));
      return 'ok';
    });
  }

  await browser.close();
  server.close();

  const pass = results.filter((r) => r.ok).length;
  console.log('\n===== PHONE APP, PHASE D SCREENS (web build) END-TO-END =====');
  results.forEach((r) => console.log(`${r.ok ? 'PASS' : 'FAIL'}  ${r.name}${r.note ? '  —  ' + r.note : ''}`));
  console.log(`\n${pass}/${results.length} steps passed`);
  const uniq = [...new Set(problems)];
  console.log(`\n===== BROWSER CONSOLE PROBLEMS (${uniq.length}) =====`);
  uniq.slice(0, 30).forEach((p) => console.log(p));
  console.log('\nScreenshots:', SHOTS);
  process.exit(pass === results.length && uniq.length === 0 ? 0 : 1);
})().catch((e) => { console.error('CRASH', e); try { server.close(); } catch {} process.exit(2); });
