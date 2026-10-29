// End-to-end test of the phone app's PRACTICE screens (Record a question, Theory study, Mistakes) in the app's WEB build,
// in a real browser against a live Laravel server. Every result is checked independently through the API afterwards.
// WRITES DATA: use only a server on a throw-away (*_test) database, freshly seeded. See README.md.
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
  const isDisabled = (id) => tid(id).evaluate((el) => el.disabled === true || el.getAttribute('aria-disabled') === 'true');

  const login = await api('POST', '/auth/login', null, { email: EMAIL, password: PASSWORD, device_name: 'mobile-web-practice.js check' });
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
  const dayBefore = (await api('GET', `/daily-logs/${today}`, T)).json.log;

  await page.goto(APP);
  await tid('server').fill(API);
  await tid('email').fill(EMAIL);
  await tid('password').fill(PASSWORD);
  await tid('sign-in').click();
  await tid('save-day').waitFor();

  await step('Practice tab offers the three tasks', async () => {
    await tid('tab-practice').click();
    await tid('open-question').waitFor();
    must(await tid('open-theory').isVisible() && await tid('open-mistakes').isVisible(), 'entries missing');
    await shot('c01-practice-hub');
    return 'Record a question, Theory study, Mistakes to review';
  });

  await step('Choosing a question: nothing is registered yet, so a paper and a question are added from the phone', async () => {
    await tid('open-question').click();
    await tid(`subject-${physics.id}`).click();
    await tid(`year-${year}`).click();
    await tid('no-papers').waitFor();
    await tid('paper-new').click();
    await tid('paper-add').click();
    await page.getByText(/Give the paper a name/).waitFor();
    must((await api('GET', `/papers?subject_id=${physics.id}&year=${year}`, T)).json.data.length === 0, 'an invalid paper must not reach the server');
    await tid('paper-name').fill('  Paper I ');
    await tid('paper-add').click();
    await tid('no-questions').waitFor();
    const papers = (await api('GET', `/papers?subject_id=${physics.id}&year=${year}`, T)).json.data;
    must(papers.length === 1 && papers[0].name === 'Paper I', 'paper on the server: ' + JSON.stringify(papers));
    await tid('question-new').click();
    await tid('question-number').fill('1');
    await tid('question-add').click();
    await page.getByText(/Marks it is worth|Enter the marks this question is worth/).first().waitFor();
    await tid('question-max').fill('10');
    await tid(`question-topic-${topicA.id}`).click();
    await tid('question-add').click();
    await tid('attempt-save').waitFor();
    const qs = (await api('GET', `/papers/${papers[0].id}/questions`, T)).json.data;
    must(qs.length === 1 && qs[0].number === '1' && qs[0].max_marks === 10 && JSON.stringify(qs[0].topic_ids) === JSON.stringify([topicA.id]), 'question on the server: ' + JSON.stringify(qs));
    await shot('c02-attempt-form');
    return 'paper "Paper I" and question 1 (10 marks, 1 topic) registered, and selected';
  });

  await step('An incomplete attempt is not sent', async () => {
    await tid('attempt-marks').fill('6');
    await tid('attempt-save').click();
    await tid('attempt-invalid').waitFor();
    await page.getByText(/Choose how it went/).waitFor();
    await page.getByText(/Say whether you did it without help/).waitFor();
    must((await api('GET', '/attempts', T)).json.meta.total === 0, 'an attempt was saved');
    await tid('attempt-marks').fill('11');
    await tid('attempt-result-partial').click();
    await tid('attempt-independent-yes').click();
    await tid('attempt-save').click();
    await page.getByText(/cannot be more than the question's maximum of 10/).waitFor();
    must((await api('GET', '/attempts', T)).json.meta.total === 0, 'an attempt was saved');
    return 'missing choices and over-maximum marks are explained; the server has 0 attempts';
  });

  await step('Save a weak attempt: the server has exactly what was entered', async () => {
    await tid('attempt-marks').fill('6');
    await tid('dur-worked-hours').fill('0');
    await tid('dur-worked-mins').fill('45');
    await tid('attempt-mistake-calculation').click();
    await tid('attempt-save').click();
    await tid('attempt-saved').waitFor();
    must((await tid('attempt-save').count()) === 0, 'the form should be gone after saving');
    const a = (await api('GET', '/attempts', T)).json;
    must(a.meta.total === 1, 'attempts on the server: ' + a.meta.total);
    const x = a.data[0];
    must(x.marks_earned === 6 && x.max_marks === 10 && x.result === 'partial' && x.independent === true && x.worked_minutes === 45 && x.mistake_category === 'calculation' && x.attempt_no === 1 && x.date === today, 'attempt: ' + JSON.stringify(x));
    await shot('c03-attempt-saved');
    return 'attempt 1: 6/10, partial, independent, 45 min, "calculation", dated by the server';
  });

  await step('A second attempt is ADDED (never overwritten) and the improvement is shown', async () => {
    await tid('attempt-again').click();
    must((await tid('attempt-marks').inputValue()) === '', 'the form should start empty');
    await tid('attempt-marks').fill('9');
    await tid('attempt-result-correct').click();
    await tid('attempt-independent-yes').click();
    await tid('attempt-save').click();
    await tid('attempt-saved').waitFor();
    const text = await tid('attempt-improvement').innerText();
    must(/First attempt 60%, this one 90% \(\+30 points\)\. Best so far 90%/.test(text), 'improvement: ' + text);
    const a = (await api('GET', '/attempts', T)).json.data.sort((p, q) => p.attempt_no - q.attempt_no);
    must(a.length === 2 && a[0].marks_earned === 6 && a[1].marks_earned === 9 && a[1].attempt_no === 2, 'attempts: ' + JSON.stringify(a.map((z) => [z.attempt_no, z.marks_earned])));
    return 'attempts 1 and 2 both on the server (6 and 9); improvement +30 points';
  });

  await step('Recording an attempt did not change the day\'s time totals (no double counting)', async () => {
    const after = (await api('GET', `/daily-logs/${today}`, T)).json.log;
    must(JSON.stringify(after) === JSON.stringify(dayBefore), 'the daily log changed: ' + JSON.stringify(after));
    return 'the day is still ' + (after.logged ? 'as logged' : 'missing');
  });

  await step('Theory study: topic search, one topic, time saved; the day total is untouched', async () => {
    await page.goBack();
    await tid('open-theory').click();
    await tid(`theory-subject-${physics.id}`).click();
    await tid('theory-save').click();
    await page.getByText(/Choose the topic you studied/).waitFor();
    await page.getByText(/at least 1 minute/).waitFor();
    must((await api('GET', '/theory', T)).json.meta.total === 0, 'an invalid entry reached the server');
    const word = topicB.name.split(/\s+/).find((w) => w.length > 3) || topicB.name;
    await tid('theory-topic-search').fill(word);
    await tid(`theory-topic-${topicB.id}`).click();
    await tid('dur-theory-entry-hours').fill('0');
    await tid('dur-theory-entry-mins').fill('40');
    await page.getByRole('switch', { name: 'Theory for this topic is complete' }).click({ force: true });
    await page.getByLabel('Confidence: 3').click(); // by label: the Today tab stays mounted behind, with its own rating chips
    await tid('theory-save').click();
    await tid('theory-saved').waitFor();
    const t = (await api('GET', '/theory', T)).json.data;
    must(t.length === 1 && t[0].topic_id === topicB.id && t[0].minutes === 40 && t[0].marked_complete === true && t[0].confidence === 3 && t[0].date === today, 'theory on the server: ' + JSON.stringify(t));
    const topicNow = (await api('GET', `/topics?subject_id=${physics.id}`, T)).json.data.find((z) => z.id === topicB.id);
    must(topicNow.status !== 'mastered', 'marking theory complete must never mark a topic mastered');
    const day = (await api('GET', `/daily-logs/${today}`, T)).json.log;
    must(JSON.stringify(day) === JSON.stringify(dayBefore), 'the daily log changed');
    await tid(`theory-entry-${t[0].id}`).waitFor();
    await shot('c04-theory');
    return `40 min on "${topicB.name}", complete, confidence 3; topic is "${topicNow.status}", not mastered; day totals untouched`;
  });

  await step('Mistakes: a weak independent attempt appears; "I can now do it on my own" is refused without proof', async () => {
    // Set up one more question that clearly needs review (through the API, as a second device would).
    const paper = (await api('GET', `/papers?subject_id=${physics.id}&year=${year}`, T)).json.data[0];
    const q2 = (await api('POST', `/papers/${paper.id}/questions`, T, { question_no: '2', max_marks: 8, topic_ids: [topicB.id] })).json.question;
    const att = await api('POST', '/attempts', T, { question_id: q2.id, attempt_date: today, marks_earned: 2, result: 'wrong', independent: true, mistake_category: 'conceptual' });
    must(att.status === 201, 'setup attempt failed: ' + att.status);
    await page.goBack();
    await tid('open-mistakes').click();
    await tid(`mistake-${q2.id}`).waitFor();
    await tid(`review-open-${q2.id}`).click();
    await page.getByRole('switch', { name: 'I can now do it on my own' }).click({ force: true });
    await tid('review-save').click();
    const err = await tid('review-error').innerText();
    must(/Not saved/.test(err), 'no refusal shown: ' + err);
    await shot('c05-mistake-refused');
    await page.getByRole('switch', { name: 'I can now do it on my own' }).click({ force: true }); // untick
    await page.getByRole('switch', { name: 'I reviewed the theory' }).click({ force: true });
    await tid('review-notes').fill('went through the notes');
    await tid('review-save').click();
    await tid('review-saved').waitFor();
    const row = (await api('GET', '/mistakes', T)).json.data.find((r) => r.question.id === q2.id);
    must(row && row.last_review === today, 'the review is not on the server: ' + JSON.stringify(row && row.last_review));
    return 'refusal shown in the server\'s words; plain review saved (last review ' + today + '); the question is still listed until it is truly mastered';
  });

  for (const [name, w, h] of [['small phone 360x640', 360, 640], ['tablet 1024x768', 1024, 768]]) {
    await step(`Layout: ${name}`, async () => {
      await page.setViewportSize({ width: w, height: h });
      const out = [];
      for (const route of ['open-question', 'open-theory', 'open-mistakes']) {
        await tid('tab-practice').click();
        await page.waitForTimeout(200);
        const hub = await tid(route).count();
        if (!hub) await page.goBack();
        await tid(route).click();
        await page.waitForTimeout(500);
        const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
        if (m.sw > m.cw + 1) out.push(`${route} overflows (${m.sw} > ${m.cw})`);
        await page.screenshot({ path: path.join(SHOTS, `c-layout-${route}-${w}x${h}.png`), fullPage: true });
        await page.goBack();
      }
      must(out.length === 0, out.join('; '));
      return 'no sideways scrolling';
    });
  }

  await browser.close();
  server.close();

  const pass = results.filter((r) => r.ok).length;
  console.log('\n===== PHONE APP, PRACTICE SCREENS (web build) END-TO-END =====');
  results.forEach((r) => console.log(`${r.ok ? 'PASS' : 'FAIL'}  ${r.name}${r.note ? '  —  ' + r.note : ''}`));
  console.log(`\n${pass}/${results.length} steps passed`);
  const uniq = [...new Set(problems)];
  console.log(`\n===== BROWSER CONSOLE PROBLEMS (${uniq.length}) =====`);
  uniq.slice(0, 30).forEach((p) => console.log(p));
  console.log('\nScreenshots:', SHOTS);
  process.exit(pass === results.length && uniq.length === 0 ? 0 : 1);
})().catch((e) => { console.error('CRASH', e); try { server.close(); } catch {} process.exit(2); });
