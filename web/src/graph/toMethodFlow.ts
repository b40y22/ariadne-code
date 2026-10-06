import type { Edge, MarkerType, Node } from '@vue-flow/core'
import { theme } from '../theme'
import { lineRange } from './toFlow'
import type { Graph, NodeType } from './types'

export type FlowKind = 'start' | 'end' | 'call' | 'condition' | 'loop' | 'try' | 'catch' | 'finally' | 'return' | 'throw'

/** What the `flow` node component renders. */
export interface FlowNodeData {
  kind: FlowKind
  title: string
  subtitle: string
}

/** A Vue Flow node that is known to carry its display data. */
export type FlowNode = Node<FlowNodeData> & { data: FlowNodeData }

const FLOW_KINDS: ReadonlySet<NodeType> = new Set<NodeType>([
  'start',
  'end',
  'call',
  'condition',
  'loop',
  'try',
  'catch',
  'finally',
  'return',
  'throw',
])

/** How far a loop-back swings out to the right of the nodes it passes. */
const LOOP_OFFSET = 56

/** Labels that mean "the exceptional path": drawn in the danger colour. */
const EXCEPTIONAL = new Set(['exception', 'throw'])
/** Labels that return to an earlier node: drawn dashed so loops read as loops. */
const BACKWARD = new Set(['next', 'continue'])
/** Labels that carry the main path of a branch. */
const PRIMARY = new Set(['true', 'body', 'set'])

/** Flow nodes are numbered in the order they are built, which is also the order of execution. */
const orderOf = (id: string): number => Number(/#(\d+)$/.exec(id)?.[1] ?? Number.NaN)

/** An edge that returns to a node built earlier or at the same point: a loop-back (`next` or `continue`). */
export function isBackward(from: string, to: string): boolean {
  return orderOf(to) <= orderOf(from)
}

export function flowEdgeClass(label: string | null): string {
  if (label === null) {
    return 'flow-edge flow-edge-plain'
  }

  const kind = EXCEPTIONAL.has(label) ? 'exceptional' : BACKWARD.has(label) ? 'backward' : PRIMARY.has(label) ? 'primary' : 'secondary'

  return `flow-edge flow-edge-${kind}`
}

/**
 * The edges that give the flow its top-to-bottom order. Loop-backs return to an earlier node, so
 * handing them to the layout would make it treat every loop as a cycle and scramble the layers.
 */
export function layoutEdges(edges: readonly Edge[]): Edge[] {
  return edges.filter((edge) => !String(edge.class).includes('flow-edge-backward'))
}

function edgeColor(label: string | null): string {
  if (label !== null && EXCEPTIONAL.has(label)) {
    return theme.danger
  }

  if (label !== null && PRIMARY.has(label)) {
    return theme.accent
  }

  return theme.muted
}

/**
 * The control flow of one method: its calls, branches, loops and exits in execution order.
 * Returns nothing for a method without a body (abstract or interface methods).
 */
export function toMethodFlow(graph: Graph, methodId: string): { nodes: FlowNode[]; edges: Edge[] } {
  const methodName = graph.nodes.find((node) => node.id === methodId)?.name ?? ''

  const nodes: FlowNode[] = graph.nodes
    .filter((node) => node.parent === methodId && FLOW_KINDS.has(node.type))
    .map((node) => {
      const kind = node.type as FlowKind

      return {
        id: node.id,
        type: 'flow',
        position: { x: 0, y: 0 },
        data:
          kind === 'start'
            ? { kind, title: 'Start', subtitle: `${methodName}()` }
            : kind === 'end'
              ? { kind, title: 'End', subtitle: '' }
              : { kind, title: node.name, subtitle: lineRange(node) },
      }
    })

  const known = new Set(nodes.map((node) => node.id))

  const edges: Edge[] = graph.edges
    .filter((edge) => edge.type === 'flow' && known.has(edge.from) && known.has(edge.to))
    .map((edge, index) => {
      // A loop-back keeps its label (a `continue` after `true` still says "true") but is drawn as a loop-back.
      const backward = isBackward(edge.from, edge.to)

      return {
        id: `flow:${index}:${edge.from}>${edge.to}`,
        source: edge.from,
        target: edge.to,
        type: 'smoothstep',
        label: edge.label ?? undefined,
        class: backward ? 'flow-edge flow-edge-backward' : flowEdgeClass(edge.label),
        markerEnd: { type: 'arrowclosed' as MarkerType, color: backward ? theme.muted : edgeColor(edge.label) },
        ...(backward ? { sourceHandle: 'loop-out', targetHandle: 'loop-in', pathOptions: { offset: LOOP_OFFSET, borderRadius: 14 } } : {}),
      }
    })

  return { nodes, edges }
}
