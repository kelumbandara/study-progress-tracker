import type { PropsWithChildren, ReactElement } from 'react';
import { ScrollView, StyleSheet, View } from 'react-native';
import { type Edge, SafeAreaView } from 'react-native-safe-area-context';

import { MAX_CONTENT_WIDTH, usePalette } from './theme';

interface Props {
  /** Pull-to-refresh control, if the screen has one. */
  refreshControl?: ReactElement<any> | undefined;
  /** Edges that need padding for the status bar / gesture bar. Tab screens already have a header, so default to the sides only. */
  edges?: Edge[];
}

/** Scrolling page with comfortable margins; on tablets the content is centred in a readable column. */
export function Screen({ children, refreshControl, edges = ['left', 'right'] }: PropsWithChildren<Props>) {
  const p = usePalette();
  return (
    <SafeAreaView edges={edges} style={[styles.safe, { backgroundColor: p.background }]}>
      <ScrollView
        refreshControl={refreshControl}
        keyboardShouldPersistTaps="handled"
        contentContainerStyle={styles.content}
        automaticallyAdjustKeyboardInsets
      >
        <View style={styles.column}>{children}</View>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  content: { padding: 16, paddingBottom: 40, alignItems: 'center' },
  column: { width: '100%', maxWidth: MAX_CONTENT_WIDTH },
});
