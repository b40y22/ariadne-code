/** Which view of the graph is shown, and how it is written in the page address. */

export type View = { kind: 'map' } | { kind: 'flow'; methodId: string }

/** The current selection. `reveal` scrolls the matching code into view; off when the click came from the code. */
export interface Selection {
  id: string
  reveal: boolean
}

export const MAP: View = { kind: 'map' }

const HASH_KEY = 'method'

/** In project mode, the unit the map is focused on. */
const FOCUS_KEY = 'focus'

const params = (hash: string): URLSearchParams => new URLSearchParams(hash.replace(/^#/, ''))

export function viewFromHash(hash: string): View {
  const methodId = params(hash).get(HASH_KEY)

  return methodId === null || methodId === '' ? MAP : { kind: 'flow', methodId }
}

export function focusFromHash(hash: string): string | null {
  const focus = params(hash).get(FOCUS_KEY)

  return focus === '' ? null : focus
}

export function hashFor(view: View, focus: string | null = null): string {
  const parts = [
    ...(focus === null ? [] : [`${FOCUS_KEY}=${encodeURIComponent(focus)}`]),
    ...(view.kind === 'flow' ? [`${HASH_KEY}=${encodeURIComponent(view.methodId)}`] : []),
  ]

  return parts.length === 0 ? '' : `#${parts.join('&')}`
}

export function sameView(a: View, b: View): boolean {
  return a.kind === 'map' ? b.kind === 'map' : b.kind === 'flow' && a.methodId === b.methodId
}
