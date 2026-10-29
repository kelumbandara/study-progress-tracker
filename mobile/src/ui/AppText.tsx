import { Text, type TextProps } from 'react-native';

import { usePalette } from './theme';

type Variant = 'title' | 'heading' | 'body' | 'small' | 'label';
type Tone = 'normal' | 'muted' | 'danger' | 'success' | 'warning';

const sizes: Record<Variant, { fontSize: number; fontWeight: '400' | '600' | '700'; lineHeight: number }> = {
  title: { fontSize: 26, fontWeight: '700', lineHeight: 32 },
  heading: { fontSize: 18, fontWeight: '600', lineHeight: 24 },
  body: { fontSize: 16, fontWeight: '400', lineHeight: 22 },
  small: { fontSize: 14, fontWeight: '400', lineHeight: 20 },
  label: { fontSize: 14, fontWeight: '600', lineHeight: 20 },
};

interface Props extends TextProps {
  variant?: Variant;
  tone?: Tone;
}

export function AppText({ variant = 'body', tone = 'normal', style, ...rest }: Props) {
  const p = usePalette();
  const color = { normal: p.text, muted: p.muted, danger: p.danger, success: p.success, warning: p.warning }[tone];
  return <Text {...rest} style={[sizes[variant], { color }, style]} />;
}
