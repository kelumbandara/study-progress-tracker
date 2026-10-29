import { fireEvent, screen, waitFor } from '@testing-library/react-native';

import { HistoryScreen } from '@/features/more/HistoryScreen';
import { ReportScreen } from '@/features/more/ReportScreen';
import { SyllabusScreen } from '@/features/more/SyllabusScreen';

import { type FakeApi, fakeApi, renderApp, seedSession } from '../../test-utils/helpers';

const TODAY = '2026-11-10'; // a Tuesday
const bootstrap = {
  server_time: '2026-11-10T20:00:00+05:30', today: TODAY, timezone: 'Asia/Colombo',
  study_period: { start: '2026-10-28', end: '2027-07-31', exam_date: null }, paper_years: { from: 2022, to: 2024 },
  subjects: [
    { id: 1, name: 'Combined Mathematics', priority: 'high', priority_label: 'High', weak_score_pct: 60, slow_minutes_per_mark: null },
    { id: 2, name: 'Physics', priority: 'medium', priority_label: 'Medium', weak_score_pct: 60, slow_minutes_per_mark: null },
  ],
  options: {
    categories: [],
    topic_statuses: [
      { key: 'not_started', label: 'Not Started' }, { key: 'in_progress', label: 'In Progress' }, { key: 'theory_completed', label: 'Theory Completed' },
      { key: 'questions_practised', label: 'Questions Practised' }, { key: 'revision_required', label: 'Revision Required' }, { key: 'mastered', label: 'Mastered' },
    ],
    attempt_results: [{ key: 'correct', label: 'Correct' }, { key: 'partial', label: 'Partly correct' }, { key: 'wrong', label: 'Wrong' }, { key: 'not_completed', label: 'Not completed' }],
    mistake_categories: [{ key: 'concept', label: 'Concept gap' }, { key: 'careless', label: 'Careless error' }],
  },
  rules: { mastery_pct: 80, min_attempts_for_advice: 5, max_minutes_per_day: 1440 },
};

let api: FakeApi;
beforeEach(async () => {
  api = fakeApi();
  await seedSession();
  api.on('GET', '/bootstrap', { status: 200, body: bootstrap });
});
afterEach(() => api.restore());

const press = async (id: string) => fireEvent.press(await screen.findByTestId(id));
const type = async (id: string, text: string) => fireEvent.changeText(await screen.findByTestId(id), text);

// ---------------------------------------------------------------- syllabus

const stats = { total: 2, started: 1, theory_completed: 1, practised: 0, mastered: 0, revision: 0, not_practised: 2, theory_pct: 50, mastered_pct: 0, remaining: 1 };
const topic = (over: object = {}) => ({
  id: 11, name: 'Limits', reference: '1.1', status: 'in_progress', status_label: 'In Progress', confidence: null, theory_completed_on: null,
  theory_minutes: 0, practised: false, is_sample: false, subtopics: [], ...over,
});
const syllabus = {
  subject: bootstrap.subjects[0], stats,
  units: [{ id: 3, name: 'Calculus', reference: 'U1', is_sample: false, stats, topics: [topic({ subtopics: [topic({ id: 12, name: 'One-sided limits', reference: '1.1.1', status: 'not_started', status_label: 'Not Started' })] })] }],
};

describe('<SyllabusScreen />', () => {
  test('nothing loads until a subject is chosen; units open on tap and subtopics are listed', async () => {
    api.on('GET', '/syllabus', { status: 200, body: syllabus });
    await renderApp(<SyllabusScreen />);
    await screen.findByTestId('syl-subject-1');
    expect(api.calls.some((c) => c.path === '/syllabus')).toBe(false);

    await press('syl-subject-1');
    expect((await screen.findByTestId('syl-stats')).props.children).toMatch(/2 topics\. Theory done: 1 \(50%\)/);
    expect(api.calls.find((c) => c.path === '/syllabus')!.query.get('subject_id')).toBe('1');
    expect(screen.queryByTestId('topic-11')).toBeNull();

    await press('unit-3');
    expect(screen.getByTestId('topic-11').props.accessibilityLabel).toBe('Limits, In Progress');
    expect(screen.getByTestId('topic-12').props.accessibilityLabel).toBe('One-sided limits, Not Started');
  });

  test('saving a status sends the chosen status, confidence and note', async () => {
    api.on('GET', '/syllabus', { status: 200, body: syllabus });
    api.on('PUT', '/syllabus/topics/11', { status: 200, body: { topic: topic({ status: 'theory_completed', status_label: 'Theory Completed' }) } });
    await renderApp(<SyllabusScreen />);
    await press('syl-subject-1');
    await press('unit-3');
    await press('topic-11');
    await press('topic-status-theory_completed');
    await fireEvent.press(screen.getByLabelText('Topic confidence: 4'));
    await type('topic-note', 'Finished chapter');
    await press('topic-save');

    await waitFor(() => expect(api.calls.some((c) => c.method === 'PUT')).toBe(true));
    expect(api.calls.find((c) => c.method === 'PUT')!.body).toEqual({ status: 'theory_completed', confidence: 4, note: 'Finished chapter' });
    expect(await screen.findByTestId('topic-saved')).toBeTruthy();
  });

  test('the server refusing Mastered is shown as it is and nothing is changed on screen', async () => {
    api.on('GET', '/syllabus', { status: 200, body: syllabus });
    api.on('PUT', '/syllabus/topics/11', { status: 422, body: { message: 'A topic can only be mastered after an independent attempt of at least 80%.' } });
    await renderApp(<SyllabusScreen />);
    await press('syl-subject-1');
    await press('unit-3');
    await press('topic-11');
    await press('topic-status-mastered');
    await press('topic-save');
    expect(await screen.findByText(/only be mastered after an independent attempt of at least 80%/)).toBeTruthy();
    expect(screen.queryByTestId('topic-saved')).toBeNull();
  });
});

// ---------------------------------------------------------------- reports

const hoursRow = (label: string, actual: number, planned: number | null) => ({ label, planned_range: planned, actual_range: actual, cumulative: actual, planned_total: planned });
const report = (over: object = {}) => ({
  type: 'weekly', from: '2026-11-09', to: '2026-11-15', subject: 'All subjects',
  hours: { rows: { school: hoursRow('School', 600, null), theory: hoursRow('Study Theory', 150, 300) }, logged_days: 2 },
  consistency: { days_in_range: 2, days_logged: 2, days_missing: 0, days_with_independent: 1, rest_days: 0, avg_daily_minutes: 375, avg_daily_independent: 75, avg_weekly_minutes: 2625, avg_weekly_independent: 525 },
  questions: { attempts: 3, distinct: 2, earned: 15, available: 30, weighted_pct: 50, first: { n: 2, pct: 40 }, retry: { n: 1, pct: 70 } },
  topics_completed: ['Physics: Dimensions'], timed_papers: [], strong: [], weak: [],
  reviews: { reviewed: 1, theory: 0, understood: 1, reattempted: 0, mastered: 0 }, outstanding: 4,
  recommendations: [{ level: 'info', text: 'Combined Mathematics: not enough data yet.' }], ...over,
});

describe('<ReportScreen />', () => {
  test('opens on the current week, shows hours with "not set" planned time, and never calls missing days zero', async () => {
    api.on('GET', '/reports', { status: 200, body: report() });
    await renderApp(<ReportScreen />);
    expect((await screen.findByTestId('rep-range')).props.children).toBe('Mon 9 Nov 2026 to Sun 15 Nov 2026');
    const call = api.calls.find((c) => c.path === '/reports')!;
    expect(call.query.get('type')).toBe('weekly');
    expect(call.query.get('anchor')).toBe(TODAY);
    expect(call.query.has('subject_id')).toBe(false);
    expect(screen.getByTestId('hours-school').props.children).toMatch(/10 h 00 min \(planned hours not set\)/);
    expect(screen.getByTestId('hours-theory').props.children).toMatch(/2 h 30 min of 5 h 00 min planned/);
    expect(screen.getByTestId('rep-days').props.children).toBe('2 of 2');
    expect(screen.getByTestId('rep-outstanding').props.children).toBe('4');
  });

  test('Earlier moves back a whole week, Later is blocked on the current week', async () => {
    api.on('GET', '/reports', (url: URL) => ({ status: 200, body: url.searchParams.get('anchor') === TODAY ? report() : report({ from: '2026-11-02', to: '2026-11-08' }) }));
    await renderApp(<ReportScreen />);
    await screen.findByTestId('report-body');
    expect(screen.getByTestId('rep-next').props.accessibilityState?.disabled).toBe(true);
    await press('rep-prev');
    await waitFor(() => expect(screen.getByTestId('rep-range').props.children).toBe('Mon 2 Nov 2026 to Sun 8 Nov 2026'));
    expect(api.calls.filter((c) => c.path === '/reports').map((c) => c.query.get('anchor'))).toContain('2026-11-03');
    expect(screen.getByTestId('rep-next').props.accessibilityState?.disabled).toBeFalsy();
  });

  test('monthly adds the monthly sections; the subject filter is sent', async () => {
    const monthly = {
      coverage: [{ subject: 'Physics', theory_pct: 20, mastered_pct: 5, total: 40 }],
      trend: [{ subject: 'Physics', weeks: { '09 Nov': { pct: 55, n: 3 } } }, { subject: 'Combined Mathematics', weeks: [] }],
      papers: { by_paper: [] }, time_change: null, missed: [], forecast: [], remaining_days: 200,
    };
    api.on('GET', '/reports', (url: URL) => ({ status: 200, body: url.searchParams.get('type') === 'monthly' ? report({ type: 'monthly', from: '2026-11-01', to: '2026-11-30', monthly }) : report() }));
    await renderApp(<ReportScreen />);
    await screen.findByTestId('report-body');
    expect(screen.queryByTestId('report-monthly')).toBeNull();
    await press('rep-type-monthly');
    expect(await screen.findByTestId('report-monthly')).toBeTruthy();
    expect(screen.getByText('Week of 09 Nov: 55% (3 attempts)')).toBeTruthy();
    expect(screen.getByText(/Not enough repeated, timed questions yet/)).toBeTruthy();
    await press('rep-subject-2');
    await waitFor(() => expect(api.calls.some((c) => c.query.get('subject_id') === '2')).toBe(true));
  });

  test('a failed load says so and offers to try again', async () => {
    api.on('GET', '/reports', { status: 500, body: { message: 'Server error' } });
    await renderApp(<ReportScreen />);
    expect(await screen.findByText(/Cannot load the report/)).toBeTruthy();
    expect(screen.getByText('Try again')).toBeTruthy();
  });
});

// ---------------------------------------------------------------- history and corrections

const attemptRow = (over: object = {}) => ({
  id: 77, question_id: 9, attempt_no: 2, date: '2026-11-08', marks_earned: 6, max_marks: 10, percent: 60, worked_minutes: 75, result: 'partial', result_label: 'Partly correct',
  independent: false, checked_with_scheme: true, mistake_category: 'concept', notes: 'first try', paper_attempt_id: null,
  question: { id: 9, number: '3', paper_label: '2024 Paper I', subject: 'Physics' }, ...over,
});
const attemptPage = (rows: object[], page = 1, last = 1) => ({ data: rows, meta: { page, last_page: last, total: rows.length } });

describe('<HistoryScreen /> attempts', () => {
  test('lists attempts and opens a correction pre-filled from the server; the answer on independence is kept as saved', async () => {
    api.on('GET', '/attempts', { status: 200, body: attemptPage([attemptRow()]) });
    await renderApp(<HistoryScreen />);
    expect((await screen.findByTestId('attempt-row-77')).props.children).toBe('Physics 2024 Paper I, question 3, attempt 2');
    await press('attempt-edit-77');

    expect((await screen.findByTestId('attempt-marks')).props.value).toBe('6');
    expect(screen.getByTestId('attempt-independent-no').props.accessibilityState.selected).toBe(true);
    expect(screen.getByTestId('attempt-result-partial').props.accessibilityState.selected).toBe(true);
    expect(screen.getByTestId('attempt-date-label')).toBeTruthy();
  });

  test('saving sends only the editable fields to the attempt, never a new attempt', async () => {
    api.on('GET', '/attempts', { status: 200, body: attemptPage([attemptRow()]) });
    api.on('PUT', '/attempts/77', { status: 200, body: { attempt: attemptRow({ marks_earned: 8, percent: 80, independent: true }) } });
    await renderApp(<HistoryScreen />);
    await press('attempt-edit-77');
    await type('attempt-marks', '8');
    await press('attempt-independent-yes');
    await press('correct-save');

    await waitFor(() => expect(api.calls.some((c) => c.method === 'PUT')).toBe(true));
    const put = api.calls.find((c) => c.method === 'PUT')!;
    expect(put.path).toBe('/attempts/77');
    expect(put.body).toEqual({
      attempt_date: '2026-11-08', marks_earned: 8, result: 'partial', independent: true, worked_minutes: 75,
      checked_with_scheme: true, mistake_category: 'concept', notes: 'first try',
    });
    expect(api.calls.some((c) => c.method === 'POST')).toBe(false);
    expect(await screen.findByTestId('attempt-corrected')).toBeTruthy();
  });

  test('marks above the question maximum are refused before sending', async () => {
    api.on('GET', '/attempts', { status: 200, body: attemptPage([attemptRow()]) });
    await renderApp(<HistoryScreen />);
    await press('attempt-edit-77');
    await type('attempt-marks', '12');
    await press('correct-save');
    expect(await screen.findByText(/cannot be more than the question's maximum of 10/)).toBeTruthy();
    expect(api.calls.some((c) => c.method === 'PUT')).toBe(false);
  });

  test('older entries load a page at a time and the subject filter is sent', async () => {
    api.on('GET', '/attempts', (url: URL) => ({
      status: 200,
      body: url.searchParams.get('page') === '2' ? attemptPage([attemptRow({ id: 70, attempt_no: 1 })], 2, 2) : attemptPage([attemptRow()], 1, 2),
    }));
    await renderApp(<HistoryScreen />);
    await screen.findByTestId('attempt-row-77');
    expect(screen.queryByTestId('attempt-row-70')).toBeNull();
    await press('hist-more');
    expect(await screen.findByTestId('attempt-row-70')).toBeTruthy();
    expect(screen.queryByTestId('hist-more')).toBeNull();
    await press('hist-subject-2');
    await waitFor(() => expect(api.calls.some((c) => c.path === '/attempts' && c.query.get('subject_id') === '2')).toBe(true));
  });

  test('an empty history says so', async () => {
    api.on('GET', '/attempts', { status: 200, body: attemptPage([]) });
    await renderApp(<HistoryScreen />);
    expect(await screen.findByTestId('hist-empty')).toBeTruthy();
  });
});

const theoryRow = (over: object = {}) => ({
  id: 31, topic_id: 11, date: '2026-11-09', minutes: 90, marked_complete: true, confidence: 3, notes: null,
  topic: { id: 11, name: 'Limits', status: 'theory_completed', subject: 'Combined Mathematics' }, ...over,
});

describe('<HistoryScreen /> theory', () => {
  test('a theory entry is corrected with date, minutes, confidence and notes only, and the server warning is shown', async () => {
    api.on('GET', '/theory', { status: 200, body: { data: [theoryRow()], meta: { page: 1, last_page: 1, total: 1 } } });
    api.on('PUT', '/theory/31', { status: 200, body: { entry: theoryRow({ minutes: 45 }), warnings: ['Theory detail is more than the day total.'] } });
    await renderApp(<HistoryScreen />);
    await press('hist-kind-theory');
    expect((await screen.findByTestId('theory-row-31')).props.children).toBe('Combined Mathematics: Limits');
    await press('theory-edit-31');
    await screen.findByTestId('correct-save');
    await fireEvent.changeText(screen.getByTestId('dur-theory-edit-hours'), '0');
    await fireEvent.changeText(screen.getByTestId('dur-theory-edit-mins'), '45');
    await type('theory-notes', 'shortened');
    await press('correct-save');

    await waitFor(() => expect(api.calls.some((c) => c.method === 'PUT')).toBe(true));
    expect(api.calls.find((c) => c.method === 'PUT')!.body).toEqual({ study_date: '2026-11-09', minutes: 45, confidence: 3, notes: 'shortened' });
    expect(await screen.findByTestId('theory-corrected')).toBeTruthy();
    expect(screen.getByText('Theory detail is more than the day total.')).toBeTruthy();
  });

  test('0 minutes is refused before sending', async () => {
    api.on('GET', '/theory', { status: 200, body: { data: [theoryRow()], meta: { page: 1, last_page: 1, total: 1 } } });
    await renderApp(<HistoryScreen />);
    await press('hist-kind-theory');
    await press('theory-edit-31');
    await screen.findByTestId('correct-save');
    await fireEvent.changeText(screen.getByTestId('dur-theory-edit-hours'), '0');
    await fireEvent.changeText(screen.getByTestId('dur-theory-edit-mins'), '0');
    await press('correct-save');
    expect(await screen.findByText(/at least 1 minute/)).toBeTruthy();
    expect(api.calls.some((c) => c.method === 'PUT')).toBe(false);
  });
});
