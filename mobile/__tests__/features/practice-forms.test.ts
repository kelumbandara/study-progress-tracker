import { emptyAttempt, emptyTheory, marksOnly, parseMarks, validateAttempt, validatePaper, validateQuestion, validateTheory } from '@/features/practice/forms';

const DATE = '2026-11-10';

describe('marks', () => {
  test('whole and decimal marks are read; anything else is not a mark', () => {
    expect(parseMarks('7')).toBe(7);
    expect(parseMarks(' 7.5 ')).toBe(7.5);
    expect(parseMarks('7,25')).toBe(7.25);
    expect(parseMarks('0')).toBe(0);
    for (const bad of ['', 'x', '-1', '1.234', '1e3', '7.', '.5', '12345']) expect(parseMarks(bad)).toBeNull();
  });

  test('typing keeps only digits and one decimal point', () => {
    expect(marksOnly('a7b')).toBe('7');
    expect(marksOnly('7,5')).toBe('7.5');
    expect(marksOnly('1.2.3')).toBe('1.23');
    expect(marksOnly('-3')).toBe('3');
  });
});

describe('an attempt', () => {
  const filled = () => ({ ...emptyAttempt(DATE), marks: '7.5', result: 'partial' as const, independent: true });

  test('independence is never assumed: the person must say it', () => {
    const f = { ...filled(), independent: null };
    const v = validateAttempt(f, 10);
    expect(v.ok).toBe(false);
    if (!v.ok) expect(v.errors.independent).toMatch(/without help/);
  });

  test('marks, result and independence are all required', () => {
    const v = validateAttempt(emptyAttempt(DATE), 10);
    expect(v.ok).toBe(false);
    if (!v.ok) expect(Object.keys(v.errors).sort()).toEqual(['independent', 'marks', 'result']);
  });

  test('marks above the question maximum are refused with the maximum named', () => {
    const v = validateAttempt({ ...filled(), marks: '10.5' }, 10);
    expect(v.ok).toBe(false);
    if (!v.ok) expect(v.errors.marks).toMatch(/maximum of 10/);
    expect(validateAttempt({ ...filled(), marks: '10' }, 10).ok).toBe(true); // full marks are fine
  });

  test('a valid attempt becomes the API body; an untimed attempt sends no time', () => {
    const v = validateAttempt({ ...filled(), notes: '  slow start  ' }, 10);
    expect(v.ok).toBe(true);
    if (v.ok) {
      expect(v.input).toEqual({
        attempt_date: DATE, marks_earned: 7.5, result: 'partial', independent: true, worked_minutes: null,
        checked_with_scheme: false, mistake_category: null, notes: 'slow start',
      });
    }
  });

  test('time worked is sent in minutes; bad time is explained', () => {
    const ok = validateAttempt({ ...filled(), hours: '1', mins: '25' }, 10);
    expect(ok.ok && ok.input.worked_minutes).toBe(85);
    const bad = validateAttempt({ ...filled(), mins: '75' }, 10);
    expect(bad.ok).toBe(false);
    if (!bad.ok) expect(bad.errors.worked).toMatch(/0 to 59/);
  });
});

describe('registering a paper or question (always typed by the student)', () => {
  test('a paper needs a name; marks and duration are optional but must be sensible', () => {
    expect(validatePaper({ name: '  ', maxMarks: '', duration: '' }).ok).toBe(false);
    const ok = validatePaper({ name: ' Paper I ', maxMarks: '', duration: '' });
    expect(ok.ok && ok.input).toEqual({ paper_name: 'Paper I' });
    const full = validatePaper({ name: 'Paper II', maxMarks: '100', duration: '180' });
    expect(full.ok && full.input).toEqual({ paper_name: 'Paper II', max_marks: 100, duration_minutes: 180 });
    const bad = validatePaper({ name: 'P', maxMarks: '0', duration: '9999' });
    expect(bad.ok).toBe(false);
    if (!bad.ok) expect(Object.keys(bad.errors).sort()).toEqual(['duration', 'maxMarks']);
  });

  test('a question needs a number and marks above zero', () => {
    expect(validateQuestion({ number: '', maxMarks: '5', topicIds: [] }).ok).toBe(false);
    expect(validateQuestion({ number: '3', maxMarks: '', topicIds: [] }).ok).toBe(false);
    expect(validateQuestion({ number: '3', maxMarks: '0', topicIds: [] }).ok).toBe(false);
    const ok = validateQuestion({ number: ' 3(b) ', maxMarks: '7.5', topicIds: [4, 9] });
    expect(ok.ok && ok.input).toEqual({ question_no: '3(b)', max_marks: 7.5, topic_ids: [4, 9] });
    const none = validateQuestion({ number: '1', maxMarks: '4', topicIds: [] });
    expect(none.ok && 'topic_ids' in none.input).toBe(false);
  });
});

describe('theory study', () => {
  test('a topic and at least one minute are required', () => {
    const v = validateTheory(emptyTheory(DATE));
    expect(v.ok).toBe(false);
    if (!v.ok) expect(Object.keys(v.errors).sort()).toEqual(['minutes', 'topic']);
  });

  test('hours and minutes become whole minutes; complete never implies mastered', () => {
    const v = validateTheory({ ...emptyTheory(DATE), topicId: 12, hours: '1', mins: '30', markedComplete: true, confidence: 4, notes: ' ok ' });
    expect(v.ok).toBe(true);
    if (v.ok) {
      expect(v.minutes).toBe(90);
      expect(v.input).toEqual({ study_date: DATE, topic_id: 12, minutes: 90, marked_complete: true, confidence: 4, notes: 'ok' });
    }
  });

  test('limits: minutes 0 to 59 and at most 24 hours', () => {
    expect(validateTheory({ ...emptyTheory(DATE), topicId: 1, mins: '75' }).ok).toBe(false);
    expect(validateTheory({ ...emptyTheory(DATE), topicId: 1, hours: '24', mins: '1' }).ok).toBe(false);
    expect(validateTheory({ ...emptyTheory(DATE), topicId: 1, hours: '24' }).ok).toBe(true);
  });
});

describe('corrections', () => {
  const { attemptToForm, theoryToForm, validateAttempt, validateTheoryEdit } = require('@/features/practice/forms');
  const saved = { date: '2026-11-08', marks_earned: 6.5, result: 'partial', independent: false, worked_minutes: 75, checked_with_scheme: true, mistake_category: 'concept', notes: 'x' };

  test('an attempt form starts from what is saved, and "with help" stays "with help"', () => {
    const f = attemptToForm(saved);
    expect(f).toMatchObject({ date: '2026-11-08', marks: '6.5', result: 'partial', independent: false, hours: '1', mins: '15', checkedWithScheme: true, mistakeCategory: 'concept', notes: 'x' });
    const v = validateAttempt(f, 10);
    expect(v.ok && v.input).toEqual({ attempt_date: '2026-11-08', marks_earned: 6.5, result: 'partial', independent: false, worked_minutes: 75, checked_with_scheme: true, mistake_category: 'concept', notes: 'x' });
  });

  test('an untimed attempt stays untimed and missing marks do not become zero', () => {
    const f = attemptToForm({ ...saved, worked_minutes: null, notes: null, mistake_category: null });
    expect(f.hours).toBe('0');
    const v = validateAttempt(f, 10);
    expect(v.ok && v.input.worked_minutes).toBeNull();
    expect(validateAttempt(attemptToForm({ ...saved, marks_earned: null }), 10).ok).toBe(false);
  });

  test('theory correction needs at least a minute and clears empty notes', () => {
    const f = theoryToForm({ date: '2026-11-09', minutes: 90, confidence: 3, notes: null });
    expect(f).toEqual({ date: '2026-11-09', hours: '1', mins: '30', confidence: 3, notes: '' });
    const ok = validateTheoryEdit(f);
    expect(ok.ok && ok.input).toEqual({ study_date: '2026-11-09', minutes: 90, confidence: 3, notes: null });
    expect(validateTheoryEdit({ ...f, hours: '0', mins: '0' }).ok).toBe(false);
    expect(validateTheoryEdit({ ...f, mins: '75' }).ok).toBe(false);
  });
});
