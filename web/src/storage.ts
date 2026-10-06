/**
 * `localStorage` that never throws. It is missing or full in private windows and some embedded browsers,
 * and a remembered layout or panel size is never worth breaking the page over.
 */

export function readStored(key: string, storage?: Pick<Storage, 'getItem'>): string | null {
  try {
    return (storage ?? localStorage).getItem(key)
  } catch {
    return null
  }
}

export function readStoredNumber(key: string, fallback: number, storage?: Pick<Storage, 'getItem'>): number {
  const raw = readStored(key, storage)
  const value = raw === null ? Number.NaN : Number(raw)

  return Number.isFinite(value) ? value : fallback
}

export function writeStored(key: string, value: string | number, storage?: Pick<Storage, 'setItem'>): void {
  try {
    ;(storage ?? localStorage).setItem(key, String(value))
  } catch {
    // The value just won't be remembered.
  }
}
