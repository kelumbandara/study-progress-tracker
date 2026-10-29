import { fireEvent, screen, waitFor, within } from '@testing-library/react-native';

import { DailyLogScreen } from '@/features/dailyLog/DailyLogScreen';

import { type FakeApi, fakeApi, renderApp, seedSession } from '../../test-utils/helpers';

const TODAY = '2026-11-10';
const cumulative = { school: 330, tuition: 120, theory: 75, papers: 120 };
const bootstrap = {
  server_time: '2026-11-10T20:00:00+05:30', today: TODAY, timezone: 'Asia/Colombo',
  study_period: { start: '2026-10-28', end: '2027-07-31', exam_date: null }, paper_years: { from: 2017, to: 2026 }, subjects: [],
  options: { categories: [], topic_statuses: [], attempt_results: [], mistake_categories: [] },
  rules: { mastery_pct: 80, min_attempts_for_advice: 5, max_minutes_per_day: 1440 },
};

const missingLog = (date: string) => ({ date, logged: false, minutes: null, total_minutes: null, is_rest_day: false, rating: null, notes: null, is_sample: false });
const savedLog = (date: string, minutes = cumulative, extra: object = {}) => ({
  date, logged: true, minutes, total_minutes: Object.values(minutes).reduce((a, b) => a + b, 0), is_rest_day: false, rating: null, notes: null, is_sample: false, ...extra,
});
const payload = (log: object, extra: object = {}) => ({ log, warnings: [], cumulative_minutes: cumulative, days_so_far: 14, missing_days: 13, today: TODAY, ...extra });

let api: FakeApi;
beforeEach(async () => {
  api = fakeApi();
  await seedSession();
  api.on('GET', '/bootstrap', { status: 200, body: bootstrap });
  api.on('GET', /^\/daily-logs\/\d{4}-\d{2}-\d{2}$/, (url) => ({ status: 200, body: payload(missingLog(url.pathname.split('/').pop()!)) }));
});
afterEach(() => api.restore());

const type = async (id: string, text: string) => fireEvent.changeText(screen.getByTestId(id), text);

describe('<DailyLogScreen />', () => {
  test('opens on the SERVER\'s today and shows a missing day as missing, not as zero', async () => {
    await renderApp(<DailyLogScreen />);
    expect(await screen.findByTestId('status-missing')).toBeTruthy();
    expect(screen.getByTestId('day-label').props.children).toBe('Tue 10 Nov 2026');
    expect(api.calls.find((c) => c.path.startsWith('/daily-logs/'))?.path).toBe(`/daily-logs/${TODAY}`);
    expect(screen.getByText(/counts as missing, not as zero/)).toBeTruthy();
    expect(screen.getByTestId('day-total').props.children).toBe('0 h 00 min');
    expect(screen.getByTestId('missing-days').props.children.join('')).toBe('Days with no log so far: 13 of 14. A missing day is not counted as zero.');
  });

  test('the day total follows what is typed', async () => {
    await renderApp(<DailyLogScreen />);
    await screen.findByTestId('status-missing');
    await type('dur-school-hours', '5');
    await type('dur-school-mins', '30');
    await type('dur-theory-hours', '1');
    await type('dur-theory-mins', '15');
    expect(screen.getByTestId('day-total').props.children).toBe('6 h 45 min');
  });

  test('only digits can be typed in the boxes', async () => {
    await renderApp(<DailyLogScreen />);
    await screen.findByTestId('status-missing');
    await type('dur-school-hours', 'a1b');
    expect(screen.getByTestId('dur-school-hours').props.value).toBe('1');
    await type('dur-school-mins', '-5.9');
    expect(screen.getByTestId('dur-school-mins').props.value).toBe('59');
    await type('dur-school-hours', '123');
    expect(screen.getByTestId('dur-school-hours').props.value).toBe('12'); // two digits at most
  });

  test('more than 24 hours or more than 59 minutes is explained and cannot be saved', async () => {
    await renderApp(<DailyLogScreen />);
    await screen.findByTestId('status-missing');
    await type('dur-school-hours', '24');
    await type('dur-tuition-hours', '1');
    expect(await screen.findByTestId('total-error')).toBeTruthy();
    expect(screen.getByText(/more than 24 hours/)).toBeTruthy();
    expect(screen.getByTestId('save-day').props.accessibilityState.disabled).toBe(true);

    await type('dur-tuition-hours', '0');
    await type('dur-school-hours', '1');
    await type('dur-theory-mins', '75');
    expect(screen.getByText(/Study Theory: minutes must be 0 to 59/)).toBeTruthy();
    expect(screen.getByTestId('save-day').props.accessibilityState.disabled).toBe(true);
    expect(api.calls.filter((c) => c.method === 'PUT')).toHaveLength(0);
  });

  test('saving sends all four durations in minutes and then shows the day as saved', async () => {
    api.on('PUT', `/daily-logs/${TODAY}`, (_u, _i, body) => ({ status: 200, body: payload(savedLog(TODAY, body.minutes, { rating: body.rating, notes: body.notes, is_rest_day: body.is_rest_day }), { missing_days: 12 }) }));
    await renderApp(<DailyLogScreen />);
    await screen.findByTestId('status-missing');
    await type('dur-school-hours', '5');
    await type('dur-school-mins', '30');
    await type('dur-tuition-hours', '2');
    await type('dur-theory-hours', '1');
    await type('dur-theory-mins', '15');
    await type('dur-papers-hours', '2');
    await fireEvent(screen.getByTestId('rest-day'), 'valueChange', true);
    await fireEvent.press(screen.getByTestId('rating-4'));
    await type('notes', '  good day  ');
    await fireEvent.press(screen.getByTestId('save-day'));

    expect(await screen.findByTestId('status-saved')).toBeTruthy();
    const put = api.calls.find((c) => c.method === 'PUT')!;
    expect(put.path).toBe(`/daily-logs/${TODAY}`);
    expect(put.body).toEqual({ minutes: { school: 330, tuition: 120, theory: 75, papers: 120 }, is_rest_day: true, rating: 4, notes: 'good day' });
    expect(put.headers.Authorization).toBe('Bearer stored-token');
    // Saved and unchanged: the button now says "Save changes" and is off until something changes.
    expect(screen.getByText('Save changes')).toBeTruthy();
    expect(screen.getByTestId('save-day').props.accessibilityState.disabled).toBe(true);
    // The saved totals come from the server's reply.
    expect(screen.getByTestId('missing-days').props.children.join('')).toMatch(/: 12 of 14/);
  });

  test('a day with zeros can be saved on purpose (a confirmed zero day)', async () => {
    api.on('PUT', `/daily-logs/${TODAY}`, (_u, _i, body) => ({ status: 200, body: payload(savedLog(TODAY, body.minutes)) }));
    await renderApp(<DailyLogScreen />);
    await screen.findByTestId('status-missing');
    expect(screen.getByTestId('save-day').props.accessibilityState.disabled).toBe(false); // nothing typed, still allowed
    await fireEvent.press(screen.getByTestId('save-day'));
    expect(await screen.findByTestId('status-saved')).toBeTruthy();
    expect(api.calls.find((c) => c.method === 'PUT')!.body.minutes).toEqual({ school: 0, tuition: 0, theory: 0, papers: 0 });
  });

  test('a saved day opens filled in, and Save changes is only available after an edit', async () => {
    api.on('GET', `/daily-logs/${TODAY}`, { status: 200, body: payload(savedLog(TODAY, cumulative, { rating: 3, notes: 'hello' })) });
    await renderApp(<DailyLogScreen />);
    expect(await screen.findByTestId('status-logged')).toBeTruthy();
    expect(screen.getByTestId('dur-school-hours').props.value).toBe('5');
    expect(screen.getByTestId('dur-school-mins').props.value).toBe('30');
    expect(screen.getByTestId('dur-papers-hours').props.value).toBe('2');
    expect(screen.getByTestId('notes').props.value).toBe('hello');
    expect(screen.getByTestId('day-total').props.children).toBe('10 h 45 min');
    expect(screen.getByTestId('save-day').props.accessibilityState.disabled).toBe(true);

    await type('dur-theory-hours', '2');
    expect(screen.getByTestId('save-day').props.accessibilityState.disabled).toBe(false);
    await type('dur-theory-hours', '1'); // back to what is saved
    expect(screen.getByTestId('save-day').props.accessibilityState.disabled).toBe(true);
  });

  test('the server\'s refusal is shown, and what was typed is kept', async () => {
    api.on('PUT', `/daily-logs/${TODAY}`, { status: 422, body: { message: 'x', errors: { 'minutes.theory': ['The theory time is not valid.'] } } });
    await renderApp(<DailyLogScreen />);
    await screen.findByTestId('status-missing');
    await type('dur-theory-hours', '3');
    await fireEvent.press(screen.getByTestId('save-day'));
    expect(await screen.findByText(/The theory time is not valid\./)).toBeTruthy();
    expect(screen.getByTestId('dur-theory-hours').props.value).toBe('3');
  });

  test('no connection: a clear message, nothing lost, and trying again works', async () => {
    let online = false;
    api.on('PUT', `/daily-logs/${TODAY}`, (_u, _i, body) => (online ? { status: 200, body: payload(savedLog(TODAY, body.minutes)) } : { status: 0, networkError: true }));
    await renderApp(<DailyLogScreen />);
    await screen.findByTestId('status-missing');
    await type('dur-papers-hours', '2');
    await fireEvent.press(screen.getByTestId('save-day'));

    expect(await screen.findByTestId('save-error')).toBeTruthy();
    expect(screen.getByText(/Not saved: no connection/)).toBeTruthy();
    expect(screen.getByText(/Cannot reach the server/)).toBeTruthy();
    expect(screen.getByTestId('dur-papers-hours').props.value).toBe('2');
    expect(screen.queryByTestId('status-saved')).toBeNull();

    online = true;
    await fireEvent.press(screen.getByTestId('save-day'));
    expect(await screen.findByTestId('status-saved')).toBeTruthy();
    expect(screen.queryByTestId('save-error')).toBeNull();
  });

  test('allocation warnings from the server are shown', async () => {
    api.on('GET', `/daily-logs/${TODAY}`, { status: 200, body: payload(savedLog(TODAY), { warnings: ['Detail minutes exceed the day\'s Study Theory total.'] }) });
    await renderApp(<DailyLogScreen />);
    expect(await screen.findByText(/Detail minutes exceed/)).toBeTruthy();
  });

  test('moving between days loads each day, and the future is out of reach', async () => {
    await renderApp(<DailyLogScreen />);
    await screen.findByTestId('status-missing');
    expect(screen.getByTestId('day-next').props.accessibilityState.disabled).toBe(true);

    await fireEvent.press(screen.getByTestId('day-prev'));
    await waitFor(() => expect(screen.getByTestId('day-label').props.children).toBe('Mon 9 Nov 2026'));
    expect(api.calls.some((c) => c.path === '/daily-logs/2026-11-09')).toBe(true);
    expect(screen.getByTestId('day-next').props.accessibilityState.disabled).toBe(false);
    expect(screen.getByTestId('day-today')).toBeTruthy();

    await fireEvent.press(screen.getByTestId('day-next'));
    await waitFor(() => expect(screen.getByTestId('day-label').props.children).toBe('Tue 10 Nov 2026'));
    expect(screen.queryByTestId('day-today')).toBeNull();

    await fireEvent.press(screen.getByTestId('day-prev'));
    await fireEvent.press(screen.getByTestId('day-prev'));
    await waitFor(() => expect(screen.getByTestId('day-label').props.children).toBe('Sun 8 Nov 2026'));
    await fireEvent.press(screen.getByTestId('day-today'));
    await waitFor(() => expect(screen.getByTestId('day-label').props.children).toBe('Tue 10 Nov 2026'));
  });

  test('tapping into a box that shows 0 empties it (no select-all, which swallows taps on Android); leaving it empty is still zero', async () => {
    await renderApp(<DailyLogScreen />);
    await screen.findByTestId('status-missing');
    const box = screen.getByTestId('dur-school-hours');
    expect(box.props.value).toBe('0');
    expect(box.props.selectTextOnFocus).toBeFalsy();
    expect(box.props.placeholder).toBe('0');
    await fireEvent(box, 'focus');
    expect(screen.getByTestId('dur-school-hours').props.value).toBe('');
    expect(screen.getByTestId('day-total').props.children).toBe('0 h 00 min');
    // an emptied box is not an unsaved change on a saved day
    expect(screen.getByTestId('save-day').props.accessibilityState.disabled).toBe(false); // still a missing day: saving zeros is allowed
  });

  test('a box with a real value is NOT emptied on focus', async () => {
    api.on('GET', `/daily-logs/${TODAY}`, { status: 200, body: payload(savedLog(TODAY)) });
    await renderApp(<DailyLogScreen />);
    await screen.findByTestId('status-logged');
    await fireEvent(screen.getByTestId('dur-school-hours'), 'focus');
    expect(screen.getByTestId('dur-school-hours').props.value).toBe('5');
    // focusing a 0 minutes box on a saved day does not make the day look edited
    await fireEvent(screen.getByTestId('dur-tuition-mins'), 'focus');
    expect(screen.getByTestId('save-day').props.accessibilityState.disabled).toBe(true);
  });

  test('typing on one day does not leak into the next day', async () => {
    await renderApp(<DailyLogScreen />);
    await screen.findByTestId('status-missing');
    await type('dur-school-hours', '7');
    await fireEvent.press(screen.getByTestId('day-prev'));
    await waitFor(() => expect(screen.getByTestId('day-label').props.children).toBe('Mon 9 Nov 2026'));
    await screen.findByTestId('status-missing');
    expect(screen.getByTestId('dur-school-hours').props.value).toBe('0');
  });

  test('a failure to load is explained and can be retried', async () => {
    let up = false;
    api.on('GET', `/daily-logs/${TODAY}`, () => (up ? { status: 200, body: payload(missingLog(TODAY)) } : { status: 503, body: { message: 'x' } }));
    await renderApp(<DailyLogScreen />);
    expect(await screen.findByText(/Cannot load this day/)).toBeTruthy();
    up = true;
    await fireEvent.press(screen.getByText('Try again'));
    expect(await screen.findByTestId('status-missing')).toBeTruthy();
  });

  test('the rating can be chosen and cleared', async () => {
    await renderApp(<DailyLogScreen />);
    await screen.findByTestId('status-missing');
    await fireEvent.press(screen.getByTestId('rating-5'));
    expect(screen.getByTestId('rating-5').props.accessibilityState.selected).toBe(true);
    await fireEvent.press(screen.getByTestId('rating-5'));
    expect(screen.getByTestId('rating-5').props.accessibilityState.selected).toBe(false);
  });

  test('the four categories use the website\'s names', async () => {
    await renderApp(<DailyLogScreen />);
    await screen.findByTestId('status-missing');
    for (const name of ['School', 'Tuition', 'Study Theory', 'Write Exam Papers']) expect(screen.getAllByText(name).length).toBeGreaterThan(0);
    const totals = within(screen.getByText('Your totals so far').parent!.parent!);
    expect(totals.getAllByText('5 h 30 min').length).toBeGreaterThan(0);
  });
});
