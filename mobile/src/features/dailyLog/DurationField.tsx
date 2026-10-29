import { StyleSheet, TextInput, View } from 'react-native';

import { digitsOnly } from '@/lib/time';
import { AppText } from '@/ui/AppText';
import { MIN_TOUCH, radius, usePalette } from '@/ui/theme';

interface Props {
  label: string;
  hint: string;
  hours: string;
  mins: string;
  onChange: (next: { hours: string; mins: string }) => void;
  error?: string | undefined;
  testID: string;
}

/** Hours + minutes boxes for one category. Only digits can be typed. */
export function DurationField({ label, hint, hours, mins, onChange, error, testID }: Props) {
  const p = usePalette();
  const box = [styles.input, { color: p.text, backgroundColor: p.inputBackground, borderColor: error ? p.danger : p.border }];

  return (
    <View style={styles.wrap}>
      <AppText variant="label">{label}</AppText>
      <AppText variant="small" tone="muted" style={styles.hint}>
        {hint}
      </AppText>
      <View style={styles.row}>
        <TextInput
          testID={`${testID}-hours`}
          accessibilityLabel={`${label} hours`}
          value={hours}
          onChangeText={(t) => onChange({ hours: digitsOnly(t, 2), mins })}
          keyboardType="number-pad"
          inputMode="numeric"
          maxLength={2}
          onFocus={() => hours === '0' && onChange({ hours: '', mins })}
          placeholder="0"
          placeholderTextColor={p.muted}
          style={box}
        />
        <AppText variant="small" tone="muted" style={styles.unit}>
          h
        </AppText>
        <TextInput
          testID={`${testID}-mins`}
          accessibilityLabel={`${label} minutes`}
          value={mins}
          onChangeText={(t) => onChange({ hours, mins: digitsOnly(t, 2) })}
          keyboardType="number-pad"
          inputMode="numeric"
          maxLength={2}
          onFocus={() => mins === '0' && onChange({ hours, mins: '' })}
          placeholder="0"
          placeholderTextColor={p.muted}
          style={box}
        />
        <AppText variant="small" tone="muted" style={styles.unit}>
          min
        </AppText>
      </View>
      {error ? (
        <AppText variant="small" tone="danger" style={styles.error} accessibilityLiveRegion="polite">
          {'✖ '}
          {error}
        </AppText>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { marginBottom: 14 },
  hint: { marginBottom: 6 },
  row: { flexDirection: 'row', alignItems: 'center' },
  input: { width: 72, minHeight: MIN_TOUCH, borderWidth: 1.5, borderRadius: radius.sm, textAlign: 'center', fontSize: 18 },
  unit: { marginHorizontal: 8, minWidth: 28 },
  error: { marginTop: 4 },
});
