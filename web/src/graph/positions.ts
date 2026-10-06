import type { Node } from '@vue-flow/core'

export interface SavedPosition {
  x: number
  y: number
  /** Only stored for resizable nodes (class groups). */
  width?: number
  height?: number
}

export type Positions = Record<string, SavedPosition>

/** The part of a Vue Flow node that is worth remembering. */
export interface SavableNode {
  id: string
  type?: string
  position: { x: number; y: number }
  dimensions?: { width: number; height: number }
}

const RESIZABLE = 'class-group'

// v2: classes became containers, so positions saved by the flat layout no longer fit.
const PREFIX = 'ariadne:layout:v2:'

export const layoutKey = (fileName: string): string => PREFIX + fileName

const isNumber = (value: unknown): value is number => typeof value === 'number' && Number.isFinite(value)

function isPosition(value: unknown): value is SavedPosition {
  return typeof value === 'object' && value !== null && 'x' in value && 'y' in value && isNumber(value.x) && isNumber(value.y)
}

/** Reads saved node positions. Anything unreadable or malformed counts as "nothing saved". */
export function loadPositions(storage: Pick<Storage, 'getItem'>, key: string): Positions {
  try {
    const raw = storage.getItem(key)
    const data: unknown = raw === null ? null : JSON.parse(raw)

    if (typeof data !== 'object' || data === null) {
      return {}
    }

    const positions: Positions = {}

    for (const [id, value] of Object.entries(data)) {
      if (!isPosition(value)) {
        continue
      }

      const size = 'width' in value && 'height' in value && isNumber(value.width) && isNumber(value.height) ? { width: value.width, height: value.height } : {}
      positions[id] = { x: value.x, y: value.y, ...size }
    }

    return positions
  } catch {
    return {}
  }
}

export function savePositions(storage: Pick<Storage, 'setItem'>, key: string, nodes: readonly SavableNode[]): void {
  const positions: Positions = {}

  for (const node of nodes) {
    const size =
      node.type === RESIZABLE && node.dimensions
        ? { width: Math.round(node.dimensions.width), height: Math.round(node.dimensions.height) }
        : {}

    positions[node.id] = { x: Math.round(node.position.x), y: Math.round(node.position.y), ...size }
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

/** Nodes with a saved position move there (and resizable ones get their size back); the rest keep the automatic layout. */
export function applyPositions<T extends Node>(nodes: readonly T[], saved: Positions): T[] {
  return nodes.map((node) => {
    const saves = saved[node.id]

    if (!saves) {
      return node
    }

    const moved = { ...node, position: { x: saves.x, y: saves.y } }

    return node.type === RESIZABLE && saves.width !== undefined && saves.height !== undefined
      ? { ...moved, style: { width: `${saves.width}px`, height: `${saves.height}px` } }
      : moved
  })
}
