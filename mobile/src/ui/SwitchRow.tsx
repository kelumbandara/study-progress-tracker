import { StyleSheet, Switch, View } from 'react-native';

import { AppText } from './AppText';

interface Props {
  label: string;
  hint?: string;
  value: boolean;
  onChange: (value: boolean) => void;
  testID?: string;
}

export function SwitchRow({ label, hint, value, onChange, testID }: Props) {
  return (
    <View style={styles.row}>
      <View style={styles.text}>
        <AppText variant="label">{label}</AppText>
        {hint ? (
          <AppText variant="small" tone="muted">
            {hint}
          </AppText>
        ) : null}
      </View>
      <Switch testID={testID} accessibilityLabel={label} value={value} onValueChange={onChange} />
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', minHeight: 56 },
  text: { flex: 1, paddingRight: 12 },
});
