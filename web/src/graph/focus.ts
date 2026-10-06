import type { Graph } from './types'

/** What a reader can focus on: a class with its methods, a function, or the script of a file. */
const UNIT_TYPES: ReadonlySet<string> = new Set(['class', 'function', 'script'])

export interface FocusUnit {
  id: string
  name: string
  type: 'class' | 'function' | 'script'
}

/** Every class, function and script of the graph, by name, for a picker. */
export function focusUnits(graph: Graph): FocusUnit[] {
  return graph.nodes
    .filter((node) => UNIT_TYPES.has(node.type))
    .map((node) => ({ id: node.id, name: node.name, type: node.type as FocusUnit['type'] }))
    .sort((a, b) => a.name.localeCompare(b.name))
}

/**
 * The unit a node belongs to: a method's class, a flow node's method's unit, or the node itself for a class,
 * function or script. Undefined for call targets with nothing to focus on (`external`, `unresolved`).
 */
export function unitOf(graph: Graph, nodeId: string): string | undefined {
  const byId = new Map(graph.nodes.map((node) => [node.id, node]))
  let node = byId.get(nodeId)

  if (node?.parent) {
    node = byId.get(node.parent)
  }

  if (node === undefined) {
    return undefined
  }

  if (UNIT_TYPES.has(node.type)) {
    return node.id
  }

  const id = node.id

  return node.type === 'method' ? graph.edges.find((edge) => edge.type === 'contains' && edge.to === id)?.from : undefined
}

function members(graph: Graph, unitId: string): Set<string> {
  const ids = new Set([unitId])

  for (const edge of graph.edges) {
    if (edge.type === 'contains' && edge.from === unitId) {
      ids.add(edge.to)
    }
  }

  return ids
}

/**
 * The part of a project's map around one unit: the unit with all its methods, everything they call and
 * everything that calls them, and the classes of those methods holding only the methods involved.
 * Calls between two neighbours are left out, so every edge on screen touches the unit.
 */
export function focusGraph(graph: Graph, unitId: string): Graph {
  const own = members(graph, unitId)
  const shown = new Set(own)

  for (const edge of graph.edges) {
    if (edge.type !== 'calls') {
      continue
    }

    if (own.has(edge.from)) {
      shown.add(edge.to)
    } else if (own.has(edge.to)) {
      shown.add(edge.from)
    }
  }

  for (const edge of graph.edges) {
    if (edge.type === 'contains' && shown.has(edge.to)) {
      shown.add(edge.from)
    }
  }

  return {
    nodes: graph.nodes.filter((node) => shown.has(node.id)),
    edges: graph.edges.filter(
      (edge) =>
        shown.has(edge.from) &&
        shown.has(edge.to) &&
        (edge.type === 'contains' || (edge.type === 'calls' && (own.has(edge.from) || own.has(edge.to)))),
    ),
  }
}

/** The unit with the most calls in and out of it: a good first thing to look at in a project nobody explained. */
export function busiestUnit(graph: Graph): string | undefined {
  const unitOfMember = new Map<string, string>()

  for (const node of graph.nodes) {
    if (UNIT_TYPES.has(node.type)) {
      unitOfMember.set(node.id, node.id)
    }
  }

  for (const edge of graph.edges) {
    if (edge.type === 'contains') {
      unitOfMember.set(edge.to, edge.from)
    }
  }

  const counts = new Map<string, number>()

  for (const edge of graph.edges) {
    if (edge.type !== 'calls') {
      continue
    }

    for (const end of new Set([unitOfMember.get(edge.from), unitOfMember.get(edge.to)])) {
      if (end !== undefined) {
        counts.set(end, (counts.get(end) ?? 0) + 1)
      }
    }
  }

  let best: string | undefined
  let bestCount = -1

  for (const unit of focusUnits(graph)) {
    const count = counts.get(unit.id) ?? 0

    if (count > bestCount) {
      best = unit.id
      bestCount = count
    }
  }

  return best
}

/** A graph made of the map around the focus and the flow of one method, which the API serves apart. */
export function withFlow(graph: Graph, flow: Graph | null): Graph {
  return flow === null ? graph : { nodes: [...graph.nodes, ...flow.nodes], edges: [...graph.edges, ...flow.edges] }
}

