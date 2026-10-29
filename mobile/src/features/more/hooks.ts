import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import type { AttemptCorrection, ReportQuery, TheoryCorrection, TopicStatusInput } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';

export function useSyllabus(subjectId: number | null) {
  const { api } = useAuth();
  return useQuery({ queryKey: ['syllabus', subjectId], queryFn: () => api.syllabus(subjectId as number), enabled: subjectId !== null });
}

/** Saving a status never retries on its own; the server may refuse Mastered with a plain explanation. */
export function useSetTopicStatus() {
  const { api } = useAuth();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: TopicStatusInput }) => api.setTopicStatus(id, input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['syllabus'] });
      void qc.invalidateQueries({ queryKey: ['topics'] });
      void qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });
}

export function useReport(q: ReportQuery) {
  const { api } = useAuth();
  return useQuery({ queryKey: ['report', q.type, q.anchor, q.subject_id ?? null], queryFn: () => api.report(q), enabled: q.anchor !== '' });
}

export function useAttemptHistory(subjectId: number | null) {
  const { api } = useAuth();
  return useInfiniteQuery({
    queryKey: ['attempts', subjectId],
    queryFn: ({ pageParam }) => api.attempts(pageParam, subjectId ?? undefined),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.page < last.meta.last_page ? last.meta.page + 1 : undefined),
  });
}

export function useTheoryHistory() {
  const { api } = useAuth();
  return useInfiniteQuery({
    queryKey: ['theory', 'history'],
    queryFn: ({ pageParam }) => api.theoryPage(pageParam),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.page < last.meta.last_page ? last.meta.page + 1 : undefined),
  });
}

function useInvalidateAfterCorrection() {
  const qc = useQueryClient();
  return () => {
    for (const key of ['attempts', 'questions', 'mistakes', 'dashboard', 'syllabus', 'report', 'theory', 'topics']) void qc.invalidateQueries({ queryKey: [key] });
    void qc.invalidateQueries({ queryKey: ['dailyLog'], refetchType: 'none' });
  };
}

export function useCorrectAttempt() {
  const { api } = useAuth();
  const done = useInvalidateAfterCorrection();
  return useMutation({ mutationFn: ({ id, input }: { id: number; input: AttemptCorrection }) => api.correctAttempt(id, input), onSuccess: done });
}

export function useCorrectTheory() {
  const { api } = useAuth();
  const done = useInvalidateAfterCorrection();
  return useMutation({ mutationFn: ({ id, input }: { id: number; input: TheoryCorrection }) => api.correctTheory(id, input), onSuccess: done });
}
