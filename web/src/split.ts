export const MIN_SPLIT = 20
export const MAX_SPLIT = 80
export const DEFAULT_SPLIT = 55

/** Width of the left pane as a percentage, kept within sensible bounds. */
export function clampSplit(value: number): number {
  return Number.isFinite(value) ? Math.min(MAX_SPLIT, Math.max(MIN_SPLIT, value)) : DEFAULT_SPLIT
}

/** Converts a pointer position inside a container to a left-pane percentage. */
export function splitFromPointer(pointerX: number, left: number, width: number): number {
  return width > 0 ? clampSplit(((pointerX - left) / width) * 100) : DEFAULT_SPLIT
}
