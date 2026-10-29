import type { PropsWithChildren } from 'react';
import { StyleSheet, View } from 'react-native';

import { AppText } from './AppText';
import { radius, usePalette } from './theme';

export function Card({ title, children }: PropsWithChildren<{ title?: string }>) {
  const p = usePalette();
  return (
    <View style={[styles.card, { backgroundColor: p.surface, borderColor: p.border }]}>
      {title ? (
        <AppText variant="heading" style={styles.title} accessibilityRole="header">
          {title}
        </AppText>
      ) : null}
      {children}
    </View>
  );
}

const styles = StyleSheet.create({
  card: { borderWidth: 1, borderRadius: radius.md, padding: 16, marginBottom: 14 },
  title: { marginBottom: 10 },
});
