import type { Edge } from '@vue-flow/core'
import ELK from 'elkjs/lib/elk.bundled.js'
import type { CodeNode } from './toFlow'

const elk = new ELK()

const NODE_HEIGHT = 58
const MIN_NODE_WIDTH = 190
const CHAR_WIDTH = 8.5
// Icon, gaps and padding around the title.
const CHROME = 78

function widthOf(node: CodeNode): number {
  const longest = Math.max(node.data.title.length, node.data.subtitle.length)

  return Math.max(MIN_NODE_WIDTH, Math.round(longest * CHAR_WIDTH + CHROME))
}

/** Positions nodes left to right with ELK's layered algorithm. Returns new node objects. */
export async function layout(nodes: CodeNode[], edges: Edge[]): Promise<CodeNode[]> {
  const widths = new Map(nodes.map((node) => [node.id, widthOf(node)]))

  const result = await elk.layout({
    id: 'root',
    layoutOptions: {
      'elk.algorithm': 'layered',
      'elk.direction': 'RIGHT',
      'elk.spacing.nodeNode': '36',
      'elk.layered.spacing.nodeNodeBetweenLayers': '110',
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
