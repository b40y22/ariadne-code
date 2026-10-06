export const MIN_PANEL = 120
export const DEFAULT_PANEL = 210
/** The graph above the panel always keeps at least this much room. */
export const MIN_GRAPH = 160

/** A panel height kept within bounds for the space it sits in. */
export function clampPanelHeight(value: number, available: number): number {
  if (!Number.isFinite(value)) {
    return DEFAULT_PANEL
  }

  const max = Math.max(MIN_PANEL, available - MIN_GRAPH)

  return Math.round(Math.min(max, Math.max(MIN_PANEL, value)))
}

/** The height a double-click gives: as tall as allowed, or back to the default when already there. */
export function toggledPanelHeight(current: number, available: number): number {
  const max = clampPanelHeight(available, available)

  return current >= max - 8 ? DEFAULT_PANEL : max
}
