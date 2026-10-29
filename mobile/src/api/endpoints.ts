import type { ApiClient } from './client';
import type {
  AttemptCorrection,
  AttemptInput,
  AttemptPage,
  AttemptSaved,
  Bootstrap,
  DailyLogInput,
  DailyLogPayload,
  Dashboard,
  LoginResponse,
  MistakeReviewInput,
  MistakeRow,
  Paper,
  PaperInput,
  Question,
  QuestionInput,
  Report,
  ReportQuery,
  Syllabus,
  SyllabusTopic,
  TheoryCorrection,
  TheoryEntry,
  TheoryInput,
  TheoryPage,
  TheorySaved,
  TopicChoice,
  TopicStatusInput,
  User,
} from './types';

/** One function per API call. Screens use these, never raw URLs. */
export function createEndpoints(client: ApiClient) {
  return {
    login: (email: string, password: string, deviceName: string) =>
      client.post<LoginResponse>('/auth/login', { email, password, device_name: deviceName }, { auth: false }),
    logout: () => client.post<{ message: string }>('/auth/logout'),
    me: () => client.get<{ user: User }>('/me'),
    bootstrap: () => client.get<Bootstrap>('/bootstrap'),
    dashboard: () => client.get<Dashboard>('/dashboard'),
    getDailyLog: (date: string) => client.get<DailyLogPayload>(`/daily-logs/${date}`),
    saveDailyLog: (date: string, input: DailyLogInput) => client.put<DailyLogPayload>(`/daily-logs/${date}`, input),

    papers: (subjectId: number, year: number) => client.get<{ data: Paper[] }>('/papers', { subject_id: subjectId, year }),
    registerPaper: (input: PaperInput) => client.post<{ created: boolean; paper: Paper }>('/papers', input),
    questions: (paperId: number) => client.get<{ paper: Paper; data: Question[] }>(`/papers/${paperId}/questions`),
    registerQuestion: (paperId: number, input: QuestionInput) => client.post<{ created: boolean; question: Question }>(`/papers/${paperId}/questions`, input),
    topics: (subjectId: number) => client.get<{ data: TopicChoice[] }>('/topics', { subject_id: subjectId }),
    recordAttempt: (input: AttemptInput) => client.post<AttemptSaved>('/attempts', input),

    recordTheory: (input: TheoryInput) => client.post<TheorySaved>('/theory', input),
    recentTheory: () => client.get<{ data: TheoryEntry[] }>('/theory', { per_page: 8 }),

    mistakes: () => client.get<{ count: number; data: MistakeRow[] }>('/mistakes'),
    syllabus: (subjectId: number) => client.get<Syllabus>('/syllabus', { subject_id: subjectId }),
    setTopicStatus: (topicId: number, input: TopicStatusInput) => client.put<{ topic: SyllabusTopic }>(`/syllabus/topics/${topicId}`, input),
    report: (q: ReportQuery) => client.get<Report>('/reports', { type: q.type, anchor: q.anchor, subject_id: q.subject_id }),
    attempts: (page: number, subjectId?: number) => client.get<AttemptPage>('/attempts', { per_page: 15, page, subject_id: subjectId }),
    correctAttempt: (id: number, input: AttemptCorrection) => client.put<{ attempt: AttemptPage['data'][number] }>(`/attempts/${id}`, input),
    theoryPage: (page: number) => client.get<TheoryPage>('/theory', { per_page: 15, page }),
    correctTheory: (id: number, input: TheoryCorrection) => client.put<TheorySaved>(`/theory/${id}`, input),

    reviewMistake: (input: MistakeReviewInput) => client.post<{ review: { id: number; question_id: number; reviewed_on: string } }>('/mistakes', input),
  };
}

export type Endpoints = ReturnType<typeof createEndpoints>;
