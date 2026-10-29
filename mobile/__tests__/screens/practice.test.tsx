import { fireEvent, screen, waitFor } from '@testing-library/react-native';

import { MistakesScreen } from '@/features/practice/MistakesScreen';
import { RecordQuestionScreen } from '@/features/practice/RecordQuestionScreen';
import { TheoryScreen } from '@/features/practice/TheoryScreen';

import { type FakeApi, fakeApi, renderApp, seedSession } from '../../test-utils/helpers';

const TODAY = '2026-11-10';
const bootstrap = {
  server_time: '2026-11-10T20:00:00+05:30', today: TODAY, timezone: 'Asia/Colombo',
  study_period: { start: '2026-10-28', end: '2027-07-31', exam_date: null }, paper_years: { from: 2022, to: 2024 },
  subjects: [
    { id: 1, name: 'Combined Mathematics', priority: 'high', priority_label: 'High', weak_score_pct: 60, slow_minutes_per_mark: null },
    { id: 2, name: 'Physics', priority: 'medium', priority_label: 'Medium', weak_score_pct: 60, slow_minutes_per_mark: null },
  ],
  options: {
    categories: [],
    topic_statuses: [],
    attempt_results: [{ key: 'correct', label: 'Correct' }, { key: 'partial', label: 'Partly correct' }, { key: 'wrong', label: 'Wrong' }, { key: 'not_completed', label: 'Not completed' }],
    mistake_categories: [{ key: 'concept', label: 'Concept gap' }, { key: 'careless', label: 'Careless error' }],
  },
  rules: { mastery_pct: 80, min_attempts_for_advice: 5, max_minutes_per_day: 1440 },
};

const paper = (over: object = {}) => ({ id: 5, subject_id: 1, year: 2024, name: 'Paper I', label: '2024 Paper I', max_marks: null, duration_minutes: null, questions_count: 1, attempts_count: 0, ...over });
const question = (over: object = {}) => ({ id: 9, paper_id: 5, number: '3', max_marks: 10, label: 'Q3', reference: null, notes: null, attempts_count: 0, topic_ids: [], ...over });
const attemptReply = (over: object = {}) => ({
  attempt: { id: 77, question_id: 9, attempt_no: 1, date: TODAY, marks_earned: 7.5, max_marks: 10, percent: 75, worked_minutes: null, result: 'partial', result_label: 'Partly correct', independent: true, checked_with_scheme: false, mistake_category: null, notes: null },
  improvement: null, topics: [], warnings: [], ...over,
});

let api: FakeApi;
beforeEach(async () => {
  api = fakeApi();
  await seedSession();
  api.on('GET', '/bootstrap', { status: 200, body: bootstrap });
});
afterEach(() => api.restore());

const press = async (id: string) => fireEvent.press(await screen.findByTestId(id));
const type = async (id: string, text: string) => fireEvent.changeText(await screen.findByTestId(id), text);
const selected = (id: string) => {
  const state = screen.getByTestId(id).props.accessibilityState ?? {};
  return Boolean(state.selected ?? state.checked);
};

async function reachQuestion() {
  await press('subject-1');
  await press('year-2024');
  await press('paper-5');
  await press('question-9');
}

describe('<RecordQuestionScreen />', () => {
  beforeEach(() => {
    api.on('GET', '/papers', { status: 200, body: { data: [paper()] } });
    api.on('GET', '/papers/5/questions', { status: 200, body: { paper: paper(), data: [question()] } });
  });

  test('Subject → Year → Paper → Question each load only when the step before is chosen', async () => {
    await renderApp(<RecordQuestionScreen />);
    await screen.findByTestId('subject-1');
    expect(screen.queryByTestId('year-2024')).toBeNull();
    expect(api.calls.some((c) => c.path === '/papers')).toBe(false);

    await press('subject-1');
    expect(screen.getAllByTestId(/^year-/).map((n) => n.props.accessibilityLabel)).toEqual(['Exam year: 2024', 'Exam year: 2023', 'Exam year: 2022']);
    await press('year-2024');
    await press('paper-5');
    const papersCall = api.calls.find((c) => c.path === '/papers')!;
    expect(papersCall.query.get('subject_id')).toBe('1');
    expect(papersCall.query.get('year')).toBe('2024');
    await press('question-9');
    expect(await screen.findByTestId('attempt-save')).toBeTruthy();
    expect(screen.getByText(/This will be your first attempt/)).toBeTruthy();

    // Changing the subject clears everything below it, so a question can never be saved against the wrong paper.
    await press('subject-2');
    expect(screen.queryByTestId('attempt-save')).toBeNull();
    expect(screen.queryByTestId('paper-5')).toBeNull();
  });

  test('independence and the result must be chosen; nothing is sent until the form is valid', async () => {
    await renderApp(<RecordQuestionScreen />);
    await reachQuestion();
    await type('attempt-marks', '7.5');
    await press('attempt-save');
    expect(await screen.findByTestId('attempt-invalid')).toBeTruthy();
    expect(screen.getByText(/Choose how it went/)).toBeTruthy();
    expect(screen.getByText(/Say whether you did it without help/)).toBeTruthy();
    expect(api.calls.filter((c) => c.method === 'POST')).toHaveLength(0);
  });

  test('marks above the question maximum are refused before sending', async () => {
    await renderApp(<RecordQuestionScreen />);
    await reachQuestion();
    await type('attempt-marks', '11');
    await press('attempt-result-correct');
    await press('attempt-independent-yes');
    await press('attempt-save');
    expect(await screen.findByText(/cannot be more than the question's maximum of 10/)).toBeTruthy();
    expect(api.calls.filter((c) => c.method === 'POST')).toHaveLength(0);
  });

  test('saving sends exactly what was entered, with the server date, and shows the result', async () => {
    api.on('POST', '/attempts', { status: 201, body: attemptReply({ topics: [{ id: 4, name: 'Limits', status: 'questions_practised' }], warnings: ['Detail minutes exceed the day total.'] }) });
    await renderApp(<RecordQuestionScreen />);
    await reachQuestion();
    await type('attempt-marks', '7.5');
    await press('attempt-result-partial');
    await press('attempt-independent-yes');
    await type('dur-worked-hours', '1');
    await type('dur-worked-mins', '20');
    await fireEvent(screen.getByTestId('attempt-scheme'), 'valueChange', true);
    await press('attempt-mistake-careless');
    await type('attempt-notes', ' slow ');
    await press('attempt-save');

    expect(await screen.findByTestId('attempt-saved')).toBeTruthy();
    const post = api.calls.find((c) => c.method === 'POST' && c.path === '/attempts')!;
    expect(post.body).toEqual({
      question_id: 9, attempt_date: TODAY, marks_earned: 7.5, result: 'partial', independent: true, worked_minutes: 80,
      checked_with_scheme: true, mistake_category: 'careless', notes: 'slow',
    });
    expect(post.headers.Authorization).toBe('Bearer stored-token');
    expect(screen.getByText(/7.5 of 10 marks \(75%\), independent/)).toBeTruthy();
    expect(screen.getByTestId('topic-status-4').props.children).toBe('Limits: questions practised');
    expect(screen.getByText(/Detail minutes exceed the day total/)).toBeTruthy();
    // The form is gone, so a second tap cannot silently add a duplicate attempt.
    expect(screen.queryByTestId('attempt-save')).toBeNull();
    expect(api.calls.filter((c) => c.method === 'POST')).toHaveLength(1);
  });

  test('"Record another attempt" opens a fresh form for the same question', async () => {
    api.on('POST', '/attempts', { status: 201, body: attemptReply() });
    await renderApp(<RecordQuestionScreen />);
    await reachQuestion();
    await type('attempt-marks', '7.5');
    await press('attempt-result-partial');
    await press('attempt-independent-no');
    await press('attempt-save');
    await press('attempt-again');
    expect((await screen.findByTestId('attempt-marks')).props.value).toBe('');
    expect(selected('attempt-independent-yes')).toBe(false);
    expect(selected('attempt-independent-no')).toBe(false);
  });

  test('improvement over the first attempt is shown from the second attempt on', async () => {
    api.on('POST', '/attempts', {
      status: 201,
      body: attemptReply({ attempt: { ...attemptReply().attempt, attempt_no: 2, marks_earned: 9, percent: 90 }, improvement: { score_points: 15, minutes_change: -10, best_percent: 90, first_percent: 75, latest_percent: 90 } }),
    });
    await renderApp(<RecordQuestionScreen />);
    await reachQuestion();
    await type('attempt-marks', '9');
    await press('attempt-result-correct');
    await press('attempt-independent-yes');
    await press('attempt-save');
    const text = (await screen.findByTestId('attempt-improvement')).props.children.join('');
    expect(text).toBe('First attempt 75%, this one 90% (+15 points). Best so far 90%. Time down by 0 h 10 min.');
  });

  test('a refusal from the server is shown and nothing typed is lost; a dropped connection can be retried', async () => {
    let online = false;
    api.on('POST', '/attempts', () => (online ? { status: 201, body: attemptReply() } : { status: 0, networkError: true }));
    await renderApp(<RecordQuestionScreen />);
    await reachQuestion();
    await type('attempt-marks', '5');
    await press('attempt-result-wrong');
    await press('attempt-independent-yes');
    await press('attempt-save');
    expect(await screen.findByText(/Not saved: no connection/)).toBeTruthy();
    expect(screen.getByTestId('attempt-marks').props.value).toBe('5');

    online = true;
    await press('attempt-save');
    expect(await screen.findByTestId('attempt-saved')).toBeTruthy();
    expect(screen.queryByTestId('attempt-error')).toBeNull();
  });

  test('a paper that is not registered can be added, and is then selected', async () => {
    api.on('GET', '/papers', { status: 200, body: { data: [] } });
    api.on('POST', '/papers', { status: 201, body: { created: true, paper: paper({ id: 5 }) } });
    await renderApp(<RecordQuestionScreen />);
    await press('subject-1');
    await press('year-2024');
    expect(await screen.findByTestId('no-papers')).toBeTruthy();

    await press('paper-new');
    await press('paper-add');
    expect(await screen.findByText(/Give the paper a name/)).toBeTruthy();
    expect(api.calls.filter((c) => c.method === 'POST')).toHaveLength(0);

    api.on('GET', '/papers', { status: 200, body: { data: [paper()] } });
    await type('paper-name', ' Paper I ');
    await press('paper-add');
    await waitFor(() => expect(api.calls.some((c) => c.method === 'POST' && c.path === '/papers')).toBe(true));
    expect(api.calls.find((c) => c.method === 'POST' && c.path === '/papers')!.body).toEqual({ subject_id: 1, exam_year: 2024, paper_name: 'Paper I' });
    expect(await screen.findByTestId('question-9')).toBeTruthy(); // the new paper is selected, so its questions load
  });

  test('a question that is not registered can be added with its topics and is then selected', async () => {
    api.on('GET', '/papers/5/questions', { status: 200, body: { paper: paper(), data: [] } });
    api.on('GET', '/topics', { status: 200, body: { data: [
      { id: 4, name: 'Limits', full_name: 'Calculus › Limits', unit: 'Calculus', status: 'not_started' },
      { id: 6, name: 'Vectors', full_name: 'Geometry › Vectors', unit: 'Geometry', status: 'not_started' },
    ] } });
    api.on('POST', '/papers/5/questions', { status: 201, body: { created: true, question: question({ id: 11, number: '2(a)', max_marks: 6 }) } });
    await renderApp(<RecordQuestionScreen />);
    await press('subject-1');
    await press('year-2024');
    await press('paper-5');
    await press('question-new');
    await type('question-number', '2(a)');
    await type('question-max', '6');
    await press('question-topic-4');
    await press('question-topic-6');
    await press('question-topic-6'); // untick again
    api.on('GET', '/papers/5/questions', { status: 200, body: { paper: paper(), data: [question({ id: 11, number: '2(a)', max_marks: 6 })] } });
    await press('question-add');
    await waitFor(() => expect(api.calls.some((c) => c.method === 'POST' && c.path === '/papers/5/questions')).toBe(true));
    expect(api.calls.find((c) => c.method === 'POST' && c.path === '/papers/5/questions')!.body).toEqual({ question_no: '2(a)', max_marks: 6, topic_ids: [4] });
    expect(await screen.findByText(/Worth 6 marks/)).toBeTruthy();
  });
});

describe('<TheoryScreen />', () => {
  const topics = { data: [
    { id: 4, name: 'Limits', full_name: 'Calculus › Limits', unit: 'Calculus', status: 'not_started' },
    { id: 6, name: 'Vectors', full_name: 'Geometry › Vectors', unit: 'Geometry', status: 'in_progress' },
  ] };
  beforeEach(() => {
    api.on('GET', '/topics', { status: 200, body: topics });
    api.on('GET', '/theory', { status: 200, body: { data: [], meta: { page: 1, last_page: 1, total: 0 } } });
  });

  test('topic and time are required; nothing is sent until they are', async () => {
    await renderApp(<TheoryScreen />);
    await press('theory-subject-1');
    await press('theory-save');
    expect(await screen.findByText(/Choose the topic you studied/)).toBeTruthy();
    expect(screen.getByText(/at least 1 minute/)).toBeTruthy();
    expect(api.calls.filter((c) => c.method === 'POST')).toHaveLength(0);
  });

  test('the topic list can be searched and only one topic can be chosen', async () => {
    await renderApp(<TheoryScreen />);
    await press('theory-subject-1');
    await screen.findByTestId('theory-topic-4');
    await type('theory-topic-search', 'vect');
    await waitFor(() => expect(screen.queryByTestId('theory-topic-4')).toBeNull());
    expect(screen.getByTestId('theory-topic-6')).toBeTruthy();
    await type('theory-topic-search', '');
    await press('theory-topic-4');
    await press('theory-topic-6');
    expect(selected('theory-topic-4')).toBe(false);
    expect(selected('theory-topic-6')).toBe(true);
  });

  test('saving sends minutes, shows the server warning and gets ready for the next topic', async () => {
    api.on('POST', '/theory', (_u, _i, body) => ({
      status: 201,
      body: { entry: { id: 3, topic_id: body.topic_id, date: body.study_date, minutes: body.minutes, marked_complete: body.marked_complete, confidence: body.confidence, notes: body.notes, topic: { id: 4, name: 'Limits', status: 'in_progress', subject: 'Combined Mathematics' } }, warnings: ['Detail minutes exceed the day\'s Study Theory total.'] },
    }));
    await renderApp(<TheoryScreen />);
    await press('theory-subject-1');
    await press('theory-topic-4');
    await type('dur-theory-entry-hours', '1');
    await type('dur-theory-entry-mins', '15');
    await fireEvent(screen.getByTestId('theory-complete'), 'valueChange', true);
    await press('rating-4');
    await type('theory-notes', ' good ');
    await press('theory-save');

    expect(await screen.findByTestId('theory-saved')).toBeTruthy();
    expect(api.calls.find((c) => c.method === 'POST')!.body).toEqual({ study_date: TODAY, topic_id: 4, minutes: 75, marked_complete: true, confidence: 4, notes: 'good' });
    expect(screen.getByText(/Limits: 1 h 15 min on Tue 10 Nov 2026 is saved, and the theory is marked complete/)).toBeTruthy();
    expect(screen.getByText(/Detail minutes exceed/)).toBeTruthy();
    expect(selected('theory-topic-4')).toBe(false); // cleared for the next topic
    expect(screen.getByTestId('dur-theory-entry-hours').props.value).toBe('0');
  });

  test('a refusal from the server is shown and the entry is kept', async () => {
    api.on('POST', '/theory', { status: 422, body: { message: 'x', errors: { study_date: ['The study date must not be in the future.'] } } });
    await renderApp(<TheoryScreen />);
    await press('theory-subject-1');
    await press('theory-topic-4');
    await type('dur-theory-entry-mins', '30');
    await press('theory-save');
    expect(await screen.findByText(/must not be in the future/)).toBeTruthy();
    expect(screen.getByTestId('dur-theory-entry-mins').props.value).toBe('30');
  });

  test('recent entries are listed', async () => {
    api.on('GET', '/theory', { status: 200, body: { data: [{ id: 1, topic_id: 4, date: '2026-11-09', minutes: 45, marked_complete: false, confidence: null, notes: null, topic: { id: 4, name: 'Limits', status: 'in_progress', subject: 'Combined Mathematics' } }], meta: { page: 1, last_page: 1, total: 1 } } });
    await renderApp(<TheoryScreen />);
    expect(await screen.findByTestId('theory-entry-1')).toBeTruthy();
    expect(screen.getByText('Mon 9 Nov 2026 · 0 h 45 min')).toBeTruthy();
  });
});

describe('<MistakesScreen />', () => {
  const row = (over: object = {}) => ({
    question: { id: 9, number: '3', max_marks: 10, paper_label: '2024 Paper I', subject: 'Combined Mathematics', topics: [{ id: 4, name: 'Limits' }] },
    latest_attempt: attemptReply().attempt, reasons: ['Latest score below 80%'], attempts: 3, weak_attempts: 2, repeated: true, last_review: null, ...over,
  });

  test('an empty queue says so', async () => {
    api.on('GET', '/mistakes', { status: 200, body: { count: 0, data: [] } });
    await renderApp(<MistakesScreen />);
    expect(await screen.findByTestId('mistakes-none')).toBeTruthy();
  });

  test('each question shows why it is here, including repeats', async () => {
    api.on('GET', '/mistakes', { status: 200, body: { count: 1, data: [row()] } });
    await renderApp(<MistakesScreen />);
    expect(await screen.findByTestId('mistake-9')).toBeTruthy();
    expect(screen.getByText('2024 Paper I, question 3')).toBeTruthy();
    expect(screen.getByText('• Latest score below 80%')).toBeTruthy();
    expect(screen.getByText('⚠ Repeated mistake: weak in 2 of 3 attempts')).toBeTruthy();
    expect(screen.getByText('Not reviewed yet')).toBeTruthy();
    expect(screen.getByText('Topics: Limits')).toBeTruthy();
  });

  test('a review is sent with the ticked boxes; the list is reloaded afterwards', async () => {
    api.on('GET', '/mistakes', { status: 200, body: { count: 1, data: [row()] } });
    api.on('POST', '/mistakes', { status: 201, body: { review: { id: 1, question_id: 9, reviewed_on: TODAY } } });
    await renderApp(<MistakesScreen />);
    await press('review-open-9');
    await fireEvent(await screen.findByTestId('review-theory'), 'valueChange', true);
    await fireEvent(screen.getByTestId('review-solution'), 'valueChange', true);
    await type('review-notes', ' did it ');
    await press('review-save');
    expect(await screen.findByTestId('review-saved')).toBeTruthy();
    expect(api.calls.find((c) => c.method === 'POST')!.body).toEqual({
      question_id: 9, reviewed_on: TODAY, theory_reviewed: true, solution_understood: true, reattempted: false, mastered_independently: false, notes: 'did it',
    });
    await waitFor(() => expect(api.calls.filter((c) => c.method === 'GET' && c.path === '/mistakes').length).toBeGreaterThan(1));
    expect(screen.queryByTestId('review-save')).toBeNull();
  });

  test('"I can now do it on my own" without proof is refused with the server\'s own words', async () => {
    api.on('GET', '/mistakes', { status: 200, body: { count: 1, data: [row()] } });
    api.on('POST', '/mistakes', { status: 422, body: { message: 'x', errors: { mastered_independently: ['Your latest attempt was not an independent attempt of at least 80%.'] } } });
    await renderApp(<MistakesScreen />);
    await press('review-open-9');
    await fireEvent(await screen.findByTestId('review-mastered'), 'valueChange', true);
    await press('review-save');
    expect(await screen.findByText(/not an independent attempt of at least 80%/)).toBeTruthy();
    expect(screen.queryByTestId('review-saved')).toBeNull();
  });
});
