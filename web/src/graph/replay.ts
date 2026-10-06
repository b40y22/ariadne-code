import type { Edge } from '@vue-flow/core'

/** One step of a replay: the node reached and the edge that led there (none for the first step). */
export interface Step {
  nodeId: string
  viaEdgeId: string | null
  /** Branch taken to get here, e.g. "true" or "exit". */
  label: string | null
}

/** A loop that never ends would grow the log forever; a person stepping through will not need more than this. */
export const MAX_STEPS = 500

/** Labels that mark the main path of a branch, preferred when a step is taken without choosing. */
const PRIMARY_LABELS = new Set(['true', 'body', 'set'])

export function startReplay(startNodeId: string): Step[] {
  return [{ nodeId: startNodeId, viaEdgeId: null, label: null }]
}

export function current(path: readonly Step[]): Step | undefined {
  return path[path.length - 1]
}

/** The ways to leave a node, in the order the analyzer produced them. */
export function exits(edges: readonly Edge[], nodeId: string): Edge[] {
  return edges.filter((edge) => edge.source === nodeId)
}

const labelOf = (edge: Edge): string | null => (edge.label === undefined || edge.label === '' ? null : String(edge.label))

/**
 * The exit to take when the reader just presses "next": a plain edge, else the main branch,
 * else the first one. Never a way back into a loop while a forward exit exists.
 */
export function primaryExit(options: readonly Edge[], isBackward: (edge: Edge) => boolean = () => false): Edge | undefined {
  const forward = options.filter((edge) => !isBackward(edge))
  const pool = forward.length > 0 ? forward : options

  return pool.find((edge) => labelOf(edge) === null) ?? pool.find((edge) => PRIMARY_LABELS.has(labelOf(edge) ?? '')) ?? pool[0]
}

/** The path after taking an edge. A path that reached its length limit is returned unchanged. */
export function advance(path: readonly Step[], edge: Edge): Step[] {
  if (path.length >= MAX_STEPS || current(path)?.nodeId !== edge.source) {
    return [...path]
  }

  return [...path, { nodeId: edge.target, viaEdgeId: edge.id, label: labelOf(edge) }]
}

/** One step back, never past the start. */
export function stepBack(path: readonly Step[]): Step[] {
  return path.length > 1 ? path.slice(0, -1) : [...path]
}

/** Returns to an earlier step, discarding everything after it. */
export function jumpTo(path: readonly Step[], index: number): Step[] {
  return index >= 0 && index < path.length ? path.slice(0, index + 1) : [...path]
}

/** Ids of the edges walked so far. */
export function walkedEdges(path: readonly Step[]): Set<string> {
  return new Set(path.flatMap((step) => (step.viaEdgeId === null ? [] : [step.viaEdgeId])))
}

/** Ids of the nodes visited so far. */
export function visitedNodes(path: readonly Step[]): Set<string> {
  return new Set(path.map((step) => step.nodeId))
}
