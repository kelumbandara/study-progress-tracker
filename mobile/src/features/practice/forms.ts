import type { AttemptInput, AttemptResult, PaperInput, QuestionInput, TheoryCorrection, TheoryInput } from '@/api/types';
import { joinMinutes, MAX_DAY_MINUTES, parseWholeNumber } from '@/lib/time';

/** Marks such as 7 or 7.5 (two decimals at most). Returns null when the text is not a mark. */
export function parseMarks(text: string): number | null {
  const t = text.trim().replace(',', '.');
  return /^\d{1,4}(\.\d{1,2})?$/.test(t) ? Number(t) : null;
}

/** Keeps only what can be part of a mark while typing. */
export function marksOnly(text: string): string {
  return text.replace(',', '.').replace(/[^\d.]/g, '').replace(/(\..*)\./g, '$1').slice(0, 7);
}

// ---- one attempt at a question ----

export interface AttemptForm {
  date: string;
  marks: string;
  result: AttemptResult | null;
  /** null until the student answers: this decides whether the attempt can count towards "Mastered", so it is never assumed. */
  independent: boolean | null;
  hours: string;
  mins: string;
  checkedWithScheme: boolean;
  mistakeCategory: string | null;
  notes: string;
}

export function emptyAttempt(date: string): AttemptForm {
  return { date, marks: '', result: null, independent: null, hours: '0', mins: '0', checkedWithScheme: false, mistakeCategory: null, notes: '' };
}

export interface AttemptErrors {
  marks?: string;
  result?: string;
  independent?: string;
  worked?: string;
}

export type AttemptValidation = { ok: true; input: Omit<AttemptInput, 'question_id'> } | { ok: false; errors: AttemptErrors };

/** The same limits as the server, checked first so a mistake is caught before a network trip. */
export function validateAttempt(form: AttemptForm, maxMarks: number): AttemptValidation {
  const errors: AttemptErrors = {};
  const marks = parseMarks(form.marks);
  if (form.marks.trim() === '') errors.marks = 'Enter the marks you scored (0 if none).';
  else if (marks === null) errors.marks = 'Marks must be a number such as 7 or 7.5.';
  else if (marks > maxMarks) errors.marks = `Marks cannot be more than the question's maximum of ${maxMarks}.`;

  if (form.result === null) errors.result = 'Choose how it went.';
  if (form.independent === null) errors.independent = 'Say whether you did it without help.';

  const h = parseWholeNumber(form.hours);
  const m = parseWholeNumber(form.mins);
  let worked: number | null = null;
  if (h === null || m === null) errors.worked = 'Time worked: use whole numbers only.';
  else if (m > 59) errors.worked = 'Time worked: minutes must be 0 to 59.';
  else if (joinMinutes(h, m) > MAX_DAY_MINUTES) errors.worked = 'Time worked cannot be more than 24 hours.';
  else worked = joinMinutes(h, m);

  if (Object.keys(errors).length > 0 || marks === null || form.result === null || form.independent === null) return { ok: false, errors };

  return {
    ok: true,
    input: {
      attempt_date: form.date,
      marks_earned: marks,
      result: form.result,
      independent: form.independent,
      // 0 h 0 min means "not timed"; a real time of zero is not a thing worth recording.
      worked_minutes: worked && worked > 0 ? worked : null,
      checked_with_scheme: form.checkedWithScheme,
      mistake_category: form.mistakeCategory,
      notes: form.notes.trim() === '' ? null : form.notes.trim(),
    },
  };
}

// ---- registering a missing paper or question (typed in by the student, never pre-filled) ----

export interface PaperForm {
  name: string;
  maxMarks: string;
  duration: string;
}

export type PaperValidation = { ok: true; input: Omit<PaperInput, 'subject_id' | 'exam_year'> } | { ok: false; errors: { name?: string; maxMarks?: string; duration?: string } };

export function validatePaper(form: PaperForm): PaperValidation {
  const errors: { name?: string; maxMarks?: string; duration?: string } = {};
  const name = form.name.trim();
  if (name === '') errors.name = 'Give the paper a name, for example "Paper I".';
  else if (name.length > 32) errors.name = 'The name can be at most 32 characters.';
  const optionalWhole = (text: string, max: number): number | undefined | null => {
    if (text.trim() === '') return undefined;
    const n = /^\d{1,4}$/.test(text.trim()) ? Number(text.trim()) : null;
    return n === null || n < 1 || n > max ? null : n;
  };
  const maxMarks = optionalWhole(form.maxMarks, 1000);
  if (maxMarks === null) errors.maxMarks = 'Maximum marks must be a whole number from 1 to 1000, or left empty.';
  const duration = optionalWhole(form.duration, 600);
  if (duration === null) errors.duration = 'Duration must be whole minutes from 1 to 600, or left empty.';
  if (Object.keys(errors).length > 0) return { ok: false, errors };
  return {
    ok: true,
    input: { paper_name: name, ...(maxMarks !== undefined && maxMarks !== null ? { max_marks: maxMarks } : {}), ...(duration !== undefined && duration !== null ? { duration_minutes: duration } : {}) },
  };
}

export interface QuestionForm {
  number: string;
  maxMarks: string;
  topicIds: number[];
}

export type QuestionValidation = { ok: true; input: QuestionInput } | { ok: false; errors: { number?: string; maxMarks?: string } };

export function validateQuestion(form: QuestionForm): QuestionValidation {
  const errors: { number?: string; maxMarks?: string } = {};
  const number = form.number.trim();
  if (number === '') errors.number = 'Enter the question number, for example 3 or 3(b).';
  else if (number.length > 16) errors.number = 'The question number can be at most 16 characters.';
  const marks = parseMarks(form.maxMarks);
  if (form.maxMarks.trim() === '') errors.maxMarks = 'Enter the marks this question is worth.';
  else if (marks === null || marks <= 0 || marks > 1000) errors.maxMarks = 'Marks must be a number above 0, up to 1000.';
  if (Object.keys(errors).length > 0 || marks === null) return { ok: false, errors };
  return { ok: true, input: { question_no: number, max_marks: marks, ...(form.topicIds.length > 0 ? { topic_ids: form.topicIds } : {}) } };
}

// ---- theory study ----

export interface TheoryForm {
  date: string;
  topicId: number | null;
  hours: string;
  mins: string;
  markedComplete: boolean;
  confidence: number | null;
  notes: string;
}

export function emptyTheory(date: string): TheoryForm {
  return { date, topicId: null, hours: '0', mins: '0', markedComplete: false, confidence: null, notes: '' };
}

export interface TheoryErrors {
  topic?: string;
  minutes?: string;
}

export type TheoryValidation = { ok: true; input: TheoryInput; minutes: number } | { ok: false; errors: TheoryErrors };

export function validateTheory(form: TheoryForm): TheoryValidation {
  const errors: TheoryErrors = {};
  if (form.topicId === null) errors.topic = 'Choose the topic you studied.';
  const h = parseWholeNumber(form.hours);
  const m = parseWholeNumber(form.mins);
  let minutes = 0;
  if (h === null || m === null) errors.minutes = 'Use whole numbers only.';
  else if (m > 59) errors.minutes = 'Minutes must be 0 to 59.';
  else {
    minutes = joinMinutes(h, m);
    if (minutes < 1) errors.minutes = 'Enter how long you studied (at least 1 minute).';
    else if (minutes > MAX_DAY_MINUTES) errors.minutes = 'That is more than 24 hours.';
  }
  if (Object.keys(errors).length > 0 || form.topicId === null) return { ok: false, errors };
  return {
    ok: true,
    minutes,
    input: {
      study_date: form.date,
      topic_id: form.topicId,
      minutes,
      marked_complete: form.markedComplete,
      confidence: form.confidence,
      notes: form.notes.trim() === '' ? null : form.notes.trim(),
    },
  };
}

// ---- correcting something already saved ----

/** Fills the attempt form from a saved attempt, so a correction starts from what is on the server. */
export function attemptToForm(a: { date: string; marks_earned: number | null; result: AttemptResult; independent: boolean; worked_minutes: number | null; checked_with_scheme: boolean; mistake_category: string | null; notes: string | null }): AttemptForm {
  const h = a.worked_minutes ? Math.floor(a.worked_minutes / 60) : 0;
  const m = a.worked_minutes ? a.worked_minutes % 60 : 0;
  return {
    date: a.date,
    marks: a.marks_earned === null ? '' : String(a.marks_earned),
    result: a.result,
    independent: a.independent,
    hours: String(h),
    mins: String(m),
    checkedWithScheme: a.checked_with_scheme,
    mistakeCategory: a.mistake_category,
    notes: a.notes ?? '',
  };
}

export interface TheoryEditForm {
  date: string;
  hours: string;
  mins: string;
  confidence: number | null;
  notes: string;
}

export function theoryToForm(e: { date: string; minutes: number; confidence: number | null; notes: string | null }): TheoryEditForm {
  return { date: e.date, hours: String(Math.floor(e.minutes / 60)), mins: String(e.minutes % 60), confidence: e.confidence, notes: e.notes ?? '' };
}

export type TheoryEditValidation = { ok: true; input: TheoryCorrection } | { ok: false; errors: { minutes?: string } };

export function validateTheoryEdit(form: TheoryEditForm): TheoryEditValidation {
  const r = validateTheory({ date: form.date, topicId: 0, hours: form.hours, mins: form.mins, markedComplete: false, confidence: form.confidence, notes: form.notes });
  if (!r.ok) return { ok: false, errors: r.errors.minutes ? { minutes: r.errors.minutes } : {} };
  return { ok: true, input: { study_date: form.date, minutes: r.minutes, confidence: form.confidence, notes: form.notes.trim() === '' ? null : form.notes.trim() } };
}
