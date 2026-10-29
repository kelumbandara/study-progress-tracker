import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';

import { errorMessage } from '@/api/errors';
import type { Bootstrap, SyllabusStats, SyllabusTopic, SyllabusUnit } from '@/api/types';
import { useBootstrap } from '@/features/dailyLog/hooks';
import { formatLongDate } from '@/lib/dates';
import { AppText } from '@/ui/AppText';
import { Banner } from '@/ui/Banner';
import { Button } from '@/ui/Button';
import { Card } from '@/ui/Card';
import { Choice } from '@/ui/Choice';
import { RatingChips } from '@/ui/RatingChips';
import { Screen } from '@/ui/Screen';
import { TextField } from '@/ui/TextField';
import { MIN_TOUCH, radius, usePalette } from '@/ui/theme';

import { useSetTopicStatus, useSyllabus } from './hooks';

const pct = (v: number | null) => (v === null ? '—' : `${Math.round(v)}%`);

/** The official syllabus with the student's progress. Tap a topic to change its status. */
export function SyllabusScreen() {
  const boot = useBootstrap();
  const [subjectId, setSubjectId] = useState<number | null>(null);
  const syllabus = useSyllabus(subjectId);
  const [openUnit, setOpenUnit] = useState<number | null>(null);
  const [openTopic, setOpenTopic] = useState<number | null>(null);
  const [savedName, setSavedName] = useState<string | null>(null);

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
      <Choice
        label="Subject"
        testID="syl-subject"
        options={boot.data.subjects.map((s) => ({ value: s.id, label: s.name }))}
        value={subjectId}
        onChange={(v) => {
          setSubjectId(v);
          setOpenUnit(null);
          setOpenTopic(null);
          setSavedName(null);
        }}
      />
      {subjectId === null ? (
        <AppText variant="small" tone="muted">
          Choose a subject to see its syllabus.
        </AppText>
      ) : syllabus.isPending ? (
        <ActivityIndicator accessibilityLabel="Loading syllabus" style={styles.loading} />
      ) : !syllabus.data ? (
        <>
          <Banner tone="error" title="Cannot load the syllabus">
            {errorMessage(syllabus.error)}
          </Banner>
          <Button title="Try again" onPress={() => void syllabus.refetch()} />
        </>
      ) : (
        <>
          <StatsCard title={syllabus.data.subject.name} stats={syllabus.data.stats} />
          {syllabus.data.units.length === 0 ? (
            <Banner tone="info" title="No syllabus loaded" testID="syl-empty">
              This subject has no syllabus topics on the server yet.
            </Banner>
          ) : null}
          {savedName ? (
            <Banner tone="success" testID="topic-saved">
              {`${savedName} is updated.`}
            </Banner>
          ) : null}
          {syllabus.data.units.map((u) => (
            <UnitBlock
              key={u.id}
              unit={u}
              open={openUnit === u.id}
              onToggle={() => setOpenUnit(openUnit === u.id ? null : u.id)}
              boot={boot.data}
              openTopic={openTopic}
              onOpenTopic={(id) => {
                setOpenTopic(id);
                setSavedName(null);
              }}
              onSaved={(name) => {
                setOpenTopic(null);
                setSavedName(name);
              }}
            />
          ))}
        </>
      )}
    </Screen>
  );
}

function StatsCard({ title, stats }: { title: string; stats: SyllabusStats }) {
  return (
    <Card title={title}>
      <AppText testID="syl-stats">
        {`${stats.total} topics. Theory done: ${stats.theory_completed} (${pct(stats.theory_pct)}). Practised with questions: ${stats.practised}. Mastered: ${stats.mastered} (${pct(stats.mastered_pct)}). Needs revision: ${stats.revision}.`}
      </AppText>
    </Card>
  );
}

function UnitBlock({
  unit, open, onToggle, boot, openTopic, onOpenTopic, onSaved,
}: {
  unit: SyllabusUnit;
  open: boolean;
  onToggle: () => void;
  boot: Bootstrap;
  openTopic: number | null;
  onOpenTopic: (id: number | null) => void;
  onSaved: (name: string) => void;
}) {
  const p = usePalette();
  return (
    <View style={[styles.unit, { backgroundColor: p.surface, borderColor: p.border }]}>
      <Pressable
        onPress={onToggle}
        testID={`unit-${unit.id}`}
        accessibilityRole="button"
        accessibilityState={{ expanded: open }}
        accessibilityLabel={`${unit.reference ? unit.reference + ' ' : ''}${unit.name}, ${unit.stats.theory_completed} of ${unit.stats.total} theory done`}
        style={styles.unitHead}
      >
        <View style={styles.flex}>
          <AppText variant="heading">{`${unit.reference ? unit.reference + '  ' : ''}${unit.name}`}</AppText>
          <AppText variant="small" tone="muted">
            {`Theory ${unit.stats.theory_completed} of ${unit.stats.total}  ·  Mastered ${unit.stats.mastered}`}
          </AppText>
        </View>
        <AppText variant="heading" tone="muted">
          {open ? '˅' : '›'}
        </AppText>
      </Pressable>
      {open
        ? unit.topics.map((t) => (
            <View key={t.id}>
              <TopicRow topic={t} boot={boot} open={openTopic === t.id} onOpen={onOpenTopic} onSaved={onSaved} />
              {(t.subtopics ?? []).map((c) => (
                <View key={c.id} style={styles.sub}>
                  <TopicRow topic={c} boot={boot} open={openTopic === c.id} onOpen={onOpenTopic} onSaved={onSaved} />
                </View>
              ))}
            </View>
          ))
        : null}
    </View>
  );
}

function TopicRow({ topic, boot, open, onOpen, onSaved }: { topic: SyllabusTopic; boot: Bootstrap; open: boolean; onOpen: (id: number | null) => void; onSaved: (name: string) => void }) {
  const p = usePalette();
  return (
    <View style={[styles.topic, { borderTopColor: p.border }]}>
      <Pressable
        onPress={() => onOpen(open ? null : topic.id)}
        testID={`topic-${topic.id}`}
        accessibilityRole="button"
        accessibilityLabel={`${topic.name}, ${topic.status_label}`}
        accessibilityState={{ expanded: open }}
        style={styles.topicHead}
      >
        <View style={styles.flex}>
          <AppText>{`${topic.reference ? topic.reference + '  ' : ''}${topic.name}`}</AppText>
          <AppText variant="small" tone="muted">
            {`${topic.status_label}${topic.practised ? '  ·  practised' : ''}${topic.confidence ? `  ·  confidence ${topic.confidence}/5` : ''}`}
          </AppText>
        </View>
      </Pressable>
      {open ? <TopicEditor topic={topic} boot={boot} onSaved={() => onSaved(topic.name)} /> : null}
    </View>
  );
}

function TopicEditor({ topic, boot, onSaved }: { topic: SyllabusTopic; boot: Bootstrap; onSaved: () => void }) {
  const save = useSetTopicStatus();
  const [status, setStatus] = useState<string | null>(topic.status);
  const [confidence, setConfidence] = useState<number | null>(topic.confidence);
  const [note, setNote] = useState('');

  return (
    <View style={styles.editor}>
      {topic.theory_completed_on ? (
        <AppText variant="small" tone="muted">
          {`Theory completed on ${formatLongDate(topic.theory_completed_on)}.`}
        </AppText>
      ) : null}
      <Choice
        label="Status"
        testID="topic-status"
        options={boot.options.topic_statuses.map((o) => ({ value: o.key, label: o.label }))}
        value={status}
        onChange={(v) => {
          save.reset();
          setStatus(v);
        }}
      />
      <AppText variant="small" tone="muted" style={styles.gap}>
        {`Mastered needs an independent attempt of ${boot.rules.mastery_pct}% or more on a question linked to this topic. The server decides.`}
      </AppText>
      <AppText variant="label" style={styles.gap}>
        Confidence (optional)
      </AppText>
      <RatingChips label="Topic confidence" value={confidence} onChange={setConfidence} />
      <TextField label="Note (optional)" value={note} onChangeText={setNote} maxLength={255} testID="topic-note" />
      {save.error ? (
        <Banner tone="error" title="Not changed" testID="topic-error">
          {errorMessage(save.error)}
        </Banner>
      ) : null}
      <Button
        title="Save status"
        testID="topic-save"
        loading={save.isPending}
        disabled={status === null}
        onPress={() => status && save.mutate({ id: topic.id, input: { status, confidence, note: note.trim() === '' ? null : note.trim() } }, { onSuccess: onSaved })}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  loading: { marginVertical: 24 },
  flex: { flex: 1 },
  gap: { marginBottom: 8 },
  unit: { borderWidth: 1, borderRadius: radius.md, marginBottom: 12, overflow: 'hidden' },
  unitHead: { flexDirection: 'row', alignItems: 'center', minHeight: MIN_TOUCH + 8, padding: 14 },
  topic: { borderTopWidth: 1, paddingHorizontal: 14 },
  topicHead: { minHeight: MIN_TOUCH, justifyContent: 'center', paddingVertical: 8 },
  sub: { paddingLeft: 14 },
  editor: { paddingBottom: 12 },
});
