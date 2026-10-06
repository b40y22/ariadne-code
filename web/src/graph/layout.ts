import type { Edge, Node } from '@vue-flow/core'
import ELK, { type ElkNode } from 'elkjs/lib/elk.bundled.js'

const elk = new ELK()

const NODE_HEIGHT = 58
const MIN_NODE_WIDTH = 190
const MAX_NODE_WIDTH = 380
const CHAR_WIDTH = 8.5
// Icon, gaps and padding around the title.
const CHROME = 78
// Room for the class header above its methods.
const GROUP_PADDING = { top: 74, left: 22, bottom: 22, right: 22 }
const GROUP_MIN_HEIGHT = 110

interface Box {
  x: number
  y: number
  width: number
  height: number
}

/** A node that shows a title and a subtitle, which is what sizes it. */
export type LayoutNode = Node & { data: { title: string; subtitle: string } }

export interface LayoutOptions {
  /** Direction of the main flow. The class map reads left to right, a method flow top to bottom. */
  direction?: 'RIGHT' | 'DOWN'
}

function widthOf(node: LayoutNode): number {
  const longest = Math.max(node.data.title.length, node.data.subtitle.length)

  return Math.min(MAX_NODE_WIDTH, Math.max(MIN_NODE_WIDTH, Math.round(longest * CHAR_WIDTH + CHROME)))
}

function collect(node: ElkNode, into: Map<string, Box>): void {
  for (const child of node.children ?? []) {
    into.set(child.id, { x: child.x ?? 0, y: child.y ?? 0, width: child.width ?? 0, height: child.height ?? 0 })
    collect(child, into)
  }
}

/**
 * Positions nodes left to right with ELK's layered algorithm. Nodes with a `parentNode` are laid out
 * inside it, with coordinates relative to the parent, which is what Vue Flow expects.
 * Returns new node objects.
 */
export async function layout<T extends LayoutNode>(nodes: T[], edges: Edge[], options: LayoutOptions = {}): Promise<T[]> {
  const direction = options.direction ?? 'RIGHT'
  const kids = new Map<string, T[]>()
  const roots: T[] = []

  for (const node of nodes) {
    if (node.parentNode === undefined) {
      roots.push(node)
    } else {
      kids.set(node.parentNode, [...(kids.get(node.parentNode) ?? []), node])
    }
  }

  const toElk = (node: T): ElkNode => {
    const children = kids.get(node.id)

    if (children === undefined) {
      return { id: node.id, width: widthOf(node), height: NODE_HEIGHT }
    }

    return {
      id: node.id,
      layoutOptions: {
        'elk.padding': `[top=${GROUP_PADDING.top},left=${GROUP_PADDING.left},bottom=${GROUP_PADDING.bottom},right=${GROUP_PADDING.right}]`,
        'elk.spacing.nodeNode': '28',
        'elk.layered.spacing.nodeNodeBetweenLayers': '80',
        'elk.layered.spacing.edgeNodeBetweenLayers': '24',
        'elk.nodeSize.constraints': 'MINIMUM_SIZE',
        'elk.nodeSize.minimum': `(${widthOf(node) + GROUP_PADDING.left * 2}, ${GROUP_MIN_HEIGHT})`,
      },
      children: children.map(toElk),
    }
  }

  const result = await elk.layout({
    id: 'root',
    layoutOptions: {
      'elk.algorithm': 'layered',
      'elk.direction': direction,
      'elk.hierarchyHandling': 'INCLUDE_CHILDREN',
      'elk.spacing.nodeNode': '36',
      'elk.layered.spacing.nodeNodeBetweenLayers': '80',
      'elk.layered.spacing.edgeNodeBetweenLayers': '24',
    },
    children: roots.map(toElk),
    edges: edges.map((edge) => ({ id: edge.id, sources: [edge.source], targets: [edge.target] })),
  })

  const boxes = new Map<string, Box>()
  collect(result, boxes)

  return nodes.map((node) => {
    const box = boxes.get(node.id)

    if (box === undefined) {
      return node
    }

    return {
      ...node,
      position: { x: box.x, y: box.y },
      style: { width: `${Math.round(box.width)}px`, height: `${Math.round(box.height)}px` },
    } as T
  })
}
