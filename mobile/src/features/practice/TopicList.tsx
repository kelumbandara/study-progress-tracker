import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';

import { errorMessage } from '@/api/errors';
import type { TopicChoice } from '@/api/types';
import { AppText } from '@/ui/AppText';
import { Banner } from '@/ui/Banner';
import { Button } from '@/ui/Button';
import { TextField } from '@/ui/TextField';
import { MIN_TOUCH, radius, usePalette } from '@/ui/theme';

import { useTopics } from './hooks';

const SHOWN = 30;

interface Props {
  subjectId: number | null;
  /** Ids of the chosen topics. */
  selected: number[];
  onChange: (ids: number[]) => void;
  /** One topic only (theory study) or any number (a question can cover several). */
  multiple: boolean;
  label: string;
  error?: string | undefined;
  testID: string;
}

/** Search-and-pick list of one subject's syllabus topics. The topics come from the server; nothing is typed in or invented here. */
export function TopicList({ subjectId, selected, onChange, multiple, label, error, testID }: Props) {
  const p = usePalette();
  const topics = useTopics(subjectId);
  const [search, setSearch] = useState('');

  if (subjectId === null) {
    return (
      <AppText variant="small" tone="muted" style={styles.gap}>
        Choose a subject first to see its topics.
      </AppText>
    );
  }
  if (topics.isPending) return <ActivityIndicator accessibilityLabel="Loading topics" style={styles.gap} />;
  if (topics.isError) {
    return (
      <View style={styles.gap}>
        <Banner tone="error" title="Cannot load the topics">
          {errorMessage(topics.error)}
        </Banner>
        <Button title="Try again" onPress={() => void topics.refetch()} />
      </View>
    );
  }

  const all = topics.data.data;
  const needle = search.trim().toLowerCase();
  const matches = needle === '' ? all : all.filter((t) => t.full_name.toLowerCase().includes(needle) || t.unit.toLowerCase().includes(needle));
  // A chosen topic stays visible even when the search no longer matches it, so nothing is ticked out of sight.
  const chosen = all.filter((t) => selected.includes(t.id));
  const rows: TopicChoice[] = [...chosen, ...matches.filter((t) => !selected.includes(t.id)).slice(0, SHOWN)];

  const toggle = (id: number) => {
    if (!multiple) return onChange(selected[0] === id ? [] : [id]);
    onChange(selected.includes(id) ? selected.filter((x) => x !== id) : [...selected, id]);
  };

  return (
    <View style={styles.gap}>
      <AppText variant="label" style={styles.label}>
        {label}
      </AppText>
      <TextField label="Search topics" value={search} onChangeText={setSearch} placeholder="Type part of a topic name" autoCapitalize="none" testID={`${testID}-search`} />
      <View accessibilityRole={multiple ? undefined : 'radiogroup'} accessibilityLabel={label}>
        {rows.map((t) => {
          const on = selected.includes(t.id);
          return (
            <Pressable
              key={t.id}
              onPress={() => toggle(t.id)}
              accessibilityRole={multiple ? 'checkbox' : 'radio'}
              accessibilityLabel={t.full_name}
              accessibilityState={multiple ? { checked: on } : { selected: on }}
              aria-checked={on}
              testID={`${testID}-${t.id}`}
              style={[styles.row, { borderColor: on ? p.primary : p.border, backgroundColor: on ? p.infoBg : p.surface }]}
            >
              <AppText variant="label" style={styles.mark}>
                {on ? '✓' : '○'}
              </AppText>
              <View style={styles.rowText}>
                <AppText variant="small">{t.full_name}</AppText>
                <AppText variant="small" tone="muted">
                  {t.unit}
                </AppText>
              </View>
            </Pressable>
          );
        })}
      </View>
      {matches.length === 0 ? (
        <AppText variant="small" tone="muted">
          No topic matches “{search.trim()}”.
        </AppText>
      ) : matches.length > rows.length - chosen.length ? (
        <AppText variant="small" tone="muted">
          Showing {rows.length - chosen.length} of {matches.length} topics. Type to narrow the list.
        </AppText>
      ) : null}
      {error ? (
        <AppText variant="small" tone="danger" accessibilityLiveRegion="polite">
          {'✖ '}
          {error}
        </AppText>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  gap: { marginBottom: 14 },
  label: { marginBottom: 6 },
  row: { flexDirection: 'row', alignItems: 'center', minHeight: MIN_TOUCH, borderWidth: 1.5, borderRadius: radius.sm, paddingHorizontal: 12, paddingVertical: 8, marginBottom: 6 },
  mark: { width: 24 },
  rowText: { flex: 1 },
});
