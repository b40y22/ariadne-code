/** Which view of the graph is shown, and how it is written in the page address. */

export type View = { kind: 'map' } | { kind: 'flow'; methodId: string }

/** The current selection. `reveal` scrolls the matching code into view; off when the click came from the code. */
export interface Selection {
  id: string
  reveal: boolean
}

export const MAP: View = { kind: 'map' }

const HASH_KEY = 'method'

export function viewFromHash(hash: string): View {
  const methodId = new URLSearchParams(hash.replace(/^#/, '')).get(HASH_KEY)

  return methodId === null || methodId === '' ? MAP : { kind: 'flow', methodId }
}

export function hashFor(view: View): string {
  return view.kind === 'flow' ? `#${HASH_KEY}=${encodeURIComponent(view.methodId)}` : ''
}

export function sameView(a: View, b: View): boolean {
  return a.kind === 'map' ? b.kind === 'map' : b.kind === 'flow' && a.methodId === b.methodId
}
