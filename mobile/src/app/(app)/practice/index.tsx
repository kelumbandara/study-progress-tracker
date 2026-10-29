import { type Href, useRouter } from 'expo-router';
import { Pressable, StyleSheet, View } from 'react-native';

import { AppText } from '@/ui/AppText';
import { Screen } from '@/ui/Screen';
import { MIN_TOUCH, radius, usePalette } from '@/ui/theme';

const ITEMS = [
  { href: '/practice/question', title: 'Record a question', text: 'An attempt at a past-paper question: marks, result, time, whether you did it alone.', id: 'open-question' },
  { href: '/practice/theory', title: 'Theory study', text: 'Time spent studying the theory of one topic.', id: 'open-theory' },
  { href: '/practice/mistakes', title: 'Mistakes to review', text: 'Questions that still need work, and a note when you review one.', id: 'open-mistakes' },
] as const;

export default function PracticeHub() {
  const router = useRouter();
  const p = usePalette();
  return (
    <Screen>
      {ITEMS.map((i) => (
        <Pressable
          key={i.id}
          testID={i.id}
          accessibilityRole="button"
          accessibilityLabel={i.title}
          onPress={() => router.push(i.href as Href)}
          style={({ pressed }) => [styles.item, { backgroundColor: p.surface, borderColor: p.border, opacity: pressed ? 0.85 : 1 }]}
        >
          <View style={styles.text}>
            <AppText variant="heading">{i.title}</AppText>
            <AppText variant="small" tone="muted">
              {i.text}
            </AppText>
          </View>
          <AppText variant="heading" tone="muted">
            ›
          </AppText>
        </Pressable>
      ))}
    </Screen>
  );
}

const styles = StyleSheet.create({
  item: { flexDirection: 'row', alignItems: 'center', minHeight: MIN_TOUCH + 24, borderWidth: 1, borderRadius: radius.md, padding: 16, marginBottom: 12 },
  text: { flex: 1, paddingRight: 8 },
});
