// Fires identical/competing form posts in parallel at the real server (test DB) and reports status codes.
const { request } = require('playwright-core');
const BASE = process.env.E2E_BASE || 'http://127.0.0.1:8081';
if (process.env.E2E_CONFIRM_TEST_DB !== 'yes') { console.error('Refusing to run: these tests WRITE data. Point E2E_BASE at a server using the *_test database and set E2E_CONFIRM_TEST_DB=yes (see README.md).'); process.exit(3); }
const EMAIL = process.env.E2E_EMAIL;
const PASSWORD = process.env.E2E_PASSWORD;
if (!EMAIL || !PASSWORD) { console.error('Set E2E_EMAIL and E2E_PASSWORD to an account created in the TEST database.'); process.exit(3); }

(async () => {
  const ctx = await request.newContext({ baseURL: BASE, ignoreHTTPSErrors: process.env.E2E_IGNORE_TLS === 'yes' });
  const metaToken = async (url) => (await (await ctx.get(url)).text()).match(/name="csrf-token" content="([^"]+)"/)[1];

  // sign in first (the app is private)
  const loginPage = await (await ctx.get('/login')).text();
  const loginToken = loginPage.match(/name="_token" value="([^"]+)"/)[1];
  const login = await ctx.post('/login', { form: { _token: loginToken, email: EMAIL, password: PASSWORD }, maxRedirects: 0 });
  if (login.status() !== 302) { console.error('Sign-in failed, HTTP ' + login.status()); process.exit(2); }

  const token = await metaToken('/papers');
  const json = (url, data) => ctx.post(url, { data, headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' } });
  const form = (url, data) => ctx.post(url, { form: { _token: token, ...data }, maxRedirects: 0 });

  // setup: one paper + one question
  const paper = await (await json('/papers', { subject_id: 1, exam_year: 2024, paper_name: 'Paper I', max_marks: 100 })).json();
  const q = await (await json(`/papers/${paper.id}/questions`, { question_no: '1', max_marks: 10 })).json();

  const summary = (label, rs) => {
    const c = {}; rs.forEach((r) => { c[r.status()] = (c[r.status()] || 0) + 1; });
    console.log(label.padEnd(44), JSON.stringify(c));
    return c;
  };

  // 1. 12 simultaneous saves of the SAME daily-log date, each with different theory minutes
  summary('12 parallel daily-log saves (same date)',
    await Promise.all(Array.from({ length: 12 }, (_, i) => form('/daily-log', { log_date: new Date().toISOString().slice(0, 10), school_h: 1, theory_h: 0, theory_m: 10 + i }))));

  // 2. 12 simultaneous attempts on the SAME question
  summary('12 parallel attempts (same question)',
    await Promise.all(Array.from({ length: 12 }, (_, i) => form('/attempts', { question_id: q.id, attempt_date: new Date().toISOString().slice(0, 10), marks_earned: i % 11, result: 'partial', assistance: 'independent' }))));

  // 3. 8 simultaneous full-paper attempts on the same paper
  summary('8 parallel full-paper attempts (same paper)',
    await Promise.all(Array.from({ length: 8 }, () => form('/paper-attempts', { paper_id: paper.id, attempt_date: new Date().toISOString().slice(0, 10), marks_earned: 50, max_marks: 100 }))));

  // 4. 10 simultaneous registrations of the same paper / question
  summary('10 parallel registrations of one paper', await Promise.all(Array.from({ length: 10 }, () => json('/papers', { subject_id: 1, exam_year: 2023, paper_name: 'Paper II' }))));
  const p2 = await (await json('/papers', { subject_id: 1, exam_year: 2023, paper_name: 'Paper II' })).json();
  summary('10 parallel registrations of one question', await Promise.all(Array.from({ length: 10 }, () => json(`/papers/${p2.id}/questions`, { question_no: '5', max_marks: 8 }))));

  await ctx.dispose();
})().catch((e) => { console.error('CRASH', e.message); process.exit(2); });
