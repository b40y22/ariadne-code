import type { Graph, GraphNode } from './types'

/**
 * The innermost class or method whose source range contains the line.
 * A method wins over its class because its range is narrower.
 */
export function nodeAtLine(graph: Graph, line: number): GraphNode | undefined {
  let best: GraphNode | undefined
  let bestSize = Infinity

  for (const node of graph.nodes) {
    if ((node.type !== 'class' && node.type !== 'method') || node.lineStart === null || node.lineEnd === null) {
      continue
    }

    const size = node.lineEnd - node.lineStart

    if (line >= node.lineStart && line <= node.lineEnd && size < bestSize) {
      best = node
      bestSize = size
    }
  }

  return best
}
