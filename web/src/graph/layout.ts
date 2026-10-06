import type { Edge, Node } from '@vue-flow/core'
import ELK, { type ElkNode } from 'elkjs/lib/elk.bundled.js'

const elk = new ELK()

const NODE_HEIGHT = 58
const MIN_NODE_WIDTH = 190
const MAX_NODE_WIDTH = 420
const CHAR_WIDTH = 8.5
// Icon, gaps and padding around the title.
const CHROME = 78
// The mark on a call step that opens the flow of the method it calls.
const OPENS_MARK = 24
// Room for the class header above its methods.
const GROUP_PADDING = { top: 74, left: 22, bottom: 22, right: 22 }
const GROUP_MIN_HEIGHT = 110

// A class with more methods than this is laid out as a grid instead of by its calls: a call-driven layout of
// dozens of methods is one tall column that has to be shrunk until nothing can be read. Up to this many the calls
// between methods are worth seeing, so the layout keeps following them.
export const GRID_THRESHOLD = 14
const GRID_COLUMNS = 4
const GRID_GAP_X = 28
const GRID_GAP_Y = 22

interface Box {
  x: number
  y: number
  width: number
  height: number
}

/** A node that shows a title and a subtitle, which is what sizes it. */
export type LayoutNode = Node & { data: { title: string; subtitle: string; opens?: string } }

export interface LayoutOptions {
  /** Direction of the main flow. The class map reads left to right, a method flow top to bottom. */
  direction?: 'RIGHT' | 'DOWN'
}

/**
 * Extra ELK options for a method flow, which is a story read from the top rather than a map.
 * - Model order keeps the source order of statements and branches (`true` before `false`), so the main
 *   path stays in one column instead of being shuffled to reduce crossings.
 * - Wider gaps leave room for the branch labels that sit on the edges.
 */
const FLOW_OPTIONS: Record<string, string> = {
  'elk.layered.considerModelOrder.strategy': 'NODES_AND_EDGES',
  'elk.layered.crossingMinimization.forceNodeModelOrder': 'true',
  'elk.layered.nodePlacement.bk.fixedAlignment': 'BALANCED',
  'elk.spacing.nodeNode': '56',
  'elk.layered.spacing.nodeNodeBetweenLayers': '64',
  'elk.layered.spacing.edgeNodeBetweenLayers': '28',
  'elk.spacing.edgeNode': '28',
  'elk.spacing.edgeEdge': '18',
}

export function widthOf(node: LayoutNode): number {
  const longest = Math.max(node.data.title.length, node.data.subtitle.length)

  const chrome = CHROME + (node.data.opens === undefined ? 0 : OPENS_MARK)

  return Math.min(MAX_NODE_WIDTH, Math.max(MIN_NODE_WIDTH, Math.round(longest * CHAR_WIDTH + chrome)))
}

export interface Grid {
  width: number
  height: number
  /** Position and size of every child, relative to the group. */
  cells: Map<string, Box>
}

/** Children in source order, row by row, in columns as wide as their widest member. */
export function gridOf(children: readonly LayoutNode[], minWidth: number): Grid {
  const columns = Math.min(GRID_COLUMNS, children.length)
  const widths = Array.from({ length: columns }, () => 0)

  children.forEach((child, index) => {
    const column = index % columns
    widths[column] = Math.max(widths[column] ?? 0, widthOf(child))
  })

  const offsets = widths.map((_, column) => widths.slice(0, column).reduce((sum, width) => sum + width + GRID_GAP_X, 0))
  const rows = Math.ceil(children.length / columns)
  const cells = new Map<string, Box>()

  children.forEach((child, index) => {
    const column = index % columns
    cells.set(child.id, {
      x: GROUP_PADDING.left + (offsets[column] ?? 0),
      y: GROUP_PADDING.top + Math.floor(index / columns) * (NODE_HEIGHT + GRID_GAP_Y),
      width: widths[column] ?? MIN_NODE_WIDTH,
      height: NODE_HEIGHT,
    })
  })

  const inner = widths.reduce((sum, width) => sum + width, 0) + GRID_GAP_X * (columns - 1)

  return {
    width: Math.max(minWidth, GROUP_PADDING.left + inner + GROUP_PADDING.right),
    height: GROUP_PADDING.top + rows * NODE_HEIGHT + (rows - 1) * GRID_GAP_Y + GROUP_PADDING.bottom,
    cells,
  }
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

  // Big classes are placed by us, as a grid; ELK then sees them as single boxes.
  const grids = new Map<string, Grid>()
  const owner = new Map<string, string>()

  for (const node of roots) {
    const children = kids.get(node.id)

    if (children !== undefined && children.length > GRID_THRESHOLD) {
      grids.set(node.id, gridOf(children, widthOf(node) + GROUP_PADDING.left * 2))
      children.forEach((child) => owner.set(child.id, node.id))
    }
  }

  const toElk = (node: T): ElkNode => {
    const grid = grids.get(node.id)

    if (grid !== undefined) {
      return { id: node.id, width: grid.width, height: grid.height }
    }

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
      ...(direction === 'DOWN' ? FLOW_OPTIONS : {}),
    },
    children: roots.map(toElk),
    // An edge to a method inside a grid ends at its class, which is the only box ELK knows.
    edges: edges
      .map((edge) => ({ id: edge.id, source: owner.get(edge.source) ?? edge.source, target: owner.get(edge.target) ?? edge.target }))
      .filter((edge) => edge.source !== edge.target)
      .map((edge) => ({ id: edge.id, sources: [edge.source], targets: [edge.target] })),
  })

  const boxes = new Map<string, Box>()
  collect(result, boxes)

  for (const [id, grid] of grids) {
    grid.cells.forEach((cell, childId) => boxes.set(childId, cell))
    const group = boxes.get(id)

    if (group !== undefined) {
      boxes.set(id, { ...group, width: grid.width, height: grid.height })
    }
  }

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
