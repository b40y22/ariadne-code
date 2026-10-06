/**
 * Single source of the visual design. `applyTheme` publishes these values as CSS variables,
 * and the same object feeds Monaco and the graph markers, which cannot read CSS variables.
 *
 * To change the accent, change `accent` and `accentRgb` only.
 */
export const theme = {
  bg: '#0b0b0d',
  surface: '#141519',
  surfaceRaised: '#1a1b21',
  border: '#2a2c34',
  borderStrong: '#3a3d47',
  text: '#ececf0',
  muted: '#8b8f9a',
  dim: '#5b5f6b',
  accent: '#ff6a1a',
  accentRgb: '255, 106, 26',
  danger: '#ff5c5c',
  dot: '#23252c',
} as const

export function applyTheme(root: HTMLElement = document.documentElement): void {
  const vars: Record<string, string> = {
    '--bg': theme.bg,
    '--surface': theme.surface,
    '--surface-raised': theme.surfaceRaised,
    '--border': theme.border,
    '--border-strong': theme.borderStrong,
    '--text': theme.text,
    '--muted': theme.muted,
    '--dim': theme.dim,
    '--accent': theme.accent,
    '--accent-rgb': theme.accentRgb,
    '--danger': theme.danger,
  }

  for (const [name, value] of Object.entries(vars)) {
    root.style.setProperty(name, value)
  }
}
