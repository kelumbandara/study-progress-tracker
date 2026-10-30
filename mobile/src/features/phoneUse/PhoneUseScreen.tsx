import { useCallback, useEffect, useState } from 'react';
import { AppState, StyleSheet, View } from 'react-native';

import { errorMessage } from '@/api/errors';
import type { AppUse } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { useBootstrap } from '@/features/dailyLog/hooks';
import { formatDuration } from '@/lib/time';
import { KEYS, storage } from '@/storage/storage';
import { AppText } from '@/ui/AppText';
import { Banner } from '@/ui/Banner';
import { Button } from '@/ui/Button';
import { Card } from '@/ui/Card';
import { confirmAction } from '@/ui/confirm';
import { Screen } from '@/ui/Screen';
import { SwitchRow } from '@/ui/SwitchRow';

import { getDay, isAccessGranted, openAccessSettings, phoneUsageSupported } from '../../../modules/phone-usage';
import { setSharing, useSharingFlag, usePhoneUseSender } from './usePhoneUse';

/** Shows what the phone would share and lets the student switch it on, send it now, or stop and erase everything. */
export function PhoneUseScreen() {
  const { api } = useAuth();
  const boot = useBootstrap();
  const { sharing } = useSharingFlag();
  const sender = usePhoneUseSender();
  const [granted, setGranted] = useState(() => isAccessGranted());
  const [today, setToday] = useState<AppUse[] | null>(null);
  const [erasing, setErasing] = useState(false);
  const [erased, setErased] = useState<number | null>(null);
  const [problem, setProblem] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    const ok = isAccessGranted();
    setGranted(ok);
    if (ok && boot.data) {
      try {
        setToday(await getDay(boot.data.today, boot.data.timezone));
      } catch (e) {
        setProblem(errorMessage(e));
      }
    } else {
      setToday(null);
    }
  }, [boot.data]);

  // Re-check when the student comes back from Android's settings page.
  useEffect(() => {
    void refresh();
    const sub = AppState.addEventListener('change', (s) => {
      if (s === 'active') void refresh();
    });
    return () => sub.remove();
  }, [refresh]);

  if (!phoneUsageSupported) {
    return (
      <Screen>
        <Banner tone="info" title="Not available here" testID="pu-unsupported">
          Phone use can only be read by the installed ezexam app on an Android phone. It does not work in Expo Go, in a web browser, or on other systems.
        </Banner>
      </Screen>
    );
  }

  const totalToday = (today ?? []).reduce((sum, a) => sum + a.minutes, 0);

  const sendNow = async () => {
    setProblem(null);
    await sender.run(true);
    void refresh();
  };

  const stopAndErase = async () => {
    const yes = await confirmAction(
      'Stop sharing and erase?',
      'Sharing stops and everything the phone has already sent is deleted from your dashboard. You can switch sharing on again later.',
      'Stop and erase',
    );
    if (!yes) return;
    setErasing(true);
    setProblem(null);
    try {
      const r = await api.erasePhoneUsage();
      await setSharing(false);
      await storage.remove(KEYS.phoneLast);
      setErased(r.deleted_rows);
    } catch (e) {
      setProblem(errorMessage(e));
    } finally {
      setErasing(false);
    }
  };

  return (
    <Screen>
      <Card title="What is shared">
        <AppText testID="pu-what">
          Only the name of each app and how many minutes it was open each day, for example "WhatsApp 45 min". Nothing else: no messages, no links, no videos watched, no photos and no screen content. It appears on your own dashboard on the website.
        </AppText>
        <AppText variant="small" tone="muted" style={styles.gap}>
          You stay in control. You can switch sharing off or erase everything at any time on this page.
        </AppText>
      </Card>

      <Card title="Sharing">
        <SwitchRow
          label="Share my phone use with my dashboard"
          hint="Off until you turn it on."
          value={sharing}
          onChange={(on) => {
            setErased(null);
            void setSharing(on).then(refresh);
          }}
          testID="pu-share"
        />
        {sharing && !granted ? (
          <>
            <Banner tone="warning" title="One more step" testID="pu-need-access">
              Android needs your permission to read app times. Tap the button, find ezexam in the list, and turn on "Allow usage access". Then come back to this screen.
            </Banner>
            <Button title="Open Android settings" onPress={openAccessSettings} testID="pu-open-settings" />
          </>
        ) : null}
        {sharing && granted ? (
          <>
            <AppText variant="small" tone="muted" testID="pu-status">
              {sender.state === 'sending'
                ? 'Sending…'
                : sender.state === 'error'
                  ? 'Could not send. It will try again the next time the app opens.'
                  : sender.lastSentAt
                    ? `Last sent at ${sender.lastSentAt.toLocaleTimeString()}.`
                    : 'It is sent when you open the app, and you can send it now.'}
            </AppText>
            <Button title="Send now" variant="secondary" loading={sender.state === 'sending'} onPress={() => void sendNow()} testID="pu-send" />
          </>
        ) : null}
      </Card>

      {erased !== null ? (
        <Banner tone="success" title="Erased" testID="pu-erased">
          {`Sharing is off and ${erased} saved row${erased === 1 ? '' : 's'} were deleted.`}
        </Banner>
      ) : null}
      {problem ? (
        <Banner tone="error" title="Problem" testID="pu-problem">
          {problem}
        </Banner>
      ) : null}
      {sender.state === 'error' && sender.error ? (
        <Banner tone="error" title="Not sent" testID="pu-send-error">
          {errorMessage(sender.error)}
        </Banner>
      ) : null}

      {granted ? (
        <Card title="Today so far">
          {today === null ? (
            <AppText tone="muted">Reading…</AppText>
          ) : today.length === 0 ? (
            <AppText tone="muted" testID="pu-today-empty">
              No app use recorded yet today.
            </AppText>
          ) : (
            <>
              <AppText variant="heading" testID="pu-today-total">
                {formatDuration(totalToday)}
              </AppText>
              <View style={styles.list}>
                {today.slice(0, 10).map((a) => (
                  <View key={a.package} style={styles.row}>
                    <AppText style={styles.app}>{a.label}</AppText>
                    <AppText tone="muted">{formatDuration(a.minutes)}</AppText>
                  </View>
                ))}
              </View>
            </>
          )}
        </Card>
      ) : null}

      <Button title="Stop sharing and erase everything" variant="danger" loading={erasing} onPress={() => void stopAndErase()} testID="pu-erase" />
    </Screen>
  );
}

const styles = StyleSheet.create({
  gap: { marginTop: 8 },
  list: { marginTop: 8 },
  row: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 4 },
  app: { flex: 1, paddingRight: 8 },
});
