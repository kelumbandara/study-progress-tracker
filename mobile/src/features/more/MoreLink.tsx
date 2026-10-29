import { type Href, useRouter } from 'expo-router';
import { Pressable, StyleSheet, View } from 'react-native';

import { AppText } from '@/ui/AppText';
import { MIN_TOUCH, radius, usePalette } from '@/ui/theme';

/** One row that opens another screen. */
export function MoreLink({ href, title, text, testID }: { href: string; title: string; text: string; testID: string }) {
  const router = useRouter();
  const p = usePalette();
  return (
    <Pressable
      testID={testID}
      accessibilityRole="button"
      accessibilityLabel={title}
      onPress={() => router.push(href as Href)}
      style={({ pressed }) => [styles.item, { backgroundColor: p.surface, borderColor: p.border, opacity: pressed ? 0.85 : 1 }]}
    >
      <View style={styles.text}>
        <AppText variant="heading">{title}</AppText>
        <AppText variant="small" tone="muted">
          {text}
        </AppText>
      </View>
      <AppText variant="heading" tone="muted">
        ›
      </AppText>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  item: { flexDirection: 'row', alignItems: 'center', minHeight: MIN_TOUCH + 24, borderWidth: 1, borderRadius: radius.md, padding: 16, marginBottom: 12 },
  text: { flex: 1, paddingRight: 8 },
});
