import { useState } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';

import { ApiError, errorMessage } from '@/api/errors';
import type { Bootstrap, TheorySaved } from '@/api/types';
import { DurationField } from '@/features/dailyLog/DurationField';
import { useBootstrap } from '@/features/dailyLog/hooks';
import { formatLongDate } from '@/lib/dates';
import { formatDuration } from '@/lib/time';
import { AppText } from '@/ui/AppText';
import { Banner } from '@/ui/Banner';
import { Button } from '@/ui/Button';
import { Card } from '@/ui/Card';
import { Choice } from '@/ui/Choice';
import { DateStepper } from '@/ui/DateStepper';
import { RatingChips } from '@/ui/RatingChips';
import { Screen } from '@/ui/Screen';
import { SwitchRow } from '@/ui/SwitchRow';
import { TextField } from '@/ui/TextField';

import { emptyTheory, type TheoryForm, validateTheory } from './forms';
import { useRecentTheory, useRecordTheory } from './hooks';
import { TopicList } from './TopicList';

/** Record time spent studying the theory of one topic. */
export function TheoryScreen() {
  const boot = useBootstrap();
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
  return <TheoryEditor boot={boot.data} />;
}

function TheoryEditor({ boot }: { boot: Bootstrap }) {
  const record = useRecordTheory();
  const [subjectId, setSubjectId] = useState<number | null>(null);
  const [form, setForm] = useState<TheoryForm>(() => emptyTheory(boot.today));
  const [saved, setSaved] = useState<TheorySaved | null>(null);
  const [tried, setTried] = useState(false);
  const check = validateTheory(form);
  const errors = tried && !check.ok ? check.errors : {};

  const set = (patch: Partial<TheoryForm>) => {
    record.reset();
    setForm((f) => ({ ...f, ...patch }));
  };
  const apiError = record.error instanceof ApiError ? record.error : null;

  const submit = () => {
    setTried(true);
    if (!check.ok) return;
    record.mutate(check.input, {
      onSuccess: (r) => {
        setSaved(r);
        // Ready for the next topic; the date and subject stay, since one sitting usually covers several topics.
        setForm((f) => emptyTheory(f.date));
        setTried(false);
      },
    });
  };

  return (
    <Screen>
      {saved ? (
        <View>
          <Banner tone="success" testID="theory-saved">
            {`${saved.entry.topic?.name ?? 'Topic'}: ${formatDuration(saved.entry.minutes)} on ${formatLongDate(saved.entry.date)} is saved${saved.entry.marked_complete ? ', and the theory is marked complete' : ''}. These minutes are part of that day's Study Theory total, not extra.`}
          </Banner>
          {saved.warnings.map((w) => (
            <Banner key={w} tone="warning" title="Check the details">
              {w}
            </Banner>
          ))}
        </View>
      ) : null}

      <Card title="Theory study">
        <DateStepper label="Date studied" date={form.date} today={boot.today} onChange={(d) => set({ date: d })} testID="theory-date" />
        <Choice
          label="Subject"
          testID="theory-subject"
          options={boot.subjects.map((s) => ({ value: s.id, label: s.name }))}
          value={subjectId}
          onChange={(id) => {
            setSubjectId(id);
            set({ topicId: null });
          }}
        />
        <TopicList
          subjectId={subjectId}
          selected={form.topicId === null ? [] : [form.topicId]}
          onChange={(ids) => set({ topicId: ids[0] ?? null })}
          multiple={false}
          label="Topic studied"
          error={errors.topic}
          testID="theory-topic"
        />
        <DurationField
          testID="dur-theory-entry"
          label="Time studied"
          hint="Part of your Study Theory total for the day, not extra."
          hours={form.hours}
          mins={form.mins}
          onChange={({ hours, mins }) => set({ hours, mins })}
          error={errors.minutes}
        />
        <SwitchRow
          label="Theory for this topic is complete"
          hint="This never marks the topic Mastered; that needs practice questions."
          value={form.markedComplete}
          onChange={(v) => set({ markedComplete: v })}
          testID="theory-complete"
        />
        <AppText variant="label" style={styles.label}>
          How confident do you feel? (1 low, 5 high)
        </AppText>
        <RatingChips label="Confidence" value={form.confidence} onChange={(v) => set({ confidence: v })} />
        <View style={styles.notes}>
          <TextField label="Notes (optional)" value={form.notes} onChangeText={(t) => set({ notes: t })} multiline maxLength={2000} testID="theory-notes" />
        </View>

        {record.error ? (
          <Banner tone="error" title={apiError?.kind === 'network' || apiError?.kind === 'timeout' ? 'Not saved: no connection' : 'Not saved'} testID="theory-error">
            {errorMessage(record.error)}
          </Banner>
        ) : null}
        <Button title="Save theory study" testID="theory-save" loading={record.isPending} onPress={submit} />
      </Card>

      <Recent />
    </Screen>
  );
}

function Recent() {
  const q = useRecentTheory();
  if (q.isPending) return <ActivityIndicator accessibilityLabel="Loading recent entries" style={styles.loading} />;
  if (q.isError) {
    return (
      <Banner tone="error" title="Cannot load recent entries">
        {errorMessage(q.error)}
      </Banner>
    );
  }
  return (
    <Card title="Recent theory study">
      {q.data.data.length === 0 ? (
        <AppText variant="small" tone="muted" testID="theory-none">
          Nothing recorded yet.
        </AppText>
      ) : (
        q.data.data.map((e) => (
          <View key={e.id} style={styles.entry} testID={`theory-entry-${e.id}`}>
            <AppText variant="label">{e.topic?.name ?? 'Topic'}</AppText>
            <AppText variant="small" tone="muted">
              {`${formatLongDate(e.date)} · ${formatDuration(e.minutes)}${e.marked_complete ? ' · complete' : ''}`}
            </AppText>
          </View>
        ))
      )}
    </Card>
  );
}

const styles = StyleSheet.create({
  loading: { marginVertical: 24 },
  label: { marginTop: 8, marginBottom: 8 },
  notes: { marginTop: 14 },
  entry: { paddingVertical: 6 },
});
