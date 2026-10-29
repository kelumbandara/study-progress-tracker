import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { ActivityIndicator, RefreshControl, StyleSheet, View } from 'react-native';

import { errorMessage } from '@/api/errors';
import type { CategoryStatus, DashboardCategory, Recommendation } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { formatLongDate } from '@/lib/dates';
import { formatHours } from '@/lib/time';
import { AppText } from '@/ui/AppText';
import { Banner } from '@/ui/Banner';
import { Button } from '@/ui/Button';
import { Card } from '@/ui/Card';
import { Screen } from '@/ui/Screen';
import { radius, usePalette } from '@/ui/theme';

const STATUS_LABEL: Record<CategoryStatus, string> = {
  not_configured: 'Not configured',
  on_track: 'On track',
  behind: 'Behind plan',
  complete: 'Complete',
  overrun: 'Over plan',
};

const TONE: Record<Recommendation['level'], 'info' | 'warning' | 'error' | 'success'> = { info: 'info', warn: 'warning', danger: 'error', good: 'success' };

function ProgressBar({ percent, label }: { percent: number; label: string }) {
  const p = usePalette();
  return (
    <View accessibilityRole="progressbar" accessibilityLabel={label} accessibilityValue={{ min: 0, max: 100, now: Math.round(Math.min(100, percent)) }} style={[styles.track, { backgroundColor: p.border }]}>
      <View style={[styles.fill, { width: `${Math.max(0, Math.min(100, percent))}%`, backgroundColor: p.primary }]} />
    </View>
  );
}

function CategoryRow({ c }: { c: DashboardCategory }) {
  return (
    <View style={styles.cat} testID={`cat-${c.key}`}>
      <View style={styles.catHead}>
        <AppText variant="label">{c.label}</AppText>
        <AppText variant="small" tone={c.status === 'behind' ? 'warning' : c.status === 'overrun' ? 'warning' : 'muted'}>
          {STATUS_LABEL[c.status]}
        </AppText>
      </View>
      {c.configured && c.planned !== null ? (
        <>
          <AppText variant="small">
            {formatHours(c.actual)} of {formatHours(c.planned)}
            {c.percent !== null ? ` (${c.percent}%)` : ''}
          </AppText>
          <ProgressBar percent={c.percent ?? 0} label={`${c.label} progress`} />
          {c.required_weekly !== null ? (
            <AppText variant="small" tone="muted">
              About {formatHours(Math.round(c.required_weekly))} per week needed from now.
            </AppText>
          ) : null}
        </>
      ) : (
        <AppText variant="small" tone="muted">
          {formatHours(c.actual)} recorded. Planned hours are not set yet.
        </AppText>
      )}
      {c.warnings.map((w) => (
        <AppText key={w} variant="small" tone="warning">
          {'⚠ '}
          {w}
        </AppText>
      ))}
    </View>
  );
}

export function DashboardScreen() {
  const { api } = useAuth();
  const q = useQuery({ queryKey: ['dashboard'], queryFn: () => api.dashboard() });
  const [refreshing, setRefreshing] = useState(false);

  const onRefresh = async () => {
    setRefreshing(true);
    try {
      await q.refetch();
    } finally {
      setRefreshing(false);
    }
  };

  if (q.isPending) {
    return (
      <Screen>
        <ActivityIndicator accessibilityLabel="Loading" style={{ marginTop: 48 }} />
      </Screen>
    );
  }
  if (q.isError) {
    return (
      <Screen>
        <Banner tone="error" title="Cannot load the dashboard">
          {errorMessage(q.error)}
        </Banner>
        <Button title="Try again" onPress={() => void q.refetch()} />
      </Screen>
    );
  }

  const d = q.data;
  return (
    <Screen refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void onRefresh()} />}>
      {!d.today_logged ? (
        <Banner tone="warning" title="Today is not logged yet" testID="today-missing">
          Open the Today tab and enter the day's time.
        </Banner>
      ) : null}

      <Card title="Study period">
        <AppText variant="small" tone="muted">
          {formatLongDate(d.period.start)} to {formatLongDate(d.period.end)}
        </AppText>
        <AppText variant="body" style={styles.period} testID="period-text">
          {d.period.ended ? 'The study period has ended.' : d.period.not_started ? 'The study period has not started yet.' : `${d.period.remaining_days} days left`} · {d.period.percent_elapsed}% of the period has passed
        </AppText>
        <ProgressBar percent={d.period.percent_elapsed} label="Period elapsed" />
        <AppText variant="small" tone="muted" style={styles.period}>
          {d.consistency.days_logged} days logged · {d.consistency.days_missing} missing (not counted as zero)
        </AppText>
      </Card>

      <Card title="Hours: planned and actual">
        {d.categories.map((c) => (
          <CategoryRow key={c.key} c={c} />
        ))}
      </Card>

      <Card title="What to do next">
        {d.recommendations.length === 0 ? (
          <AppText variant="small" tone="muted">
            Nothing to flag right now.
          </AppText>
        ) : (
          d.recommendations.map((r) => (
            <Banner key={r.text} tone={TONE[r.level]} title={r.level === 'good' ? 'Good' : r.level === 'info' ? 'Note' : 'Attention'}>
              {r.text}
            </Banner>
          ))
        )}
        <AppText variant="small" tone="muted">
          Based only on what has been recorded. This is guidance, not a prediction of any result.
        </AppText>
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  period: { marginVertical: 8 },
  cat: { marginBottom: 16 },
  catHead: { flexDirection: 'row', justifyContent: 'space-between', marginBottom: 2 },
  track: { height: 10, borderRadius: radius.sm, overflow: 'hidden', marginVertical: 6 },
  fill: { height: 10 },
});
