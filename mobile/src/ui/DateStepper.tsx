import { StyleSheet, View } from 'react-native';

import { addDays, formatLongDate } from '@/lib/dates';

import { AppText } from './AppText';
import { Button } from './Button';

interface Props {
  label: string;
  date: string;
  /** The server's today; the date cannot go past it. */
  today: string;
  onChange: (date: string) => void;
  testID: string;
}

/** Previous / next day with the date between, never later than the server's today. */
export function DateStepper({ label, date, today, onChange, testID }: Props) {
  return (
    <View style={styles.wrap}>
      <AppText variant="label" style={styles.label}>
        {label}
      </AppText>
      <View style={styles.row}>
        <Button compact variant="secondary" title="‹" accessibilityLabel={`${label}: previous day`} testID={`${testID}-prev`} onPress={() => onChange(addDays(date, -1))} />
        <View style={styles.middle}>
          <AppText variant="body" testID={`${testID}-label`} style={styles.center}>
            {formatLongDate(date)}
          </AppText>
          <AppText variant="small" tone="muted">
            {date === today ? 'Today' : ' '}
          </AppText>
        </View>
        <Button compact variant="secondary" title="›" accessibilityLabel={`${label}: next day`} testID={`${testID}-next`} disabled={date >= today} onPress={() => onChange(addDays(date, 1))} />
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { marginBottom: 14 },
  label: { marginBottom: 6 },
  row: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  middle: { flex: 1, alignItems: 'center', paddingHorizontal: 8 },
  center: { textAlign: 'center' },
});
