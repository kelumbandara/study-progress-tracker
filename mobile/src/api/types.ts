/** Shapes of the API's responses (see docs/API.md). Only what the screens use is listed. */

export type Category = 'school' | 'tuition' | 'theory' | 'papers';
export type Minutes = Record<Category, number>;

export interface User {
  name: string;
  email: string;
}

export interface LoginResponse {
  token: string;
  token_type: 'Bearer';
  expires_at: string;
  user: User;
}

export interface Option {
  key: string;
  label: string;
}

export interface Subject {
  id: number;
  name: string;
  priority: 'high' | 'medium' | 'maintain';
  priority_label: string;
  weak_score_pct: number;
  slow_minutes_per_mark: number | null;
}

export interface Bootstrap {
  server_time: string;
  /** The server's "today" (Asia/Colombo). Always use this, never the phone's clock. */
  today: string;
  timezone: string;
  study_period: { start: string; end: string; exam_date: string | null };
  paper_years: { from: number; to: number };
  subjects: Subject[];
  options: { categories: Option[]; topic_statuses: Option[]; attempt_results: Option[]; mistake_categories: Option[] };
  rules: { mastery_pct: number; min_attempts_for_advice: number; max_minutes_per_day: number };
}

export interface DailyLog {
  date: string;
  /** false = MISSING (nothing saved for the day). That is different from a saved day of zeros. */
  logged: boolean;
  minutes: Minutes | null;
  total_minutes: number | null;
  is_rest_day: boolean;
  rating: number | null;
  notes: string | null;
  is_sample: boolean;
}

export interface DailyLogPayload {
  log: DailyLog;
  warnings: string[];
  cumulative_minutes: Minutes;
  days_so_far: number;
  missing_days: number;
  today: string;
}

export interface DailyLogInput {
  minutes: Minutes;
  is_rest_day: boolean;
  rating: number | null;
  notes: string | null;
}

export type CategoryStatus = 'not_configured' | 'on_track' | 'behind' | 'complete' | 'overrun';

export interface DashboardCategory {
  key: Category;
  label: string;
  configured: boolean;
  status: CategoryStatus;
  planned: number | null;
  actual: number;
  remaining: number | null;
  overrun: number;
  percent: number | null;
  required_weekly: number | null;
  warnings: string[];
}

export interface Recommendation {
  level: 'info' | 'warn' | 'danger' | 'good';
  text: string;
}

export interface Dashboard {
  today: string;
  today_logged: boolean;
  period: {
    start: string;
    end: string;
    total_days: number;
    elapsed_days: number;
    remaining_days: number;
    percent_elapsed: number;
    not_started: boolean;
    ended: boolean;
  };
  categories: DashboardCategory[];
  totals: { configured_count: number; planned: number | null; actual_all: number; remaining: number | null; percent: number | null; expected: number | null };
  consistency: { days_logged: number; days_missing: number; days_in_range: number };
  recommendations: Recommendation[];
}

// ---- Practice: papers, questions, attempts, theory, mistakes (Phase C) ----

export type AttemptResult = 'correct' | 'partial' | 'wrong' | 'not_completed';

export interface Paper {
  id: number;
  subject_id: number;
  year: number;
  name: string;
  label: string;
  max_marks: number | null;
  duration_minutes: number | null;
  questions_count: number | null;
  attempts_count: number | null;
}

export interface Question {
  id: number;
  paper_id: number;
  number: string;
  max_marks: number;
  label: string;
  reference: string | null;
  notes: string | null;
  attempts_count: number | null;
  topic_ids: number[] | null;
}

export interface TopicChoice {
  id: number;
  name: string;
  full_name: string;
  unit: string;
  status: string;
}

export interface PaperInput {
  subject_id: number;
  exam_year: number;
  paper_name: string;
  max_marks?: number;
  duration_minutes?: number;
}

export interface QuestionInput {
  question_no: string;
  max_marks: number;
  topic_ids?: number[];
}

export interface AttemptInput {
  question_id: number;
  attempt_date: string;
  marks_earned: number;
  result: AttemptResult;
  independent: boolean;
  worked_minutes?: number | null;
  checked_with_scheme?: boolean;
  mistake_category?: string | null;
  notes?: string | null;
}

export interface Attempt {
  id: number;
  question_id: number;
  attempt_no: number;
  date: string;
  marks_earned: number | null;
  max_marks: number | null;
  percent: number | null;
  worked_minutes: number | null;
  result: AttemptResult;
  result_label: string;
  independent: boolean;
  checked_with_scheme: boolean;
  mistake_category: string | null;
  notes: string | null;
}

export interface AttemptSaved {
  attempt: Attempt;
  /** null until the question has a second attempt. */
  improvement: { score_points: number; minutes_change: number | null; best_percent: number; first_percent: number; latest_percent: number } | null;
  topics: { id: number; name: string; status: string }[];
  warnings: string[];
}

export interface TheoryInput {
  study_date: string;
  topic_id: number;
  minutes: number;
  marked_complete: boolean;
  confidence?: number | null;
  notes?: string | null;
}

export interface TheoryEntry {
  id: number;
  topic_id: number;
  date: string;
  minutes: number;
  marked_complete: boolean;
  confidence: number | null;
  notes: string | null;
  topic: { id: number; name: string; status: string; subject: string | null } | null;
}

export interface TheorySaved {
  entry: TheoryEntry;
  warnings: string[];
}

export interface MistakeRow {
  question: { id: number; number: string; max_marks: number; paper_label: string; subject: string; topics: { id: number; name: string }[] };
  latest_attempt: Attempt;
  reasons: string[];
  attempts: number;
  weak_attempts: number;
  repeated: boolean;
  last_review: string | null;
}

export interface MistakeReviewInput {
  question_id: number;
  reviewed_on: string;
  theory_reviewed: boolean;
  solution_understood: boolean;
  reattempted: boolean;
  mastered_independently: boolean;
  notes?: string | null;
}

// ---- Phase D: syllabus, reports, history ----

export interface SyllabusStats {
  total: number;
  started: number;
  theory_completed: number;
  practised: number;
  mastered: number;
  revision: number;
  not_practised: number;
  theory_pct: number | null;
  mastered_pct: number | null;
  remaining: number;
}

export interface SyllabusTopic {
  id: number;
  name: string;
  reference: string | null;
  status: string;
  status_label: string;
  confidence: number | null;
  theory_completed_on: string | null;
  theory_minutes: number;
  practised: boolean;
  is_sample: boolean;
  subtopics?: SyllabusTopic[];
}

export interface SyllabusUnit {
  id: number;
  name: string;
  reference: string | null;
  is_sample: boolean;
  stats: SyllabusStats;
  topics: SyllabusTopic[];
}

export interface Syllabus {
  subject: Subject;
  stats: SyllabusStats;
  units: SyllabusUnit[];
}

export interface TopicStatusInput {
  status: string;
  confidence?: number | null;
  note?: string | null;
}

export type ReportType = 'weekly' | 'monthly';

export interface ReportHoursRow {
  label: string;
  planned_range: number | null;
  actual_range: number;
  cumulative: number;
  planned_total: number | null;
}

export interface Report {
  type: ReportType;
  from: string;
  to: string;
  subject: string;
  hours: { rows: Record<string, ReportHoursRow>; logged_days: number };
  consistency: {
    days_in_range: number;
    days_logged: number;
    days_missing: number;
    days_with_independent: number;
    rest_days: number;
    avg_daily_minutes: number | null;
    avg_daily_independent: number | null;
    avg_weekly_minutes: number | null;
    avg_weekly_independent: number | null;
  };
  questions: {
    attempts: number;
    distinct: number;
    earned: number;
    available: number;
    weighted_pct: number | null;
    first: { n: number; pct: number | null };
    retry: { n: number; pct: number | null };
  };
  topics_completed: string[];
  timed_papers: { label: string; date: string; pct: number | null; attempt_no: number }[];
  strong: { name: string; n: number; pct: number }[];
  weak: { name: string; n: number; pct: number }[];
  reviews: { reviewed: number; theory: number; understood: number; reattempted: number; mastered: number };
  outstanding: number;
  recommendations: Recommendation[];
  monthly?: {
    coverage: { subject: string; theory_pct: number | null; mastered_pct: number | null; total: number }[];
    trend: { subject: string; weeks: Record<string, { pct: number | null; n: number }> | never[] }[];
    papers: {
      by_paper: { label: string; attempts: number; first_pct: number | null; latest_pct: number | null; best_pct: number | null; first_minutes: number | null; official: number | null }[];
    };
    time_change: { questions: number; avg_change: number } | null;
    missed: { label: string; behind_by: number }[];
    forecast: { label: string; remaining: number | null; required_weekly: number | null; weekly_plan: number; status: string }[];
    remaining_days: number;
  };
}

export interface ReportQuery {
  type: ReportType;
  anchor: string;
  subject_id?: number;
}

export interface AttemptPage {
  data: (Attempt & { question?: { id: number; number: string; paper_label: string; subject: string | null } })[];
  meta: { page: number; last_page: number; total: number };
}

export interface TheoryPage {
  data: TheoryEntry[];
  meta: { page: number; last_page: number; total: number };
}

export interface AttemptCorrection {
  attempt_date: string;
  marks_earned: number;
  result: AttemptResult;
  independent: boolean;
  worked_minutes?: number | null;
  checked_with_scheme?: boolean;
  mistake_category?: string | null;
  notes?: string | null;
}

export interface TheoryCorrection {
  study_date: string;
  minutes: number;
  confidence?: number | null;
  notes?: string | null;
}
