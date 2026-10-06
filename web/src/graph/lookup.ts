import type { Graph, GraphNode } from './types'

/**
 * The innermost node whose source range contains the line.
 *
 * Without `parent` the candidates are classes, methods, functions and scripts: the narrowest range wins, so a
 * method beats its class and a function beats the script that surrounds it. With `parent` (a method id) they are the flow nodes of that method, except
 * start and end, which span the whole method and would otherwise match every line.
 */
export function nodeAtLine(graph: Graph, line: number, parent?: string): GraphNode | undefined {
  let best: GraphNode | undefined
  let bestSize = Infinity

  for (const node of graph.nodes) {
    const candidate =
      parent === undefined
        ? node.type === 'class' || node.type === 'method' || node.type === 'function' || node.type === 'script'
        : node.parent === parent && node.type !== 'start' && node.type !== 'end'

    if (!candidate || node.lineStart === null || node.lineEnd === null) {
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
