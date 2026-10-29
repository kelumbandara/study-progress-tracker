import type { Bootstrap } from '@/api/types';
import { DurationField } from '@/features/dailyLog/DurationField';
import { AppText } from '@/ui/AppText';
import { Choice } from '@/ui/Choice';
import { DateStepper } from '@/ui/DateStepper';
import { SwitchRow } from '@/ui/SwitchRow';
import { TextField } from '@/ui/TextField';

import { type AttemptErrors, type AttemptForm, marksOnly } from './forms';

interface Props {
  boot: Bootstrap;
  form: AttemptForm;
  set: (patch: Partial<AttemptForm>) => void;
  errors: AttemptErrors;
  maxMarks: number;
}

/** The answers about one attempt. Shared by "Record a question" and by correcting an earlier attempt. */
export function AttemptFields({ boot, form, set, errors, maxMarks }: Props) {
  return (
    <>
      <DateStepper label="Date of the attempt" date={form.date} today={boot.today} onChange={(d) => set({ date: d })} testID="attempt-date" />
      <TextField
        label={`Marks you scored (out of ${maxMarks})`}
        value={form.marks}
        onChangeText={(t) => set({ marks: marksOnly(t) })}
        keyboardType="decimal-pad"
        error={errors.marks}
        testID="attempt-marks"
      />
      <Choice
        label="How did it go?"
        testID="attempt-result"
        options={boot.options.attempt_results.map((o) => ({ value: o.key, label: o.label }))}
        value={form.result}
        onChange={(v) => set({ result: v as AttemptForm['result'] })}
        error={errors.result}
      />
      <Choice
        label="Did you do it without help?"
        testID="attempt-independent"
        options={[{ value: 'yes', label: 'Yes, on my own' }, { value: 'no', label: 'No, with help or hints' }]}
        value={form.independent === null ? null : form.independent ? 'yes' : 'no'}
        onChange={(v) => set({ independent: v === null ? null : v === 'yes' })}
        error={errors.independent}
      />
      <AppText variant="small" tone="muted" style={{ marginBottom: 12 }}>
        Only an independent attempt of {boot.rules.mastery_pct}% or more can lead to Mastered.
      </AppText>

      <DurationField
        testID="dur-worked"
        label="Time worked (optional)"
        hint="Leave 0 h 0 min if you did not time it. This is part of your Write Exam Papers total, not extra."
        hours={form.hours}
        mins={form.mins}
        onChange={({ hours, mins }) => set({ hours, mins })}
        error={errors.worked}
      />
      <SwitchRow label="Checked with the marking scheme" value={form.checkedWithScheme} onChange={(v) => set({ checkedWithScheme: v })} testID="attempt-scheme" />
      <Choice
        label="Main mistake (optional)"
        testID="attempt-mistake"
        clearable
        options={boot.options.mistake_categories.map((o) => ({ value: o.key, label: o.label }))}
        value={form.mistakeCategory}
        onChange={(v) => set({ mistakeCategory: v })}
      />
      <TextField label="Notes (optional)" value={form.notes} onChangeText={(t) => set({ notes: t })} multiline maxLength={2000} testID="attempt-notes" />
    </>
  );
}
