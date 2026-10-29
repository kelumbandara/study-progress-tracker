// Phone-app style smoke test of the token API: plain fetch + bearer token, like React Native will do.
// WRITES DATA. Run only against a server that uses a throw-away database (see README.md).
const BASE = (process.env.E2E_BASE || 'http://127.0.0.1:8081').replace(/\/$/, '');
if (process.env.E2E_CONFIRM_TEST_DB !== 'yes') { console.error('Refusing to run: this test WRITES data. Set E2E_CONFIRM_TEST_DB=yes and point E2E_BASE at a throw-away server.'); process.exit(3); }
const EMAIL = process.env.E2E_EMAIL, PASSWORD = process.env.E2E_PASSWORD;
if (!EMAIL || !PASSWORD) { console.error('Set E2E_EMAIL and E2E_PASSWORD.'); process.exit(3); }
if (process.env.E2E_IGNORE_TLS === 'yes') process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0'; // self-signed local proxy only

const results = [];
let token = null;
const timings = [];

async function call(method, path, body, { auth = true, headers = {} } = {}) {
  const t0 = performance.now();
  const res = await fetch(BASE + '/api/v1' + path, {
    method, redirect: 'manual',
    headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}), ...(auth && token ? { Authorization: `Bearer ${token}` } : {}), ...headers },
    body: body ? JSON.stringify(body) : undefined,
  });
  timings.push(performance.now() - t0);
  let json = null; const text = await res.text();
  try { json = JSON.parse(text); } catch { /* keep null */ }
  return { status: res.status, json, text, headers: res.headers };
}
async function step(name, fn) {
  try { results.push({ name, ok: true, note: (await fn()) || '' }); } catch (e) { results.push({ name, ok: false, note: String(e.message).split('\n')[0].slice(0, 300) }); }
}
const must = (cond, msg) => { if (!cond) throw new Error(msg); };
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Colombo' });

(async () => {
  let topicId, paperId, questionId, attemptId;

  await step('Without a token every call is refused with JSON 401', async () => {
    for (const p of ['/dashboard', '/bootstrap', '/daily-logs/' + today(), '/mistakes']) {
      const r = await call('GET', p, null, { auth: false });
      must(r.status === 401 && r.json && r.json.message, `${p} -> ${r.status}`);
    }
    return 'protected';
  });
  await step('Wrong password is refused, no token issued', async () => {
    const r = await call('POST', '/auth/login', { email: EMAIL, password: 'not the password 1', device_name: 'api.js' }, { auth: false });
    must(r.status === 422 && r.json.errors && r.json.errors.email && !r.json.token, 'status ' + r.status);
    return 'HTTP 422';
  });
  await step('Sign in returns a token (no cookies)', async () => {
    const r = await call('POST', '/auth/login', { email: EMAIL, password: PASSWORD, device_name: 'api.js phone' }, { auth: false });
    must(r.status === 200 && r.json.token && r.json.token_type === 'Bearer', 'status ' + r.status + ' ' + r.text.slice(0, 120));
    must(!r.headers.get('set-cookie'), 'the API set a cookie');
    token = r.json.token;
    return `expires ${r.json.expires_at.slice(0, 10)}`;
  });
  await step('Security headers on API responses, each once', async () => {
    const r = await call('GET', '/me');
    must(r.status === 200 && r.json.user.email === EMAIL.toLowerCase(), 'me failed');
    must(r.headers.get('x-content-type-options') === 'nosniff', 'nosniff missing');
    must(r.headers.get('content-type').startsWith('application/json'), 'not JSON');
    return 'ok';
  });
  await step('Bootstrap: server date, period, subjects, options', async () => {
    const r = await call('GET', '/bootstrap');
    must(r.status === 200 && r.json.subjects.length === 3 && r.json.rules.mastery_pct === 80, 'unexpected bootstrap');
    must(r.json.today === today(), `server today ${r.json.today} vs expected ${today()}`);
    return `today ${r.json.today}, ${r.json.options.categories.length} categories`;
  });
  await step('Daily log: missing, save, zero day vs missing, edit', async () => {
    const d = today();
    let r = await call('GET', '/daily-logs/' + d);
    must(r.json.log.logged === false && r.json.log.minutes === null, 'a missing day must not look like zero');
    r = await call('PUT', '/daily-logs/' + d, { minutes: { school: 330, tuition: 120, theory: 75, papers: 120 }, rating: 4, notes: 'api test' });
    must(r.status === 200 && r.json.log.total_minutes === 645, 'save failed ' + r.status);
    r = await call('PUT', '/daily-logs/' + d, { minutes: { school: 330, tuition: 120, theory: 135, papers: 120 } });
    must(r.status === 200 && r.json.log.minutes.theory === 135, 'edit failed');
    r = await call('PUT', '/daily-logs/' + d, { minutes: { school: 1000, tuition: 500, theory: 0, papers: 0 } });
    must(r.status === 422, 'over 24 h should be refused');
    return 'saved 645 min, edited theory 75 → 135, 24 h limit enforced';
  });
  await step('Syllabus (needs the NIE data loaded) and topic picker', async () => {
    const r = await call('GET', '/syllabus?subject_id=1');
    must(r.status === 200 && r.json.units.length >= 1, 'no units: load the NIE syllabus first');
    topicId = r.json.units[0].topics[0].id;
    const t = await call('GET', '/topics?subject_id=1');
    must(t.json.data.length > 10, 'topics missing');
    return `${r.json.units.length} units, first topic #${topicId}`;
  });
  await step('Register a paper and a question; a retry is harmless', async () => {
    const body = { subject_id: 1, exam_year: 2024, paper_name: 'Paper I', max_marks: 100, duration_minutes: 180 };
    const a = await call('POST', '/papers', body), b = await call('POST', '/papers', body);
    must(a.status === 201 && b.status === 200 && b.json.created === false && a.json.paper.id === b.json.paper.id, 'paper registration');
    paperId = a.json.paper.id;
    const qb = { question_no: '1', max_marks: 10, topic_ids: [topicId] };
    const qa = await call('POST', `/papers/${paperId}/questions`, qb), qc = await call('POST', `/papers/${paperId}/questions`, qb);
    must(qa.status === 201 && qc.status === 200 && qa.json.question.id === qc.json.question.id, 'question registration');
    questionId = qa.json.question.id;
    return `paper #${paperId}, question #${questionId}`;
  });
  await step('Three attempts are kept; improvement is reported; mastery needs evidence', async () => {
    const mk = (marks, mins) => call('POST', '/attempts', { question_id: questionId, attempt_date: today(), marks_earned: marks, worked_minutes: mins, result: marks >= 10 ? 'correct' : 'partial', independent: true });
    const a1 = await mk(4, 35); must(a1.status === 201 && a1.json.attempt.attempt_no === 1, 'a1');
    attemptId = a1.json.attempt.id;
    const refuse = await call('PUT', '/syllabus/topics/' + topicId, { status: 'mastered' });
    must(refuse.status === 422, 'Mastered must be refused after a 40% attempt');
    const a2 = await mk(8, 25); const a3 = await mk(10, 20);
    must(a3.json.attempt.attempt_no === 3 && a3.json.improvement.score_points === 60 && a3.json.improvement.minutes_change === -15, 'improvement ' + JSON.stringify(a3.json.improvement));
    const ok = await call('PUT', '/syllabus/topics/' + topicId, { status: 'mastered' });
    must(ok.status === 200 && ok.json.topic.status === 'mastered', 'mastery after evidence');
    const bad = await call('POST', '/attempts', { question_id: questionId, attempt_date: today(), marks_earned: 11, result: 'correct', independent: true });
    must(bad.status === 422, 'marks above the maximum');
    return 'attempts 1-3 kept; +60 points, 15 min faster; Mastered refused then allowed';
  });
  await step('Correct an attempt (edit in place)', async () => {
    const r = await call('PUT', '/attempts/' + attemptId, { attempt_date: today(), marks_earned: 5, result: 'partial', independent: true, worked_minutes: 35, notes: 'typo' });
    must(r.status === 200 && r.json.attempt.marks_earned === 5 && r.json.attempt.attempt_no === 1, 'correction failed');
    const h = await call('GET', `/questions/${questionId}/attempts`);
    must(h.json.attempts.length === 3, 'a correction must not add a row');
    return 'attempt 1 corrected 4 → 5; still 3 rows';
  });
  await step('Theory, mistakes, reports, dashboard answer', async () => {
    const th = await call('POST', '/theory', { study_date: today(), topic_id: topicId, minutes: 40, marked_complete: true, confidence: 3 });
    must(th.status === 201, 'theory ' + th.status);
    must((await call('GET', '/mistakes')).status === 200, 'mistakes');
    const rep = await call('GET', '/reports?type=monthly');
    must(rep.status === 200 && rep.json.monthly, 'report');
    const dash = await call('GET', '/dashboard');
    must(dash.status === 200 && dash.json.categories.length === 4 && dash.json.today_logged === true, 'dashboard');
    return 'all returned 200 with the expected shape';
  });
  await step('Parallel requests: no errors, no duplicates, numbering intact', async () => {
    const attempts = await Promise.all(Array.from({ length: 12 }, (_, i) => call('POST', '/attempts', { question_id: questionId, attempt_date: today(), marks_earned: i % 11, result: 'partial', independent: true })));
    must(attempts.every((r) => r.status === 201), 'attempt statuses ' + attempts.map((r) => r.status).join(','));
    const nos = attempts.map((r) => r.json.attempt.attempt_no).sort((a, b) => a - b);
    must(new Set(nos).size === 12 && nos[0] === 4 && nos[11] === 15, 'attempt numbers ' + nos.join(','));
    const papers = await Promise.all(Array.from({ length: 10 }, () => call('POST', '/papers', { subject_id: 1, exam_year: 2023, paper_name: 'Paper II' })));
    must(papers.every((r) => r.status === 200 || r.status === 201) && papers.filter((r) => r.status === 201).length === 1, 'paper statuses ' + papers.map((r) => r.status).join(','));
    const logs = await Promise.all(Array.from({ length: 10 }, (_, i) => call('PUT', '/daily-logs/' + today(), { minutes: { school: 60, tuition: 0, theory: 10 + i, papers: 0 } })));
    must(logs.every((r) => r.status === 200), 'daily log statuses ' + logs.map((r) => r.status).join(','));
    const list = await call('GET', '/daily-logs?per_page=5');
    must(list.json.meta.total === 1, 'duplicate daily logs: total ' + list.json.meta.total);
    return 'attempts 4-15 unique; 1 new paper of 10 requests; 1 daily log after 10 saves';
  });
  await step('Sign out revokes the token', async () => {
    const out = await call('POST', '/auth/logout');
    must(out.status === 200, 'logout ' + out.status);
    const after = await call('GET', '/me');
    must(after.status === 401, 'token still works after logout (' + after.status + ')');
    return 'token revoked';
  });

  const pass = results.filter((r) => r.ok).length;
  console.log('\n===== PHONE-STYLE API TEST =====');
  results.forEach((r) => console.log(`${r.ok ? 'PASS' : 'FAIL'}  ${r.name}${r.note ? '  —  ' + r.note : ''}`));
  const sorted = [...timings].sort((a, b) => a - b);
  console.log(`\n${pass}/${results.length} steps passed · ${timings.length} requests · median ${sorted[Math.floor(sorted.length / 2)].toFixed(0)} ms · slowest ${sorted[sorted.length - 1].toFixed(0)} ms`);
  process.exit(pass === results.length ? 0 : 1);
})().catch((e) => { console.error('CRASH', e); process.exit(2); });
