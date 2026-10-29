import { useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, RefreshControl, StyleSheet, Switch, View } from 'react-native';

import { ApiError, errorMessage } from '@/api/errors';
import type { DailyLogPayload } from '@/api/types';
import { addDays, formatLongDate } from '@/lib/dates';
import { formatDuration } from '@/lib/time';
import { AppText } from '@/ui/AppText';
import { Banner } from '@/ui/Banner';
import { Button } from '@/ui/Button';
import { Card } from '@/ui/Card';
import { RatingChips } from '@/ui/RatingChips';
import { Screen } from '@/ui/Screen';
import { TextField } from '@/ui/TextField';
import { usePalette } from '@/ui/theme';

import { DurationField } from './DurationField';
import { CATEGORIES, type DayForm, formFromLog, formsEqual, totalMinutes, validate } from './form';
import { useBootstrap, useDailyLog, useSaveDailyLog } from './hooks';

/** "Today": the evening entry. Pick a day (today by default), type four durations, save. */
export function DailyLogScreen() {
  const boot = useBootstrap();
  const qc = useQueryClient();
  const [selected, setSelected] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  if (boot.isPending) {
    return (
      <Screen>
        <ActivityIndicator accessibilityLabel="Loading" style={styles.loading} />
      </Screen>
    );
  }
  if (boot.isError) {
    return (
      <Screen>
        <Banner tone="error" title="Cannot load">
          {errorMessage(boot.error)}
        </Banner>
        <Button title="Try again" onPress={() => void boot.refetch()} />
      </Screen>
    );
  }

  const today = boot.data.today; // the SERVER's today; the phone's clock is never used
  const date = selected ?? today;

  const refresh = async () => {
    setRefreshing(true);
    try {
      await Promise.all([qc.refetchQueries({ queryKey: ['bootstrap'] }), qc.refetchQueries({ queryKey: ['dailyLog'] })]);
    } finally {
      setRefreshing(false);
    }
  };

  return (
    <Screen refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void refresh()} />}>
      <DayPicker date={date} today={today} onChange={setSelected} />
      <DayEditor key={date} date={date} />
    </Screen>
  );
}

function DayPicker({ date, today, onChange }: { date: string; today: string; onChange: (d: string | null) => void }) {
  const isToday = date === today;
  return (
    <View style={styles.pickerWrap}>
      <View style={styles.picker}>
        <Button compact variant="secondary" title="‹" accessibilityLabel="Previous day" testID="day-prev" onPress={() => onChange(addDays(date, -1))} />
        <View style={styles.pickerLabel} accessibilityRole="header">
          <AppText variant="heading" testID="day-label" style={styles.center}>
            {formatLongDate(date)}
          </AppText>
          <AppText variant="small" tone="muted">
            {isToday ? 'Today' : ' '}
          </AppText>
        </View>
        <Button compact variant="secondary" title="›" accessibilityLabel="Next day" testID="day-next" disabled={date >= today} onPress={() => onChange(addDays(date, 1))} />
      </View>
      {!isToday ? <Button variant="secondary" title="Go to today" testID="day-today" onPress={() => onChange(null)} /> : null}
    </View>
  );
}

function DayEditor({ date }: { date: string }) {
  const q = useDailyLog(date);
  if (q.isPending) return <ActivityIndicator accessibilityLabel="Loading this day" style={styles.loading} />;
  if (q.isError) {
    return (
      <>
        <Banner tone="error" title="Cannot load this day">
          {errorMessage(q.error)}
        </Banner>
        <Button title="Try again" onPress={() => void q.refetch()} />
      </>
    );
  }
  return <DayForm date={date} payload={q.data} />;
}

function DayForm({ date, payload }: { date: string; payload: DailyLogPayload }) {
  const p = usePalette();
  const save = useSaveDailyLog(date);
  const baseline = useMemo(() => formFromLog(payload.log), [payload.log]);
  const [form, setForm] = useState<DayForm>(baseline);

  // If newer data for this day arrives (for example after a refresh) and nothing has been typed, show it.
  const baselineRef = useRef(baseline);
  useEffect(() => {
    setForm((current) => (formsEqual(current, baselineRef.current) ? baseline : current));
    baselineRef.current = baseline;
  }, [baseline]);

  const dirty = !formsEqual(form, baseline);
  const validation = validate(form);
  const total = totalMinutes(form);
  const logged = payload.log.logged;

  const apiError = save.error instanceof ApiError ? save.error : null;
  const serverFieldErrors: Record<string, string | undefined> = {};
  for (const { key } of CATEGORIES) serverFieldErrors[key] = apiError?.fieldMessage(`minutes.${key}`);
  const fieldShown = new Set(Object.keys(serverFieldErrors).filter((k) => serverFieldErrors[k]));
  // Show the server's message as a banner unless it is already shown beside a field.
  const bannerText = apiError && fieldShown.size === 0 ? apiError.message : save.error && !apiError ? errorMessage(save.error) : null;

  const set = (patch: Partial<DayForm>) => {
    save.reset();
    setForm((f) => ({ ...f, ...patch }));
  };

  const onSave = () => {
    if (!validation.ok) return;
    // Show exactly what the server stored (for example notes without surrounding spaces).
    save.mutate(validation.input, { onSuccess: (saved) => setForm(formFromLog(saved.log)) });
  };

  return (
    <View>
      {payload.warnings.map((w) => (
        <Banner key={w} tone="warning" title="Check the details">
          {w}
        </Banner>
      ))}

      {save.isSuccess && !dirty ? (
        <Banner tone="success" testID="status-saved">
          This day is saved. You can change it any time; changes are kept in a history.
        </Banner>
      ) : logged ? (
        <Banner tone="info" title="Already logged" testID="status-logged">
          This day is already saved. Change anything below and press Save changes.
        </Banner>
      ) : (
        <Banner tone="warning" title="Not logged yet" testID="status-missing">
          A day with no log counts as missing, not as zero. If you really did nothing, save it with 0 h 0 min.
        </Banner>
      )}

      <Card title="Time spent">
        <AppText variant="small" tone="muted" style={styles.intro}>
          Enter the total for each category. The minutes you spend on a particular question or topic are part of these totals, not extra.
        </AppText>
        {CATEGORIES.map(({ key, label, hint }) => (
          <DurationField
            key={key}
            testID={`dur-${key}`}
            label={label}
            hint={hint}
            hours={form.hours[key]}
            mins={form.mins[key]}
            onChange={({ hours, mins }) => set({ hours: { ...form.hours, [key]: hours }, mins: { ...form.mins, [key]: mins } })}
            error={!validation.ok ? validation.errors.fields[key] : serverFieldErrors[key]}
          />
        ))}

        <View style={[styles.totalBox, { backgroundColor: p.background, borderColor: p.border }]}>
          <AppText variant="label">Day total</AppText>
          <AppText variant="heading" testID="day-total">
            {total === null ? '—' : formatDuration(total)}
          </AppText>
        </View>
        {!validation.ok && validation.errors.total ? (
          <AppText variant="small" tone="danger" style={styles.totalError} testID="total-error">
            {'✖ '}
            {validation.errors.total}
          </AppText>
        ) : null}
      </Card>

      <Card title="About the day">
        <View style={styles.switchRow}>
          <View style={styles.switchText}>
            <AppText variant="label">Rest day</AppText>
            <AppText variant="small" tone="muted">
              No school or tuition today
            </AppText>
          </View>
          <Switch testID="rest-day" accessibilityLabel="Rest day" value={form.isRestDay} onValueChange={(v) => set({ isRestDay: v })} />
        </View>
        <AppText variant="label" style={styles.ratingLabel}>
          How was the day? (1 hard, 5 easy)
        </AppText>
        <RatingChips label="Day rating" value={form.rating} onChange={(v) => set({ rating: v })} />
        <View style={styles.notes}>
          <TextField label="Notes (optional)" value={form.notes} onChangeText={(t) => set({ notes: t })} multiline maxLength={2000} testID="notes" />
        </View>
      </Card>

      {bannerText ? (
        <Banner tone="error" title={apiError?.kind === 'network' || apiError?.kind === 'timeout' ? 'Not saved: no connection' : 'Not saved'} testID="save-error">
          {bannerText}
        </Banner>
      ) : null}

      <Button
        title={logged ? 'Save changes' : 'Save day'}
        testID="save-day"
        loading={save.isPending}
        disabled={!validation.ok || (logged && !dirty)}
        onPress={onSave}
      />

      <View style={styles.summary}>
        <Card title="Your totals so far">
          {CATEGORIES.map(({ key, label }) => (
            <View key={key} style={styles.sumRow}>
              <AppText variant="small">{label}</AppText>
              <AppText variant="small">{formatDuration(payload.cumulative_minutes[key])}</AppText>
            </View>
          ))}
          <AppText variant="small" tone="muted" style={styles.missing} testID="missing-days">
            Days with no log so far: {payload.missing_days} of {payload.days_so_far}. A missing day is not counted as zero.
          </AppText>
        </Card>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  loading: { marginTop: 48 },
  pickerWrap: { marginBottom: 14 },
  picker: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 },
  pickerLabel: { flex: 1, alignItems: 'center', paddingHorizontal: 8 },
  center: { textAlign: 'center' },
  intro: { marginBottom: 12 },
  totalBox: { borderWidth: 1, borderRadius: 8, padding: 12, flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  totalError: { marginTop: 8 },
  switchRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', minHeight: 56 },
  switchText: { flex: 1, paddingRight: 12 },
  ratingLabel: { marginTop: 8, marginBottom: 8 },
  notes: { marginTop: 14 },
  summary: { marginTop: 20 },
  sumRow: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 4 },
  missing: { marginTop: 10 },
});
