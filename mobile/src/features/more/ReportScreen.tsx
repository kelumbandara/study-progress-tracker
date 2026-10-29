import { useState } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';

import { errorMessage } from '@/api/errors';
import type { Recommendation, Report, ReportType } from '@/api/types';
import { useBootstrap } from '@/features/dailyLog/hooks';
import { addDays, addMonths, formatLongDate } from '@/lib/dates';
import { formatDuration } from '@/lib/time';
import { AppText } from '@/ui/AppText';
import { Banner } from '@/ui/Banner';
import { Button } from '@/ui/Button';
import { Card } from '@/ui/Card';
import { Choice } from '@/ui/Choice';
import { Screen } from '@/ui/Screen';

import { useReport } from './hooks';

const TONE: Record<Recommendation['level'], 'info' | 'warning' | 'error' | 'success'> = { info: 'info', warn: 'warning', danger: 'error', good: 'success' };
const pct = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${v}%`);

/** Weekly and monthly report, the same numbers as the website. Excel/CSV export and printing stay on the website. */
export function ReportScreen() {
  const boot = useBootstrap();
  const [type, setType] = useState<ReportType>('weekly');
  const [anchor, setAnchor] = useState<string | null>(null);
  const [subjectId, setSubjectId] = useState<number | null>(null);

  const today = boot.data?.today ?? null;
  const at = anchor ?? today;
  const report = useReport({ type, anchor: at ?? '', ...(subjectId !== null ? { subject_id: subjectId } : {}) });

  if (boot.isPending) {
    return (
      <Screen>
        <ActivityIndicator accessibilityLabel="Loading" style={styles.loading} />
      </Screen>
    );
  }
  if (!boot.data || !today || !at) {
    return (
      <Screen>
        <Banner tone="error" title="Cannot load">
          {errorMessage(boot.error)}
        </Banner>
        <Button title="Try again" onPress={() => void boot.refetch()} />
      </Screen>
    );
  }

  const step = (dir: -1 | 1) => setAnchor(type === 'weekly' ? addDays(at, 7 * dir) : addMonths(at, dir));
  // The next period is only offered while the current one has not reached today.
  const isCurrent = report.data ? report.data.to >= today : false;

  return (
    <Screen>
      <Choice
        label="Report"
        testID="rep-type"
        options={[{ value: 'weekly', label: 'Weekly' }, { value: 'monthly', label: 'Monthly' }]}
        value={type}
        onChange={(v) => {
          if (v) {
            setType(v as ReportType);
            setAnchor(null);
          }
        }}
      />
      <Choice
        label="Subject"
        testID="rep-subject"
        clearable
        options={boot.data.subjects.map((s) => ({ value: s.id, label: s.name }))}
        value={subjectId}
        onChange={setSubjectId}
      />
      <View style={styles.nav}>
        <Button compact variant="secondary" title="‹ Earlier" accessibilityLabel="Earlier period" testID="rep-prev" onPress={() => step(-1)} />
        <AppText style={styles.range} testID="rep-range">
          {report.data ? `${formatLongDate(report.data.from)} to ${formatLongDate(report.data.to)}` : '…'}
        </AppText>
        <Button compact variant="secondary" title="Later ›" accessibilityLabel="Later period" testID="rep-next" disabled={isCurrent} onPress={() => step(1)} />
      </View>

      {report.isPending ? (
        <ActivityIndicator accessibilityLabel="Loading report" style={styles.loading} />
      ) : !report.data ? (
        <>
          <Banner tone="error" title="Cannot load the report">
            {errorMessage(report.error)}
          </Banner>
          <Button title="Try again" onPress={() => void report.refetch()} />
        </>
      ) : (
        <ReportBody r={report.data} />
      )}
    </Screen>
  );
}

function Line({ label, value, testID }: { label: string; value: string; testID?: string }) {
  return (
    <View style={styles.line}>
      <AppText variant="small" tone="muted" style={styles.lineLabel}>
        {label}
      </AppText>
      <AppText testID={testID} style={styles.lineValue}>
        {value}
      </AppText>
    </View>
  );
}

function ReportBody({ r }: { r: Report }) {
  const c = r.consistency;
  const q = r.questions;
  return (
    <View testID="report-body">
      <Card title="Hours">
        {Object.entries(r.hours.rows).map(([key, h]) => (
          <Line
            key={key}
            label={h.label}
            testID={`hours-${key}`}
            value={`${formatDuration(h.actual_range)}${h.planned_range !== null ? ` of ${formatDuration(h.planned_range)} planned` : ' (planned hours not set)'}`}
          />
        ))}
        <AppText variant="small" tone="muted">
          {`${r.hours.logged_days} day${r.hours.logged_days === 1 ? '' : 's'} logged in this period.`}
        </AppText>
      </Card>

      <Card title="Consistency">
        <Line label="Days logged" value={`${c.days_logged} of ${c.days_in_range}`} testID="rep-days" />
        <Line label="Days not logged" value={`${c.days_missing} (not counted as zero)`} />
        <Line label="Days with independent study" value={String(c.days_with_independent)} />
        <Line label="Average per logged day" value={formatDuration(c.avg_daily_minutes)} />
        <Line label="Average independent study per logged day" value={formatDuration(c.avg_daily_independent)} />
      </Card>

      <Card title="Questions">
        {q.attempts === 0 ? (
          <AppText tone="muted" testID="rep-no-questions">
            No question attempts in this period.
          </AppText>
        ) : (
          <>
            <Line label="Attempts" value={`${q.attempts} on ${q.distinct} question${q.distinct === 1 ? '' : 's'}`} testID="rep-attempts" />
            <Line label="Marks" value={`${q.earned} of ${q.available} (${pct(q.weighted_pct)})`} />
            <Line label="First attempts" value={`${pct(q.first.pct)} over ${q.first.n}`} />
            <Line label="Retries" value={`${pct(q.retry.pct)} over ${q.retry.n}`} />
          </>
        )}
      </Card>

      {r.topics_completed.length > 0 ? (
        <Card title="Theory completed">
          {r.topics_completed.map((t) => (
            <AppText key={t}>{`• ${t}`}</AppText>
          ))}
        </Card>
      ) : null}

      {r.timed_papers.length > 0 ? (
        <Card title="Timed papers">
          {r.timed_papers.map((p, i) => (
            <AppText key={`${p.label}-${p.date}-${i}`}>{`${p.label}, ${p.date}: ${pct(p.pct)} (attempt ${p.attempt_no})`}</AppText>
          ))}
        </Card>
      ) : null}

      {r.strong.length > 0 || r.weak.length > 0 ? (
        <Card title="Topics by score">
          {r.strong.length > 0 ? <AppText variant="label">Strongest</AppText> : null}
          {r.strong.map((t) => (
            <AppText key={`s-${t.name}`}>{`${t.name}: ${t.pct}% (${t.n} attempt${t.n === 1 ? '' : 's'})`}</AppText>
          ))}
          {r.weak.length > 0 ? (
            <AppText variant="label" style={styles.sectionGap}>
              Weakest
            </AppText>
          ) : null}
          {r.weak.map((t) => (
            <AppText key={`w-${t.name}`}>{`${t.name}: ${t.pct}% (${t.n} attempt${t.n === 1 ? '' : 's'})`}</AppText>
          ))}
        </Card>
      ) : null}

      <Card title="Mistake reviews">
        <Line label="Reviewed this period" value={`${r.reviews.reviewed}`} />
        <Line label="Reattempted" value={`${r.reviews.reattempted}`} />
        <Line label="Mastered on their own" value={`${r.reviews.mastered}`} />
        <Line label="Still waiting in the queue" value={`${r.outstanding}`} testID="rep-outstanding" />
      </Card>

      {r.recommendations.length > 0 ? (
        <Card title="What the numbers suggest">
          {r.recommendations.map((x, i) => (
            <Banner key={i} tone={TONE[x.level]}>
              {x.text}
            </Banner>
          ))}
        </Card>
      ) : null}

      {r.monthly ? <MonthlyExtras m={r.monthly} /> : null}
    </View>
  );
}

function MonthlyExtras({ m }: { m: NonNullable<Report['monthly']> }) {
  return (
    <View testID="report-monthly">
      <Card title="Syllabus coverage">
        {m.coverage.map((x) => (
          <Line key={x.subject} label={x.subject} value={`theory ${pct(x.theory_pct)}, mastered ${pct(x.mastered_pct)} of ${x.total} topics`} />
        ))}
      </Card>

      {m.trend.some((t) => Object.keys(t.weeks).length > 0) ? (
        <Card title="Score by week">
          {m.trend.map((t) => {
            const weeks = Object.entries(t.weeks as Record<string, { pct: number | null; n: number }>);
            return weeks.length === 0 ? null : (
              <View key={t.subject} style={styles.sectionGap}>
                <AppText variant="label">{t.subject}</AppText>
                {weeks.map(([w, v]) => (
                  <AppText key={w}>{`Week of ${w}: ${pct(v.pct)} (${v.n} attempt${v.n === 1 ? '' : 's'})`}</AppText>
                ))}
              </View>
            );
          })}
        </Card>
      ) : null}

      {m.papers.by_paper.length > 0 ? (
        <Card title="Paper results">
          {m.papers.by_paper.map((p) => (
            <AppText key={p.label} style={styles.sectionGap}>
              {`${p.label}: first ${pct(p.first_pct)}, latest ${pct(p.latest_pct)}, best ${pct(p.best_pct)} (${p.attempts} attempt${p.attempts === 1 ? '' : 's'}). Time ${formatDuration(p.first_minutes)} of ${formatDuration(p.official)} official.`}
            </AppText>
          ))}
        </Card>
      ) : null}

      <Card title="Solving time">
        <AppText>
          {m.time_change ? `On ${m.time_change.questions} repeated question${m.time_change.questions === 1 ? '' : 's'}, time changed by ${m.time_change.avg_change} min on average from the first to the latest attempt.` : 'Not enough repeated, timed questions yet.'}
        </AppText>
      </Card>

      {m.missed.length > 0 ? (
        <Card title="Behind plan">
          {m.missed.map((x) => (
            <AppText key={x.label}>{`${x.label}: behind by ${formatDuration(x.behind_by)}`}</AppText>
          ))}
        </Card>
      ) : null}

      {m.forecast.length > 0 ? (
        <Card title="Forecast">
          {m.forecast.map((x) => (
            <AppText key={x.label} style={styles.sectionGap}>
              {`${x.label}: needs ${formatDuration(x.required_weekly)} per week; ${formatDuration(x.remaining)} remaining; plan was ${formatDuration(x.weekly_plan)} per week. ${m.remaining_days} days left.`}
            </AppText>
          ))}
        </Card>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  loading: { marginVertical: 24 },
  nav: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8, marginBottom: 14 },
  range: { flex: 1, textAlign: 'center' },
  line: { marginBottom: 8 },
  lineLabel: { marginBottom: 1 },
  lineValue: {},
  sectionGap: { marginTop: 6 },
});
