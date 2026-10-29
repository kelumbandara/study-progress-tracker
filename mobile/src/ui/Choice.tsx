import { Pressable, StyleSheet, View } from 'react-native';

import { AppText } from './AppText';
import { MIN_TOUCH, radius, usePalette } from './theme';

export interface ChoiceOption<T extends string | number> {
  value: T;
  label: string;
}

interface Props<T extends string | number> {
  label: string;
  options: ChoiceOption<T>[];
  value: T | null;
  onChange: (value: T | null) => void;
  /** Tapping the chosen option again clears it (for optional choices). */
  clearable?: boolean;
  error?: string | undefined;
  testID?: string;
}

/** One choice from a short list, shown as wrapping buttons. The chosen one is marked by fill AND a tick, never colour alone. */
export function Choice<T extends string | number>({ label, options, value, onChange, clearable = false, error, testID }: Props<T>) {
  const p = usePalette();
  return (
    <View style={styles.wrap}>
      <AppText variant="label" style={styles.label}>
        {label}
      </AppText>
      <View accessibilityRole="radiogroup" accessibilityLabel={label} style={styles.row}>
        {options.map((o) => {
          const selected = value === o.value;
          return (
            <Pressable
              key={String(o.value)}
              onPress={() => onChange(selected && clearable ? null : o.value)}
              accessibilityRole="radio"
              accessibilityLabel={`${label}: ${o.label}`}
              accessibilityState={{ selected }}
              aria-checked={selected}
              testID={testID ? `${testID}-${o.value}` : undefined}
              style={[styles.chip, { borderColor: selected ? p.primary : p.border, backgroundColor: selected ? p.primary : p.surface }]}
            >
              <AppText variant="label" style={{ color: selected ? p.onPrimary : p.text }}>
                {selected ? `✓ ${o.label}` : o.label}
              </AppText>
            </Pressable>
          );
        })}
      </View>
      {error ? (
        <AppText variant="small" tone="danger" style={styles.msg} accessibilityLiveRegion="polite">
          {'✖ '}
          {error}
        </AppText>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { marginBottom: 14 },
  label: { marginBottom: 6 },
  row: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip: { minHeight: MIN_TOUCH, minWidth: MIN_TOUCH, borderWidth: 1.5, borderRadius: radius.sm, paddingHorizontal: 14, alignItems: 'center', justifyContent: 'center' },
  msg: { marginTop: 4 },
});
