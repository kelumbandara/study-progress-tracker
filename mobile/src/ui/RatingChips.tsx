import { Pressable, StyleSheet, View } from 'react-native';

import { AppText } from './AppText';
import { MIN_TOUCH, radius, usePalette } from './theme';

interface Props {
  value: number | null;
  onChange: (value: number | null) => void;
  label: string;
}

/** 1 to 5, tap the selected number again to clear it. */
export function RatingChips({ value, onChange, label }: Props) {
  const p = usePalette();
  return (
    <View accessibilityRole="radiogroup" accessibilityLabel={label} style={styles.row}>
      {[1, 2, 3, 4, 5].map((n) => {
        const selected = value === n;
        return (
          <Pressable
            key={n}
            onPress={() => onChange(selected ? null : n)}
            accessibilityRole="radio"
            accessibilityLabel={`${label}: ${n}`}
            accessibilityState={{ selected }}
            aria-checked={selected}
            testID={`rating-${n}`}
            style={[styles.chip, { borderColor: selected ? p.primary : p.border, backgroundColor: selected ? p.primary : p.surface }]}
          >
            <AppText variant="label" style={{ color: selected ? p.onPrimary : p.text }}>
              {String(n)}
            </AppText>
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', gap: 8 },
  chip: { minWidth: MIN_TOUCH, minHeight: MIN_TOUCH, borderWidth: 1.5, borderRadius: radius.sm, alignItems: 'center', justifyContent: 'center' },
});
