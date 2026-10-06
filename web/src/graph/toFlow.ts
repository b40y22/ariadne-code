import type { Edge, MarkerType, Node } from '@vue-flow/core'
import { theme } from '../theme'
import type { Graph, GraphNode, NodeType } from './types'

export type CodeNodeKind = 'class' | 'method' | 'function' | 'script' | 'unresolved'

/** What the `code` node component renders. */
export interface CodeNodeData {
  kind: CodeNodeKind
  title: string
  subtitle: string
}

/** A Vue Flow node that is known to carry its display data. */
export type CodeNode = Node<CodeNodeData> & { data: CodeNodeData }

const CLASS_MAP_TYPES: ReadonlySet<NodeType> = new Set(['class', 'method', 'function', 'script', 'unresolved'])

/** "L11–18", or "L9" for a single line, or "" when the node has no location. */
export function lineRange(node: GraphNode): string {
  if (node.lineStart === null) {
    return ''
  }

  return node.lineStart === node.lineEnd || node.lineEnd === null ? `L${node.lineStart}` : `L${node.lineStart}–${node.lineEnd}`
}

function describe(node: GraphNode, methodCount: number): CodeNodeData {
  if (node.type === 'class') {
    return { kind: 'class', title: node.name, subtitle: `${methodCount} ${methodCount === 1 ? 'method' : 'methods'}` }
  }

  if (node.type === 'method' || node.type === 'function') {
    return { kind: node.type, title: `${node.name}()`, subtitle: lineRange(node) }
  }

  if (node.type === 'script') {
    return { kind: 'script', title: node.name, subtitle: `script · ${lineRange(node)}` }
  }

  return { kind: 'unresolved', title: node.name, subtitle: 'not resolved statically' }
}

/**
 * The class map: classes as containers holding their methods, and the calls between them.
 * A method sits inside its class, so `contains` edges are not drawn.
 * Method flow nodes are left out; they get their own view.
 *
 * Repeated calls between the same two methods collapse into one edge labelled "×N".
 *
 * Calls the analyzer could not resolve end in `unresolved` nodes. They are often the majority (every builtin and
 * every call on another object), so the map can leave them out; the edges to them go with them.
 */
export function toClassMap(graph: Graph, options: { includeUnresolved?: boolean } = {}): { nodes: CodeNode[]; edges: Edge[] } {
  const includeUnresolved = options.includeUnresolved ?? true

  const methodCounts = new Map<string, number>()
  const classOf = new Map<string, string>()

  for (const edge of graph.edges) {
    if (edge.type === 'contains') {
      methodCounts.set(edge.from, (methodCounts.get(edge.from) ?? 0) + 1)
      classOf.set(edge.to, edge.from)
    }
  }

  const nodes: CodeNode[] = graph.nodes
    .filter((node) => CLASS_MAP_TYPES.has(node.type) && (includeUnresolved || node.type !== 'unresolved'))
    .map((node) => {
      const parent = classOf.get(node.id)

      return {
        id: node.id,
        type: node.type === 'class' ? 'class-group' : 'code',
        data: describe(node, methodCounts.get(node.id) ?? 0),
        position: { x: 0, y: 0 },
        ...(parent === undefined ? {} : { parentNode: parent, extent: 'parent' as const }),
      }
    })

  const known = new Set(nodes.map((node) => node.id))
  const merged = new Map<string, { source: string; target: string; type: string; count: number }>()

  for (const edge of graph.edges) {
    if (edge.type !== 'calls' || !known.has(edge.from) || !known.has(edge.to)) {
      continue
    }

    const key = `${edge.type}|${edge.from}|${edge.to}`
    const entry = merged.get(key)

    if (entry) {
      entry.count++
    } else {
      merged.set(key, { source: edge.from, target: edge.to, type: edge.type, count: 1 })
    }
  }

  const edges: Edge[] = [...merged.entries()].map(([key, edge]) => ({
    id: key,
    source: edge.source,
    target: edge.target,
    type: 'smoothstep',
    label: edge.count > 1 ? `×${edge.count}` : undefined,
    class: `ariadne-edge ariadne-edge-${edge.type}`,
    markerEnd: edge.type === 'calls' ? { type: 'arrowclosed' as MarkerType, color: theme.accent } : undefined,
  }))

  return { nodes, edges }
}
