import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import type { DailyLogInput, DailyLogPayload } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';

/** Study dates, subjects, option lists and the server's "today". Loaded once, refreshed when the app returns to the foreground. */
export function useBootstrap() {
  const { api, status } = useAuth();
  return useQuery({ queryKey: ['bootstrap'], queryFn: () => api.bootstrap(), staleTime: 5 * 60_000, enabled: status === 'signedIn' });
}

export function useDailyLog(date: string) {
  const { api } = useAuth();
  return useQuery({ queryKey: ['dailyLog', date], queryFn: () => api.getDailyLog(date) });
}

export function useSaveDailyLog(date: string) {
  const { api } = useAuth();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: DailyLogInput) => api.saveDailyLog(date, input),
    onSuccess: (payload: DailyLogPayload) => {
      qc.setQueryData(['dailyLog', date], payload);
      // Other days' cumulative totals and the dashboard are now out of date; refresh them when they are next shown.
      void qc.invalidateQueries({ queryKey: ['dailyLog'], predicate: (q) => q.queryKey[1] !== date, refetchType: 'none' });
      void qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });
}
