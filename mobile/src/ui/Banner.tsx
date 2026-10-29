import { StyleSheet, View } from 'react-native';

import { AppText } from './AppText';
import { radius, usePalette } from './theme';

type Tone = 'info' | 'success' | 'warning' | 'error';

interface Props {
  tone: Tone;
  title?: string;
  children: string;
  testID?: string;
}

const ICON: Record<Tone, string> = { info: 'ℹ', success: '✓', warning: '⚠', error: '✖' };
const WORD: Record<Tone, string> = { info: 'Note', success: 'Saved', warning: 'Warning', error: 'Problem' };

/** Colour is never the only signal: every banner also has an icon and a word. */
export function Banner({ tone, title, children, testID }: Props) {
  const p = usePalette();
  const fg = { info: p.info, success: p.success, warning: p.warning, error: p.danger }[tone];
  const bg = { info: p.infoBg, success: p.successBg, warning: p.warningBg, error: p.dangerBg }[tone];

  return (
    <View
      testID={testID}
      accessibilityRole={tone === 'error' ? 'alert' : undefined}
      accessibilityLiveRegion={tone === 'error' ? 'assertive' : 'polite'}
      style={[styles.box, { backgroundColor: bg, borderColor: fg }]}
    >
      <AppText variant="label" style={{ color: fg }}>
        {`${ICON[tone]} ${title ?? WORD[tone]}`}
      </AppText>
      <AppText variant="small" style={styles.text}>
        {children}
      </AppText>
    </View>
  );
}

const styles = StyleSheet.create({
  box: { borderWidth: 1, borderLeftWidth: 5, borderRadius: radius.sm, padding: 12, marginBottom: 12 },
  text: { marginTop: 2 },
});
