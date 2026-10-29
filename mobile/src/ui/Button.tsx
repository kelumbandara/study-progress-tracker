import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';

import { AppText } from './AppText';
import { MIN_TOUCH, radius, usePalette } from './theme';

interface Props {
  title: string;
  onPress: () => void;
  variant?: 'primary' | 'secondary' | 'danger';
  loading?: boolean;
  disabled?: boolean;
  accessibilityLabel?: string;
  testID?: string;
  /** Small buttons (like the date arrows) still keep a 48 dp touch target. */
  compact?: boolean;
}

export function Button({ title, onPress, variant = 'primary', loading = false, disabled = false, accessibilityLabel, testID, compact = false }: Props) {
  const p = usePalette();
  const inactive = disabled || loading;
  const bg = variant === 'primary' ? p.primary : variant === 'danger' ? p.danger : 'transparent';
  const fg = variant === 'secondary' ? p.primary : variant === 'danger' ? '#ffffff' : p.onPrimary;

  return (
    <Pressable
      onPress={inactive ? undefined : onPress}
      disabled={inactive}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? title}
      accessibilityState={{ disabled: inactive, busy: loading }}
      aria-disabled={inactive}
      aria-busy={loading}
      testID={testID}
      style={({ pressed }) => [
        styles.base,
        compact ? styles.compact : null,
        { backgroundColor: bg, borderColor: variant === 'secondary' ? p.primary : bg, opacity: inactive ? 0.5 : pressed ? 0.85 : 1 },
      ]}
    >
      <View style={styles.row}>
        {loading ? <ActivityIndicator color={fg} style={styles.spinner} /> : null}
        <AppText variant="label" style={{ color: fg, fontSize: 16 }}>
          {title}
        </AppText>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  base: { minHeight: MIN_TOUCH, borderRadius: radius.sm, borderWidth: 1.5, paddingHorizontal: 18, alignItems: 'center', justifyContent: 'center' },
  compact: { paddingHorizontal: 12, minWidth: MIN_TOUCH },
  row: { flexDirection: 'row', alignItems: 'center' },
  spinner: { marginRight: 8 },
});
