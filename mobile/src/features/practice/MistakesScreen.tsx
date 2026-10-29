import { useState } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';

import { ApiError, errorMessage } from '@/api/errors';
import type { Bootstrap, MistakeRow } from '@/api/types';
import { useBootstrap } from '@/features/dailyLog/hooks';
import { formatLongDate } from '@/lib/dates';
import { AppText } from '@/ui/AppText';
import { Banner } from '@/ui/Banner';
import { Button } from '@/ui/Button';
import { Card } from '@/ui/Card';
import { DateStepper } from '@/ui/DateStepper';
import { Screen } from '@/ui/Screen';
import { SwitchRow } from '@/ui/SwitchRow';
import { TextField } from '@/ui/TextField';

import { useMistakes, useReviewMistake } from './hooks';

/** Questions that still need work, and a quick way to note that you reviewed one. */
export function MistakesScreen() {
  const boot = useBootstrap();
  const list = useMistakes();
  const [open, setOpen] = useState<number | null>(null);
  const [savedId, setSavedId] = useState<number | null>(null);

  if (boot.isPending || list.isPending) {
    return (
      <Screen>
        <ActivityIndicator accessibilityLabel="Loading" style={styles.loading} />
      </Screen>
    );
  }
  if (!boot.data || !list.data) {
    return (
      <Screen>
        <Banner tone="error" title="Cannot load">
          {errorMessage(boot.error ?? list.error)}
        </Banner>
        <Button
          title="Try again"
          onPress={() => {
            void boot.refetch();
            void list.refetch();
          }}
        />
      </Screen>
    );
  }

  const rows = list.data.data;
  return (
    <Screen>
      <AppText variant="small" tone="muted" style={styles.intro}>
        A question stays here until you score {boot.data.rules.mastery_pct}% or more on an independent attempt. Reviewing it is a note that you worked on it; it does not remove it.
      </AppText>
      {savedId !== null ? (
        <Banner tone="success" testID="review-saved">
          Your review is saved.
        </Banner>
      ) : null}
      {rows.length === 0 ? (
        <Banner tone="info" title="Nothing to review" testID="mistakes-none">
          No question needs review right now. Questions appear here after a weak or repeated attempt.
        </Banner>
      ) : (
        rows.map((r) => (
          <MistakeCard
            key={r.question.id}
            row={r}
            boot={boot.data}
            open={open === r.question.id}
            onToggle={() => {
              setOpen(open === r.question.id ? null : r.question.id);
              setSavedId(null);
            }}
            onSaved={() => {
              setOpen(null);
              setSavedId(r.question.id);
            }}
          />
        ))
      )}
    </Screen>
  );
}

function MistakeCard({ row, boot, open, onToggle, onSaved }: { row: MistakeRow; boot: Bootstrap; open: boolean; onToggle: () => void; onSaved: () => void }) {
  const q = row.question;
  const a = row.latest_attempt;
  return (
    <Card>
      <View testID={`mistake-${q.id}`}>
        <AppText variant="heading">{`${q.paper_label}, question ${q.number}`}</AppText>
        <AppText variant="small" tone="muted">
          {`${q.subject} · latest ${a.marks_earned ?? 0}/${q.max_marks} (${Math.round(a.percent ?? 0)}%), ${a.independent ? 'independent' : 'with help'}, ${formatLongDate(a.date)}`}
        </AppText>
        {row.repeated ? (
          <AppText variant="small" tone="warning" style={styles.row}>
            {`⚠ Repeated mistake: weak in ${row.weak_attempts} of ${row.attempts} attempts`}
          </AppText>
        ) : null}
        {row.reasons.map((reason) => (
          <AppText key={reason} variant="small" style={styles.row}>
            {`• ${reason}`}
          </AppText>
        ))}
        {q.topics.length > 0 ? (
          <AppText variant="small" tone="muted" style={styles.row}>
            {`Topics: ${q.topics.map((t) => t.name).join(', ')}`}
          </AppText>
        ) : null}
        <AppText variant="small" tone="muted" style={styles.row}>
          {row.last_review ? `Last reviewed ${formatLongDate(row.last_review)}` : 'Not reviewed yet'}
        </AppText>
        <View style={styles.action}>
          <Button variant="secondary" title={open ? 'Close' : 'Record a review'} testID={`review-open-${q.id}`} onPress={onToggle} />
        </View>
        {open ? <ReviewForm row={row} today={boot.today} onSaved={onSaved} /> : null}
      </View>
    </Card>
  );
}

function ReviewForm({ row, today, onSaved }: { row: MistakeRow; today: string; onSaved: () => void }) {
  const review = useReviewMistake();
  const [date, setDate] = useState(today);
  const [flags, setFlags] = useState({ theory_reviewed: false, solution_understood: false, reattempted: false, mastered_independently: false });
  const [notes, setNotes] = useState('');
  const apiError = review.error instanceof ApiError ? review.error : null;
  const set = (patch: Partial<typeof flags>) => {
    review.reset();
    setFlags((f) => ({ ...f, ...patch }));
  };

  return (
    <View style={styles.form}>
      <DateStepper
        label="Date reviewed"
        date={date}
        today={today}
        onChange={(d) => {
          review.reset();
          setDate(d);
        }}
        testID="review-date"
      />
      <SwitchRow label="I reviewed the theory" value={flags.theory_reviewed} onChange={(v) => set({ theory_reviewed: v })} testID="review-theory" />
      <SwitchRow label="I understood the solution" value={flags.solution_understood} onChange={(v) => set({ solution_understood: v })} testID="review-solution" />
      <SwitchRow label="I tried it again" value={flags.reattempted} onChange={(v) => set({ reattempted: v })} testID="review-reattempted" />
      <SwitchRow
        label="I can now do it on my own"
        hint="Only accepted if your latest attempt was independent and at or above the pass mark. Record that attempt first."
        value={flags.mastered_independently}
        onChange={(v) => set({ mastered_independently: v })}
        testID="review-mastered"
      />
      <View style={styles.notes}>
        <TextField
          label="Notes (optional)"
          value={notes}
          onChangeText={(t) => {
            review.reset();
            setNotes(t);
          }}
          multiline
          maxLength={2000}
          testID="review-notes"
        />
      </View>
      {review.error ? (
        <Banner tone="error" title={apiError?.kind === 'network' || apiError?.kind === 'timeout' ? 'Not saved: no connection' : 'Not saved'} testID="review-error">
          {errorMessage(review.error)}
        </Banner>
      ) : null}
      <Button
        title="Save review"
        testID="review-save"
        loading={review.isPending}
        onPress={() =>
          review.mutate({ question_id: row.question.id, reviewed_on: date, ...flags, notes: notes.trim() === '' ? null : notes.trim() }, { onSuccess: onSaved })
        }
      />
    </View>
  );
}

const styles = StyleSheet.create({
  loading: { marginVertical: 24 },
  intro: { marginBottom: 12 },
  row: { marginTop: 4 },
  action: { marginTop: 12 },
  form: { marginTop: 14 },
  notes: { marginTop: 8 },
});
