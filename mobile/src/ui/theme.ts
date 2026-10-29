import { useColorScheme } from 'react-native';

export interface Palette {
  background: string;
  surface: string;
  text: string;
  muted: string;
  border: string;
  primary: string;
  onPrimary: string;
  inputBackground: string;
  danger: string;
  warning: string;
  success: string;
  info: string;
  dangerBg: string;
  warningBg: string;
  successBg: string;
  infoBg: string;
}

const light: Palette = {
  background: '#f4f6f9', surface: '#ffffff', text: '#1b1f24', muted: '#566170', border: '#cfd6de',
  primary: '#1860c8', onPrimary: '#ffffff', inputBackground: '#ffffff',
  danger: '#b3261e', warning: '#8a5a00', success: '#17692d', info: '#0b5cad',
  dangerBg: '#fdecea', warningBg: '#fff4d6', successBg: '#e6f4ea', infoBg: '#e8f1fb',
};

const dark: Palette = {
  background: '#0e1218', surface: '#171d26', text: '#e8edf3', muted: '#a3adba', border: '#2f3947',
  primary: '#6aa7ff', onPrimary: '#0a1626', inputBackground: '#10151c',
  danger: '#ff8a80', warning: '#ffcc66', success: '#6fd58a', info: '#8ec1ff',
  dangerBg: '#3a1a18', warningBg: '#3a2e10', successBg: '#13301d', infoBg: '#14263d',
};

export const spacing = { xs: 4, sm: 8, md: 12, lg: 16, xl: 24 } as const;
export const radius = { sm: 8, md: 12 } as const;
/** Android's recommended minimum touch target is 48 dp. */
export const MIN_TOUCH = 48;
export const MAX_CONTENT_WIDTH = 680;

export function usePalette(): Palette {
  return useColorScheme() === 'dark' ? dark : light;
}
