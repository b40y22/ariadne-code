import type { Edge, Node } from '@vue-flow/core'
import type { Graph, NodeType } from './types'

const CLASS_MAP_TYPES: ReadonlySet<NodeType> = new Set(['class', 'method', 'unresolved'])

/**
 * The class map: classes, their methods and the calls between them.
 * Method flow nodes are left out; they get their own view.
 *
 * Repeated calls between the same two methods collapse into one edge labelled "×N".
 */
export function toClassMap(graph: Graph): { nodes: Node[]; edges: Edge[] } {
  const nodes: Node[] = graph.nodes
    .filter((node) => CLASS_MAP_TYPES.has(node.type))
    .map((node) => ({
      id: node.id,
      label: node.type === 'method' ? `${node.name}()` : node.name,
      position: { x: 0, y: 0 },
      class: `ariadne-node ariadne-${node.type}`,
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
    label: edge.count > 1 ? `×${edge.count}` : undefined,
    class: `ariadne-edge ariadne-edge-${edge.type}`,
    markerEnd: edge.type === 'calls' ? 'arrowclosed' : undefined,
  }))

  return { nodes, edges }
}
