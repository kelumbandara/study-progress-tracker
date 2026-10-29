import { Stack } from 'expo-router';

import { usePalette } from '@/ui/theme';

export default function PracticeStack() {
  const p = usePalette();
  return (
    <Stack
      screenOptions={{
        headerStyle: { backgroundColor: p.surface },
        headerTintColor: p.text,
        headerTitleStyle: { fontWeight: '700' },
        contentStyle: { backgroundColor: p.background },
      }}
    >
      <Stack.Screen name="index" options={{ title: 'Practice' }} />
      <Stack.Screen name="question" options={{ title: 'Record a question' }} />
      <Stack.Screen name="theory" options={{ title: 'Theory study' }} />
      <Stack.Screen name="mistakes" options={{ title: 'Mistakes to review' }} />
    </Stack>
  );
}
