import type { Node } from '@vue-flow/core'

export type Positions = Record<string, { x: number; y: number }>

const PREFIX = 'ariadne:layout:'

export const layoutKey = (fileName: string): string => PREFIX + fileName

function isPosition(value: unknown): value is { x: number; y: number } {
  return (
    typeof value === 'object' &&
    value !== null &&
    'x' in value &&
    'y' in value &&
    Number.isFinite(value.x) &&
    Number.isFinite(value.y)
  )
}

/** Reads saved node positions. Anything unreadable or malformed counts as "nothing saved". */
export function loadPositions(storage: Pick<Storage, 'getItem'>, key: string): Positions {
  try {
    const raw = storage.getItem(key)
    const data: unknown = raw === null ? null : JSON.parse(raw)

    if (typeof data !== 'object' || data === null) {
      return {}
    }

    return Object.fromEntries(Object.entries(data).filter(([, value]) => isPosition(value))) as Positions
  } catch {
    return {}
  }
}

export function savePositions(storage: Pick<Storage, 'setItem'>, key: string, nodes: readonly Node[]): void {
  const positions: Positions = {}

  for (const node of nodes) {
    positions[node.id] = { x: Math.round(node.position.x), y: Math.round(node.position.y) }
  }

  try {
    storage.setItem(key, JSON.stringify(positions))
  } catch {
    // Storage may be full or disabled; losing the saved layout is not worth interrupting the user.
  }
}

export function clearPositions(storage: Pick<Storage, 'removeItem'>, key: string): void {
  try {
    storage.removeItem(key)
  } catch {
    // See savePositions.
  }
}

/** Nodes with a saved position move there; the rest keep the automatic layout. */
export function applyPositions(nodes: readonly Node[], saved: Positions): Node[] {
  return nodes.map((node) => {
    const position = saved[node.id]

    return position ? { ...node, position: { ...position } } : node
  })
}
