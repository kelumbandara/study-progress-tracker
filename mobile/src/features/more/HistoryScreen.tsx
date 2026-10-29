import { useState } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';

import { ApiError, errorMessage } from '@/api/errors';
import type { AttemptPage, Bootstrap, TheoryEntry } from '@/api/types';
import { DurationField } from '@/features/dailyLog/DurationField';
import { useBootstrap } from '@/features/dailyLog/hooks';
import { AttemptFields } from '@/features/practice/AttemptFields';
import { attemptToForm, type AttemptForm, theoryToForm, type TheoryEditForm, validateAttempt, validateTheoryEdit } from '@/features/practice/forms';
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
import { TextField } from '@/ui/TextField';

import { useAttemptHistory, useCorrectAttempt, useCorrectTheory, useTheoryHistory } from './hooks';

type Kind = 'attempts' | 'theory';
type AttemptRow = AttemptPage['data'][number];

/** Earlier entries, newest first, each with a way to correct it. Nothing here deletes anything. */
export function HistoryScreen() {
  const boot = useBootstrap();
  const [kind, setKind] = useState<Kind>('attempts');

  if (boot.isPending) {
    return (
      <Screen>
        <ActivityIndicator accessibilityLabel="Loading" style={styles.loading} />
      </Screen>
    );
  }
  if (!boot.data) {
    return (
      <Screen>
        <Banner tone="error" title="Cannot load">
          {errorMessage(boot.error)}
        </Banner>
        <Button title="Try again" onPress={() => void boot.refetch()} />
      </Screen>
    );
  }

  return (
    <Screen>
      <AppText variant="small" tone="muted" style={styles.gap}>
        Correcting an entry keeps a record of the change. The question and the attempt number cannot be changed, and entries cannot be deleted.
      </AppText>
      <Choice
        label="Show"
        testID="hist-kind"
        options={[{ value: 'attempts', label: 'Question attempts' }, { value: 'theory', label: 'Theory study' }]}
        value={kind}
        onChange={(v) => v && setKind(v as Kind)}
      />
      {kind === 'attempts' ? <AttemptHistory boot={boot.data} /> : <TheoryHistory boot={boot.data} />}
    </Screen>
  );
}

function LoadMore({ hasNext, loading, onMore }: { hasNext: boolean; loading: boolean; onMore: () => void }) {
  return hasNext ? <Button variant="secondary" title="Show older entries" testID="hist-more" loading={loading} onPress={onMore} /> : null;
}

// ---- question attempts ----

function AttemptHistory({ boot }: { boot: Bootstrap }) {
  const [subjectId, setSubjectId] = useState<number | null>(null);
  const q = useAttemptHistory(subjectId);
  const [editing, setEditing] = useState<number | null>(null);
  const [savedId, setSavedId] = useState<number | null>(null);

  const rows = q.data?.pages.flatMap((p) => p.data) ?? [];
  return (
    <View>
      <Choice label="Subject" testID="hist-subject" clearable options={boot.subjects.map((s) => ({ value: s.id, label: s.name }))} value={subjectId} onChange={(v) => { setSubjectId(v); setEditing(null); }} />
      {savedId !== null ? (
        <Banner tone="success" testID="attempt-corrected">
          The attempt is corrected.
        </Banner>
      ) : null}
      {q.isPending ? (
        <ActivityIndicator accessibilityLabel="Loading attempts" style={styles.loading} />
      ) : q.isError ? (
        <>
          <Banner tone="error" title="Cannot load the attempts">
            {errorMessage(q.error)}
          </Banner>
          <Button title="Try again" onPress={() => void q.refetch()} />
        </>
      ) : rows.length === 0 ? (
        <Banner tone="info" title="No attempts yet" testID="hist-empty">
          Attempts you record in Practice appear here.
        </Banner>
      ) : (
        rows.map((a) =>
          editing === a.id ? (
            <AttemptCorrection key={a.id} boot={boot} row={a} onCancel={() => setEditing(null)} onSaved={() => { setEditing(null); setSavedId(a.id); }} />
          ) : (
            <Card key={a.id}>
              <AppText variant="label" testID={`attempt-row-${a.id}`}>
                {`${a.question?.subject ? a.question.subject + ' ' : ''}${a.question?.paper_label ?? 'Question'}, question ${a.question?.number ?? ''}, attempt ${a.attempt_no}`}
              </AppText>
              <AppText variant="small" tone="muted">
                {`${formatLongDate(a.date)}  ·  ${a.marks_earned ?? 0} of ${a.max_marks ?? 0} marks (${Math.round(a.percent ?? 0)}%)  ·  ${a.result_label}  ·  ${a.independent ? 'independent' : 'with help'}${a.worked_minutes ? `  ·  ${formatDuration(a.worked_minutes)}` : ''}`}
              </AppText>
              <Button compact variant="secondary" title="Correct this attempt" accessibilityLabel={`Correct attempt ${a.attempt_no} of question ${a.question?.number ?? ''}`} testID={`attempt-edit-${a.id}`} onPress={() => { setEditing(a.id); setSavedId(null); }} />
            </Card>
          ),
        )
      )}
      <LoadMore hasNext={!!q.hasNextPage} loading={q.isFetchingNextPage} onMore={() => void q.fetchNextPage()} />
    </View>
  );
}

function AttemptCorrection({ boot, row, onCancel, onSaved }: { boot: Bootstrap; row: AttemptRow; onCancel: () => void; onSaved: () => void }) {
  const correct = useCorrectAttempt();
  const [form, setForm] = useState<AttemptForm>(() => attemptToForm(row));
  const [tried, setTried] = useState(false);
  const max = row.max_marks ?? 0;
  const check = validateAttempt(form, max);
  const errors = tried && !check.ok ? check.errors : {};
  const set = (patch: Partial<AttemptForm>) => {
    correct.reset();
    setForm((f) => ({ ...f, ...patch }));
  };
  const apiError = correct.error instanceof ApiError ? correct.error : null;

  return (
    <Card title={`Correct attempt ${row.attempt_no}, question ${row.question?.number ?? ''}`}>
      <AttemptFields boot={boot} form={form} set={set} errors={errors} maxMarks={max} />
      <AppText variant="small" tone="muted" style={styles.gap}>
        If this removes the evidence behind a Mastered topic, that topic goes back to Revision Required.
      </AppText>
      {correct.error ? (
        <Banner tone="error" title={apiError?.kind === 'network' || apiError?.kind === 'timeout' ? 'Not saved: no connection' : 'Not saved'} testID="correct-error">
          {errorMessage(correct.error)}
        </Banner>
      ) : null}
      {tried && !check.ok ? (
        <Banner tone="warning" title="Check the details" testID="correct-invalid">
          Some answers above are missing or not valid.
        </Banner>
      ) : null}
      <View style={styles.buttons}>
        <Button
          title="Save correction"
          testID="correct-save"
          loading={correct.isPending}
          onPress={() => {
            setTried(true);
            if (check.ok) correct.mutate({ id: row.id, input: check.input }, { onSuccess: onSaved });
          }}
        />
        <Button variant="secondary" title="Cancel" onPress={onCancel} />
      </View>
    </Card>
  );
}

// ---- theory study ----

function TheoryHistory({ boot }: { boot: Bootstrap }) {
  const q = useTheoryHistory();
  const [editing, setEditing] = useState<number | null>(null);
  const [saved, setSaved] = useState<string[] | null>(null);
  const rows = q.data?.pages.flatMap((p) => p.data) ?? [];

  return (
    <View>
      {saved ? (
        <Banner tone="success" testID="theory-corrected">
          The theory entry is corrected.
        </Banner>
      ) : null}
      {saved && saved.length > 0 ? (
        <Banner tone="warning" testID="theory-warnings">
          {saved.join(' ')}
        </Banner>
      ) : null}
      {q.isPending ? (
        <ActivityIndicator accessibilityLabel="Loading theory entries" style={styles.loading} />
      ) : q.isError ? (
        <>
          <Banner tone="error" title="Cannot load the theory entries">
            {errorMessage(q.error)}
          </Banner>
          <Button title="Try again" onPress={() => void q.refetch()} />
        </>
      ) : rows.length === 0 ? (
        <Banner tone="info" title="No theory entries yet" testID="hist-empty">
          Theory study you record in Practice appears here.
        </Banner>
      ) : (
        rows.map((e) =>
          editing === e.id ? (
            <TheoryCorrection key={e.id} boot={boot} entry={e} onCancel={() => setEditing(null)} onSaved={(w) => { setEditing(null); setSaved(w); }} />
          ) : (
            <Card key={e.id}>
              <AppText variant="label" testID={`theory-row-${e.id}`}>
                {`${e.topic?.subject ? e.topic.subject + ': ' : ''}${e.topic?.name ?? 'Topic'}`}
              </AppText>
              <AppText variant="small" tone="muted">
                {`${formatLongDate(e.date)}  ·  ${formatDuration(e.minutes)}${e.marked_complete ? '  ·  theory complete' : ''}${e.confidence ? `  ·  confidence ${e.confidence}/5` : ''}`}
              </AppText>
              <Button compact variant="secondary" title="Correct this entry" accessibilityLabel={`Correct theory entry ${e.topic?.name ?? ''}`} testID={`theory-edit-${e.id}`} onPress={() => { setEditing(e.id); setSaved(null); }} />
            </Card>
          ),
        )
      )}
      <LoadMore hasNext={!!q.hasNextPage} loading={q.isFetchingNextPage} onMore={() => void q.fetchNextPage()} />
    </View>
  );
}

function TheoryCorrection({ boot, entry, onCancel, onSaved }: { boot: Bootstrap; entry: TheoryEntry; onCancel: () => void; onSaved: (warnings: string[]) => void }) {
  const correct = useCorrectTheory();
  const [form, setForm] = useState<TheoryEditForm>(() => theoryToForm(entry));
  const [tried, setTried] = useState(false);
  const check = validateTheoryEdit(form);
  const errors = tried && !check.ok ? check.errors : {};
  const set = (patch: Partial<TheoryEditForm>) => {
    correct.reset();
    setForm((f) => ({ ...f, ...patch }));
  };

  return (
    <Card title={`Correct: ${entry.topic?.name ?? 'theory entry'}`}>
      <DateStepper label="Date studied" date={form.date} today={boot.today} onChange={(d) => set({ date: d })} testID="theory-date" />
      <DurationField testID="dur-theory-edit" label="Time studied" hint="Part of your Study Theory total for that day, not extra." hours={form.hours} mins={form.mins} onChange={({ hours, mins }) => set({ hours, mins })} error={errors.minutes} />
      <AppText variant="label" style={styles.gap}>
        Confidence (optional)
      </AppText>
      <RatingChips label="Confidence" value={form.confidence} onChange={(v) => set({ confidence: v })} />
      <TextField label="Notes (optional)" value={form.notes} onChangeText={(t) => set({ notes: t })} multiline maxLength={2000} testID="theory-notes" />
      {correct.error ? (
        <Banner tone="error" title="Not saved" testID="correct-error">
          {errorMessage(correct.error)}
        </Banner>
      ) : null}
      <View style={styles.buttons}>
        <Button
          title="Save correction"
          testID="correct-save"
          loading={correct.isPending}
          onPress={() => {
            setTried(true);
            if (check.ok) correct.mutate({ id: entry.id, input: check.input }, { onSuccess: (r) => onSaved(r.warnings) });
          }}
        />
        <Button variant="secondary" title="Cancel" onPress={onCancel} />
      </View>
    </Card>
  );
}

const styles = StyleSheet.create({
  loading: { marginVertical: 24 },
  gap: { marginBottom: 12 },
  buttons: { gap: 10, marginTop: 4 },
});
