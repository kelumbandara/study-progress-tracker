import { useState } from 'react';
import { Pressable, StyleSheet, TextInput, type TextInputProps, View } from 'react-native';

import { AppText } from './AppText';
import { MIN_TOUCH, radius, usePalette } from './theme';

interface Props extends Omit<TextInputProps, 'style'> {
  label: string;
  error?: string | undefined;
  hint?: string | undefined;
  /** Adds a "Show / Hide" control for passwords. */
  revealable?: boolean;
}

export function TextField({ label, error, hint, revealable = false, secureTextEntry, multiline, ...rest }: Props) {
  const p = usePalette();
  const [revealed, setRevealed] = useState(false);
  const hidden = revealable ? !revealed : secureTextEntry;

  return (
    <View style={styles.wrap}>
      <AppText variant="label" style={styles.label}>
        {label}
      </AppText>
      <View style={styles.row}>
        <TextInput
          {...rest}
          multiline={multiline}
          secureTextEntry={hidden}
          accessibilityLabel={label}
          placeholderTextColor={p.muted}
          style={[
            styles.input,
            multiline ? styles.multiline : null,
            { color: p.text, backgroundColor: p.inputBackground, borderColor: error ? p.danger : p.border },
          ]}
        />
        {revealable ? (
          <Pressable onPress={() => setRevealed((v) => !v)} accessibilityRole="button" accessibilityLabel={revealed ? 'Hide password' : 'Show password'} style={styles.reveal}>
            <AppText variant="label" style={{ color: p.primary }}>
              {revealed ? 'Hide' : 'Show'}
            </AppText>
          </Pressable>
        ) : null}
      </View>
      {error ? (
        <AppText variant="small" tone="danger" style={styles.msg} accessibilityLiveRegion="polite">
          {'✖ '}
          {error}
        </AppText>
      ) : hint ? (
        <AppText variant="small" tone="muted" style={styles.msg}>
          {hint}
        </AppText>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { marginBottom: 14 },
  label: { marginBottom: 6 },
  row: { flexDirection: 'row', alignItems: 'center' },
  input: { flex: 1, minHeight: MIN_TOUCH, borderWidth: 1.5, borderRadius: radius.sm, paddingHorizontal: 12, fontSize: 16 },
  multiline: { minHeight: 88, paddingTop: 12, textAlignVertical: 'top' },
  reveal: { minHeight: MIN_TOUCH, minWidth: 56, alignItems: 'center', justifyContent: 'center', marginLeft: 4 },
  msg: { marginTop: 4 },
});
