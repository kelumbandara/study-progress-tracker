import { Stack } from 'expo-router';

import { usePalette } from '@/ui/theme';

export default function MoreStack() {
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
      <Stack.Screen name="index" options={{ title: 'More' }} />
      <Stack.Screen name="syllabus" options={{ title: 'Syllabus' }} />
      <Stack.Screen name="reports" options={{ title: 'Reports' }} />
      <Stack.Screen name="history" options={{ title: 'History and corrections' }} />
      <Stack.Screen name="phone-use" options={{ title: 'Phone use' }} />
    </Stack>
  );
}
