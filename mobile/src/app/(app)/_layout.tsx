import { Tabs } from 'expo-router';
import { type ColorValue, Text } from 'react-native';

import { useAutoPhoneUseSync } from '@/features/phoneUse/usePhoneUse';
import { usePalette } from '@/ui/theme';

function icon(glyph: string) {
  // A plain glyph keeps the app free of icon fonts; every tab also has a text label.
  const TabIcon = ({ color }: { color: ColorValue }) => <Text style={{ color, fontSize: 20 }}>{glyph}</Text>;
  TabIcon.displayName = `TabIcon(${glyph})`;
  return TabIcon;
}

export default function AppTabs() {
  const p = usePalette();
  useAutoPhoneUseSync(); // sends phone use in the background, only when the student has switched sharing on
  return (
    <Tabs
      screenOptions={{
        tabBarActiveTintColor: p.primary,
        tabBarInactiveTintColor: p.muted,
        tabBarStyle: { backgroundColor: p.surface, borderTopColor: p.border },
        tabBarLabelStyle: { fontSize: 12 },
        headerStyle: { backgroundColor: p.surface },
        headerTintColor: p.text,
        headerTitleStyle: { fontWeight: '700' },
      }}
    >
      <Tabs.Screen name="index" options={{ title: 'Today', tabBarIcon: icon('✎'), tabBarButtonTestID: 'tab-today' }} />
      <Tabs.Screen name="dashboard" options={{ title: 'Dashboard', tabBarIcon: icon('▦'), tabBarButtonTestID: 'tab-dashboard' }} />
      <Tabs.Screen name="practice" options={{ title: 'Practice', headerShown: false, tabBarIcon: icon('✔'), tabBarButtonTestID: 'tab-practice' }} />
      <Tabs.Screen name="more" options={{ title: 'More', headerShown: false, tabBarIcon: icon('☰'), tabBarButtonTestID: 'tab-more' }} />
    </Tabs>
  );
}
