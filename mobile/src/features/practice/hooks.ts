import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import type { AttemptInput, MistakeReviewInput, PaperInput, QuestionInput, TheoryInput } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';

export function usePapers(subjectId: number | null, year: number | null) {
  const { api } = useAuth();
  return useQuery({
    queryKey: ['papers', subjectId, year],
    queryFn: () => api.papers(subjectId as number, year as number),
    enabled: subjectId !== null && year !== null,
  });
}

export function usePaperQuestions(paperId: number | null) {
  const { api } = useAuth();
  return useQuery({ queryKey: ['questions', paperId], queryFn: () => api.questions(paperId as number), enabled: paperId !== null });
}

export function useTopics(subjectId: number | null) {
  const { api } = useAuth();
  return useQuery({ queryKey: ['topics', subjectId], queryFn: () => api.topics(subjectId as number), enabled: subjectId !== null, staleTime: 5 * 60_000 });
}

/** Registering a paper the server did not know yet. A repeat is harmless: the server answers "already existed". */
export function useRegisterPaper() {
  const { api } = useAuth();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: PaperInput) => api.registerPaper(input),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['papers'] }),
  });
}

export function useRegisterQuestion(paperId: number | null) {
  const { api } = useAuth();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: QuestionInput) => api.registerQuestion(paperId as number, input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['questions', paperId] });
      void qc.invalidateQueries({ queryKey: ['papers'] });
    },
  });
}

/** Every save adds one more attempt (the server never overwrites an earlier one), so this is never retried automatically. */
export function useRecordAttempt() {
  const { api } = useAuth();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: AttemptInput) => api.recordAttempt(input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['questions'] });
      void qc.invalidateQueries({ queryKey: ['mistakes'] });
      void qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });
}

export function useRecordTheory() {
  const { api } = useAuth();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: TheoryInput) => api.recordTheory(input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['theory'] });
      void qc.invalidateQueries({ queryKey: ['dashboard'] });
      void qc.invalidateQueries({ queryKey: ['dailyLog'], refetchType: 'none' });
    },
  });
}

export function useRecentTheory() {
  const { api } = useAuth();
  return useQuery({ queryKey: ['theory', 'recent'], queryFn: () => api.recentTheory() });
}

export function useMistakes() {
  const { api } = useAuth();
  return useQuery({ queryKey: ['mistakes'], queryFn: () => api.mistakes() });
}

export function useReviewMistake() {
  const { api } = useAuth();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: MistakeReviewInput) => api.reviewMistake(input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['mistakes'] });
      void qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });
}
