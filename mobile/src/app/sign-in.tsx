import { useState } from 'react';

import { ApiError } from '@/api/errors';
import { useAuth } from '@/auth/AuthContext';
import { ALLOW_HTTP } from '@/config';
import { AppText } from '@/ui/AppText';
import { Banner } from '@/ui/Banner';
import { Button } from '@/ui/Button';
import { Screen } from '@/ui/Screen';
import { TextField } from '@/ui/TextField';

export default function SignInScreen() {
  const { signIn, serverUrl, notice } = useAuth();
  const [server, setServer] = useState(serverUrl);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<{ server?: string; email?: string; password?: string; general?: string }>({});

  const submit = async () => {
    const next: typeof errors = {};
    if (server.trim() === '') next.server = 'Enter the server address.';
    if (email.trim() === '') next.email = 'Enter your e-mail address.';
    if (password === '') next.password = 'Enter your password.';
    setErrors(next);
    if (Object.keys(next).length > 0) return;

    setBusy(true);
    try {
      await signIn(server, email, password);
      // On success the navigator moves to the app by itself (the sign-in screen is only reachable while signed out).
    } catch (e) {
      if (e instanceof ApiError && e.kind === 'validation') {
        setErrors({ server: e.fieldMessage('server'), email: e.fieldMessage('email'), password: e.fieldMessage('password'), general: e.fieldMessage('server') || e.fieldMessage('email') ? undefined : e.message });
      } else {
        setErrors({ general: e instanceof Error ? e.message : 'Could not sign in. Please try again.' });
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen edges={['top', 'bottom', 'left', 'right']}>
      <AppText variant="title" accessibilityRole="header" style={{ marginTop: 24, marginBottom: 4 }}>
        A/L Study Tracker
      </AppText>
      <AppText tone="muted" style={{ marginBottom: 20 }}>
        Sign in with the same e-mail and password you use on the website.
      </AppText>

      {notice ? (
        <Banner tone="info" title="Signed out" testID="signin-notice">
          {notice}
        </Banner>
      ) : null}
      {errors.general ? (
        <Banner tone="error" title="Could not sign in" testID="signin-error">
          {errors.general}
        </Banner>
      ) : null}

      <TextField
        label="Server address"
        value={server}
        onChangeText={setServer}
        error={errors.server}
        hint={ALLOW_HTTP ? 'For example https://tracker.example.com (development builds also accept http://192.168.x.x:8080)' : 'For example https://tracker.example.com'}
        autoCapitalize="none"
        autoCorrect={false}
        keyboardType="url"
        textContentType="URL"
        testID="server"
      />
      <TextField
        label="E-mail"
        value={email}
        onChangeText={setEmail}
        error={errors.email}
        autoCapitalize="none"
        autoCorrect={false}
        keyboardType="email-address"
        autoComplete="email"
        textContentType="username"
        testID="email"
      />
      <TextField
        label="Password"
        value={password}
        onChangeText={setPassword}
        error={errors.password}
        revealable
        autoCapitalize="none"
        autoCorrect={false}
        autoComplete="current-password"
        textContentType="password"
        onSubmitEditing={() => void submit()}
        testID="password"
      />

      <Button title="Sign in" onPress={() => void submit()} loading={busy} testID="sign-in" />
      <AppText variant="small" tone="muted" style={{ marginTop: 16 }}>
        Forgot the password? It can only be reset on the server.
      </AppText>
    </Screen>
  );
}
