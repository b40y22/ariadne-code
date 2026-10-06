import type { Edge, MarkerType, Node } from '@vue-flow/core'
import { theme } from '../theme'
import type { Graph, GraphNode, NodeType } from './types'

export type CodeNodeKind = 'class' | 'method' | 'unresolved'

/** What the `code` node component renders. */
export interface CodeNodeData {
  kind: CodeNodeKind
  title: string
  subtitle: string
}

/** A Vue Flow node that is known to carry its display data. */
export type CodeNode = Node<CodeNodeData> & { data: CodeNodeData }

const CLASS_MAP_TYPES: ReadonlySet<NodeType> = new Set(['class', 'method', 'unresolved'])

function describe(node: GraphNode, methodCount: number): CodeNodeData {
  if (node.type === 'class') {
    return { kind: 'class', title: node.name, subtitle: `${methodCount} ${methodCount === 1 ? 'method' : 'methods'}` }
  }

  if (node.type === 'method') {
    const range = node.lineStart === null ? '' : node.lineStart === node.lineEnd ? `L${node.lineStart}` : `L${node.lineStart}–${node.lineEnd}`

    return { kind: 'method', title: `${node.name}()`, subtitle: range }
  }

  return { kind: 'unresolved', title: node.name, subtitle: 'not resolved statically' }
}

/**
 * The class map: classes, their methods and the calls between them.
 * Method flow nodes are left out; they get their own view.
 *
 * Repeated calls between the same two methods collapse into one edge labelled "×N".
 */
export function toClassMap(graph: Graph): { nodes: CodeNode[]; edges: Edge[] } {
  const methodCounts = new Map<string, number>()

  for (const edge of graph.edges) {
    if (edge.type === 'contains') {
      methodCounts.set(edge.from, (methodCounts.get(edge.from) ?? 0) + 1)
    }
  }

  const nodes: CodeNode[] = graph.nodes
    .filter((node) => CLASS_MAP_TYPES.has(node.type))
    .map((node) => ({
      id: node.id,
      type: 'code',
      data: describe(node, methodCounts.get(node.id) ?? 0),
      position: { x: 0, y: 0 },
    }))

  const known = new Set(nodes.map((node) => node.id))
  const merged = new Map<string, { source: string; target: string; type: string; count: number }>()

  for (const edge of graph.edges) {
    if (edge.type === 'flow' || !known.has(edge.from) || !known.has(edge.to)) {
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
