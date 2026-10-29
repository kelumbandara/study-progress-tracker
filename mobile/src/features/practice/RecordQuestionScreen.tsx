import { useState } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';

import { ApiError, errorMessage } from '@/api/errors';
import type { AttemptSaved, Bootstrap, Paper, Question } from '@/api/types';
import { useBootstrap } from '@/features/dailyLog/hooks';
import { formatDuration } from '@/lib/time';
import { AppText } from '@/ui/AppText';
import { Banner } from '@/ui/Banner';
import { Button } from '@/ui/Button';
import { Card } from '@/ui/Card';
import { Choice } from '@/ui/Choice';
import { Screen } from '@/ui/Screen';
import { TextField } from '@/ui/TextField';

import { type AttemptForm, emptyAttempt, marksOnly, validateAttempt, validatePaper, validateQuestion } from './forms';
import { usePaperQuestions, usePapers, useRecordAttempt, useRegisterPaper, useRegisterQuestion } from './hooks';
import { AttemptFields } from './AttemptFields';
import { TopicList } from './TopicList';

/** Record one attempt at one past-paper question: Subject → Year → Paper → Question, then how it went. */
export function RecordQuestionScreen() {
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
  return <Recorder boot={boot.data} />;
}

function Recorder({ boot }: { boot: Bootstrap }) {
  const [subjectId, setSubjectId] = useState<number | null>(null);
  const [year, setYear] = useState<number | null>(null);
  const [paperId, setPaperId] = useState<number | null>(null);
  const [questionId, setQuestionId] = useState<number | null>(null);
  const [addingPaper, setAddingPaper] = useState(false);
  const [addingQuestion, setAddingQuestion] = useState(false);
  const [saved, setSaved] = useState<AttemptSaved | null>(null);

  const papers = usePapers(subjectId, year);
  const questions = usePaperQuestions(paperId);
  const question = questions.data?.data.find((q) => q.id === questionId) ?? null;
  const paper = papers.data?.data.find((p) => p.id === paperId) ?? null;

  const years: number[] = [];
  for (let y = boot.paper_years.to; y >= boot.paper_years.from; y--) years.push(y);

  const pickSubject = (id: number | null) => {
    setSubjectId(id);
    setPaperId(null);
    setQuestionId(null);
    setAddingPaper(false);
    setAddingQuestion(false);
    setSaved(null);
  };
  const pickYear = (y: number | null) => {
    setYear(y);
    setPaperId(null);
    setQuestionId(null);
    setAddingPaper(false);
    setAddingQuestion(false);
    setSaved(null);
  };
  const pickPaper = (id: number | null) => {
    setPaperId(id);
    setQuestionId(null);
    setAddingQuestion(false);
    setSaved(null);
  };

  return (
    <Screen>
      <Card title="Which question?">
        <Choice
          label="Subject"
          testID="subject"
          options={boot.subjects.map((s) => ({ value: s.id, label: s.name }))}
          value={subjectId}
          onChange={pickSubject}
        />
        {subjectId !== null ? <Choice label="Exam year" testID="year" options={years.map((y) => ({ value: y, label: String(y) }))} value={year} onChange={pickYear} /> : null}

        {subjectId !== null && year !== null ? (
          <PaperStep
            papers={papers}
            paperId={paperId}
            onPick={pickPaper}
            adding={addingPaper}
            setAdding={setAddingPaper}
            subjectId={subjectId}
            year={year}
          />
        ) : null}

        {paperId !== null && paper ? (
          <QuestionStep
            paper={paper}
            questions={questions}
            questionId={questionId}
            onPick={(id) => {
              setQuestionId(id);
              setSaved(null);
            }}
            adding={addingQuestion}
            setAdding={setAddingQuestion}
            subjectId={subjectId}
          />
        ) : null}
      </Card>

      {question && paper ? (
        saved ? (
          <SavedCard
            saved={saved}
            question={question}
            paper={paper}
            onAgain={() => setSaved(null)}
            onOther={() => {
              setQuestionId(null);
              setSaved(null);
            }}
          />
        ) : (
          <AttemptEditor key={question.id} boot={boot} question={question} paper={paper} onSaved={setSaved} />
        )
      ) : null}
    </Screen>
  );
}

type PapersQuery = ReturnType<typeof usePapers>;

function PaperStep({
  papers, paperId, onPick, adding, setAdding, subjectId, year,
}: {
  papers: PapersQuery;
  paperId: number | null;
  onPick: (id: number | null) => void;
  adding: boolean;
  setAdding: (v: boolean) => void;
  subjectId: number;
  year: number;
}) {
  const register = useRegisterPaper();
  const [name, setName] = useState('');
  const [maxMarks, setMaxMarks] = useState('');
  const [duration, setDuration] = useState('');
  const [tried, setTried] = useState(false);
  const check = validatePaper({ name, maxMarks, duration });
  const errors = tried && !check.ok ? check.errors : {};

  if (papers.isPending) return <ActivityIndicator accessibilityLabel="Loading papers" style={styles.loading} />;
  if (papers.isError) {
    return (
      <>
        <Banner tone="error" title="Cannot load the papers">
          {errorMessage(papers.error)}
        </Banner>
        <Button title="Try again" onPress={() => void papers.refetch()} />
      </>
    );
  }
  const list = papers.data.data;

  const submit = () => {
    setTried(true);
    if (!check.ok) return;
    register.mutate(
      { subject_id: subjectId, exam_year: year, ...check.input },
      { onSuccess: (r) => { setAdding(false); setName(''); setMaxMarks(''); setDuration(''); setTried(false); onPick(r.paper.id); } },
    );
  };

  return (
    <View>
      {list.length > 0 ? (
        <Choice label="Paper" testID="paper" options={list.map((p) => ({ value: p.id, label: p.label }))} value={paperId} onChange={onPick} />
      ) : (
        <AppText variant="small" tone="muted" style={styles.gap} testID="no-papers">
          No paper is registered for this subject and year yet.
        </AppText>
      )}
      {adding ? (
        <View style={styles.sub}>
          <AppText variant="label" style={styles.gap}>
            Add a paper
          </AppText>
          <TextField label="Paper name" value={name} onChangeText={setName} placeholder="For example Paper I" maxLength={32} error={errors.name} testID="paper-name" />
          <TextField label="Maximum marks (optional)" value={maxMarks} onChangeText={(t) => setMaxMarks(t.replace(/\D/g, ''))} keyboardType="number-pad" error={errors.maxMarks} testID="paper-max" />
          <TextField label="Duration in minutes (optional)" value={duration} onChangeText={(t) => setDuration(t.replace(/\D/g, ''))} keyboardType="number-pad" error={errors.duration} testID="paper-duration" />
          {register.error ? (
            <Banner tone="error" title="Paper not added" testID="paper-error">
              {errorMessage(register.error)}
            </Banner>
          ) : null}
          <View style={styles.buttons}>
            <Button title="Add this paper" testID="paper-add" loading={register.isPending} onPress={submit} />
            <Button variant="secondary" title="Cancel" onPress={() => { setAdding(false); register.reset(); }} />
          </View>
        </View>
      ) : (
        <Button variant="secondary" title="Paper not listed? Add it" testID="paper-new" onPress={() => setAdding(true)} />
      )}
    </View>
  );
}

type QuestionsQuery = ReturnType<typeof usePaperQuestions>;

function QuestionStep({
  paper, questions, questionId, onPick, adding, setAdding, subjectId,
}: {
  paper: Paper;
  questions: QuestionsQuery;
  questionId: number | null;
  onPick: (id: number | null) => void;
  adding: boolean;
  setAdding: (v: boolean) => void;
  subjectId: number | null;
}) {
  const register = useRegisterQuestion(paper.id);
  const [number, setNumber] = useState('');
  const [maxMarks, setMaxMarks] = useState('');
  const [topicIds, setTopicIds] = useState<number[]>([]);
  const [tried, setTried] = useState(false);
  const check = validateQuestion({ number, maxMarks, topicIds });
  const errors = tried && !check.ok ? check.errors : {};

  if (questions.isPending) return <ActivityIndicator accessibilityLabel="Loading questions" style={styles.loading} />;
  if (questions.isError) {
    return (
      <>
        <Banner tone="error" title="Cannot load the questions">
          {errorMessage(questions.error)}
        </Banner>
        <Button title="Try again" onPress={() => void questions.refetch()} />
      </>
    );
  }
  const list = questions.data.data;

  const submit = () => {
    setTried(true);
    if (!check.ok) return;
    register.mutate(check.input, {
      onSuccess: (r) => { setAdding(false); setNumber(''); setMaxMarks(''); setTopicIds([]); setTried(false); onPick(r.question.id); },
    });
  };

  return (
    <View>
      {list.length > 0 ? (
        <Choice
          label="Question"
          testID="question"
          options={list.map((q) => ({ value: q.id, label: `${q.number} (${q.max_marks})` }))}
          value={questionId}
          onChange={onPick}
        />
      ) : (
        <AppText variant="small" tone="muted" style={styles.gap} testID="no-questions">
          No question is registered for this paper yet.
        </AppText>
      )}
      {adding ? (
        <View style={styles.sub}>
          <AppText variant="label" style={styles.gap}>
            Add a question to {paper.label}
          </AppText>
          <TextField label="Question number" value={number} onChangeText={setNumber} placeholder="For example 3 or 3(b)" maxLength={16} autoCapitalize="none" error={errors.number} testID="question-number" />
          <TextField label="Marks it is worth" value={maxMarks} onChangeText={(t) => setMaxMarks(marksOnly(t))} keyboardType="decimal-pad" error={errors.maxMarks} testID="question-max" />
          <TopicList subjectId={subjectId} selected={topicIds} onChange={setTopicIds} multiple label="Topics it covers (optional)" testID="question-topic" />
          {register.error ? (
            <Banner tone="error" title="Question not added" testID="question-error">
              {errorMessage(register.error)}
            </Banner>
          ) : null}
          <View style={styles.buttons}>
            <Button title="Add this question" testID="question-add" loading={register.isPending} onPress={submit} />
            <Button variant="secondary" title="Cancel" onPress={() => { setAdding(false); register.reset(); }} />
          </View>
        </View>
      ) : (
        <Button variant="secondary" title="Question not listed? Add it" testID="question-new" onPress={() => setAdding(true)} />
      )}
    </View>
  );
}

function AttemptEditor({ boot, question, paper, onSaved }: { boot: Bootstrap; question: Question; paper: Paper; onSaved: (s: AttemptSaved) => void }) {
  const record = useRecordAttempt();
  const [form, setForm] = useState<AttemptForm>(() => emptyAttempt(boot.today));
  const [tried, setTried] = useState(false);
  const check = validateAttempt(form, question.max_marks);
  const errors = tried && !check.ok ? check.errors : {};
  const set = (patch: Partial<AttemptForm>) => {
    record.reset();
    setForm((f) => ({ ...f, ...patch }));
  };

  const apiError = record.error instanceof ApiError ? record.error : null;
  const submit = () => {
    setTried(true);
    if (!check.ok) return;
    record.mutate({ question_id: question.id, ...check.input }, { onSuccess: onSaved });
  };

  return (
    <Card title={`${paper.label}, question ${question.number}`}>
      <AppText variant="small" tone="muted" style={styles.gap}>
        Worth {question.max_marks} marks. {question.attempts_count ? `You have recorded ${question.attempts_count} earlier attempt${question.attempts_count === 1 ? '' : 's'}; this one is added as a new attempt, nothing is overwritten.` : 'This will be your first attempt.'}
      </AppText>

      <AttemptFields boot={boot} form={form} set={set} errors={errors} maxMarks={question.max_marks} />

      {record.error ? (
        <Banner tone="error" title={apiError?.kind === 'network' || apiError?.kind === 'timeout' ? 'Not saved: no connection' : 'Not saved'} testID="attempt-error">
          {errorMessage(record.error)}
        </Banner>
      ) : null}
      {tried && !check.ok ? (
        <Banner tone="warning" title="Check the details" testID="attempt-invalid">
          Some answers above are missing or not valid.
        </Banner>
      ) : null}
      <Button title="Save attempt" testID="attempt-save" loading={record.isPending} onPress={submit} />
    </Card>
  );
}

function SavedCard({ saved, question, paper, onAgain, onOther }: { saved: AttemptSaved; question: Question; paper: Paper; onAgain: () => void; onOther: () => void }) {
  const a = saved.attempt;
  const imp = saved.improvement;
  return (
    <View>
      <Banner tone="success" testID="attempt-saved">
        {`Attempt ${a.attempt_no} at ${paper.label}, question ${question.number} is saved: ${a.marks_earned ?? 0} of ${a.max_marks ?? question.max_marks} marks (${Math.round(a.percent ?? 0)}%), ${a.independent ? 'independent' : 'with help'}.`}
      </Banner>
      {saved.warnings.map((w) => (
        <Banner key={w} tone="warning" title="Check the details">
          {w}
        </Banner>
      ))}
      {imp ? (
        <Card title="Progress on this question">
          <AppText variant="small" testID="attempt-improvement">
            {`First attempt ${Math.round(imp.first_percent)}%, this one ${Math.round(imp.latest_percent)}% (${imp.score_points >= 0 ? '+' : ''}${imp.score_points} points). Best so far ${Math.round(imp.best_percent)}%.`}
            {imp.minutes_change !== null ? ` Time ${imp.minutes_change > 0 ? 'up' : imp.minutes_change < 0 ? 'down' : 'unchanged'}${imp.minutes_change !== 0 ? ` by ${formatDuration(Math.abs(imp.minutes_change))}` : ''}.` : ''}
          </AppText>
        </Card>
      ) : null}
      {saved.topics.length > 0 ? (
        <Card title="Topics linked to this question">
          {saved.topics.map((t) => (
            <AppText key={t.id} variant="small" testID={`topic-status-${t.id}`}>
              {`${t.name}: ${t.status.replace(/_/g, ' ')}`}
            </AppText>
          ))}
        </Card>
      ) : null}
      <View style={styles.buttons}>
        <Button title="Record another attempt at this question" testID="attempt-again" onPress={onAgain} />
        <Button variant="secondary" title="Choose another question" testID="attempt-other" onPress={onOther} />
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  loading: { marginVertical: 24 },
  gap: { marginBottom: 12 },
  sub: { marginTop: 4, marginBottom: 8 },
  buttons: { gap: 10, marginTop: 4 },
});
