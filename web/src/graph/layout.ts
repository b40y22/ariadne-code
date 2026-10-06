import type { Edge, Node } from '@vue-flow/core'
import ELK from 'elkjs/lib/elk.bundled.js'

const elk = new ELK()

const NODE_HEIGHT = 40
const MIN_NODE_WIDTH = 110
const CHAR_WIDTH = 8
const PADDING = 32

function widthOf(node: Node): number {
  return Math.max(MIN_NODE_WIDTH, String(node.label ?? '').length * CHAR_WIDTH + PADDING)
}

/** Positions nodes top-down with ELK's layered algorithm. Returns new node objects. */
export async function layout(nodes: Node[], edges: Edge[]): Promise<Node[]> {
  const widths = new Map(nodes.map((node) => [node.id, widthOf(node)]))

  const result = await elk.layout({
    id: 'root',
    layoutOptions: {
      'elk.algorithm': 'layered',
      'elk.direction': 'DOWN',
      'elk.spacing.nodeNode': '40',
      'elk.layered.spacing.nodeNodeBetweenLayers': '70',
    },
    children: nodes.map((node) => ({ id: node.id, width: widths.get(node.id) ?? MIN_NODE_WIDTH, height: NODE_HEIGHT })),
    edges: edges.map((edge) => ({ id: edge.id, sources: [edge.source], targets: [edge.target] })),
  })

  const positions = new Map((result.children ?? []).map((child) => [child.id, { x: child.x ?? 0, y: child.y ?? 0 }]))

  return nodes.map((node) => ({
    ...node,
    position: positions.get(node.id) ?? node.position,
    style: { width: `${widths.get(node.id)}px`, height: `${NODE_HEIGHT}px` },
  }))
}
