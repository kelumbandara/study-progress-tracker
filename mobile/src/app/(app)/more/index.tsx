import Constants from 'expo-constants';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { errorMessage } from '@/api/errors';
import { useAuth } from '@/auth/AuthContext';
import { AppText } from '@/ui/AppText';
import { Banner } from '@/ui/Banner';
import { Button } from '@/ui/Button';
import { Card } from '@/ui/Card';
import { confirmAction } from '@/ui/confirm';
import { Screen } from '@/ui/Screen';

import { MoreLink } from '@/features/more/MoreLink';

export default function MoreScreen() {
  const { user, serverUrl, api, signOut } = useAuth();
  const [check, setCheck] = useState<{ tone: 'success' | 'error'; text: string } | null>(null);
  const [checking, setChecking] = useState(false);
  const [signingOut, setSigningOut] = useState(false);

  const testConnection = async () => {
    setChecking(true);
    setCheck(null);
    try {
      const me = await api.me();
      setCheck({ tone: 'success', text: `Connected to the server as ${me.user.email}.` });
    } catch (e) {
      setCheck({ tone: 'error', text: errorMessage(e) });
    } finally {
      setChecking(false);
    }
  };

  const onSignOut = async () => {
    const yes = await confirmAction('Sign out?', 'You will need your e-mail and password to sign in again on this phone.', 'Sign out');
    if (!yes) return;
    setSigningOut(true);
    await signOut();
  };

  return (
    <Screen>
      <MoreLink href="/more/syllabus" title="Syllabus" text="Every topic and where you stand; change a topic's status." testID="open-syllabus" />
      <MoreLink href="/more/reports" title="Reports" text="Weekly and monthly summaries of hours, questions and revision." testID="open-reports" />
      <MoreLink href="/more/phone-use" title="Phone use" text="Share how long you use each app (minutes only) with your own dashboard. You can switch it off and erase it any time." testID="open-phone-use" />
      <MoreLink href="/more/history" title="History and corrections" text="Look back at earlier attempts and theory study, and correct a mistake." testID="open-history" />

      <Card title="Account">
        <Row label="Name" value={user?.name ?? '—'} />
        <Row label="E-mail" value={user?.email ?? '—'} testID="account-email" />
        <Row label="Server" value={serverUrl || '—'} testID="account-server" />
      </Card>

      <Card title="Connection">
        {check ? (
          <Banner tone={check.tone} title={check.tone === 'success' ? 'Connected' : 'Problem'} testID="connection-result">
            {check.text}
          </Banner>
        ) : null}
        <Button title="Test connection" variant="secondary" loading={checking} onPress={() => void testConnection()} testID="test-connection" />
      </Card>

      <Card title="About">
        <Row label="App version" value={Constants.expoConfig?.version ?? '—'} />
        <AppText variant="small" tone="muted" style={styles.about}>
          Your records are stored on the server, not on this phone. Signing out removes only the sign-in from this phone.
        </AppText>
      </Card>

      <Button title="Sign out" variant="danger" loading={signingOut} onPress={() => void onSignOut()} testID="sign-out" />
    </Screen>
  );
}

function Row({ label, value, testID }: { label: string; value: string; testID?: string }) {
  return (
    <View style={styles.row}>
      <AppText variant="small" tone="muted">
        {label}
      </AppText>
      <AppText variant="body" testID={testID} selectable>
        {value}
      </AppText>
    </View>
  );
}

const styles = StyleSheet.create({
  row: { marginBottom: 10 },
  about: { marginTop: 4 },
});
