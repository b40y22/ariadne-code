import type { Edge, MarkerType, Node } from '@vue-flow/core'
import { theme } from '../theme'
import { lineRange } from './toFlow'
import type { Graph, NodeType } from './types'

export type FlowKind = 'start' | 'end' | 'call' | 'builtin' | 'condition' | 'loop' | 'try' | 'catch' | 'finally' | 'return' | 'throw'

/** What the `flow` node component renders. */
export interface FlowNodeData {
  kind: FlowKind
  title: string
  subtitle: string
  /** For a call step: the method or function it calls, when that has a flow of its own to open. */
  opens?: string
}

/** The kinds of node that have a flow of their own. */
const HAS_FLOW: ReadonlySet<NodeType> = new Set<NodeType>(['method', 'function', 'script'])

/**
 * The call steps of a flow whose call reaches a method or function with a flow of its own, by the analyzer's
 * `target` edges. A call that stays unresolved or ends outside the analyzed files opens nothing.
 */
export function stepTargets(graph: Graph, methodId: string): Map<string, string> {
  const steps = new Set(graph.nodes.filter((node) => node.parent === methodId).map((node) => node.id))
  const withFlow = new Set(graph.nodes.filter((node) => HAS_FLOW.has(node.type)).map((node) => node.id))
  const targets = new Map<string, string>()

  for (const edge of graph.edges) {
    if (edge.type === 'target' && steps.has(edge.from) && withFlow.has(edge.to)) {
      targets.set(edge.from, edge.to)
    }
  }

  return targets
}

/** A Vue Flow node that is known to carry its display data. */
export type FlowNode = Node<FlowNodeData> & { data: FlowNodeData }

const FLOW_KINDS: ReadonlySet<NodeType> = new Set<NodeType>([
  'start',
  'end',
  'call',
  'builtin',
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
/** The edge from a call into the body of a callback passed to it. */
const CALLBACK = 'callback'

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

  const kind = EXCEPTIONAL.has(label)
    ? 'exceptional'
    : BACKWARD.has(label)
      ? 'backward'
      : label === CALLBACK
        ? 'callback'
        : PRIMARY.has(label)
          ? 'primary'
          : 'secondary'

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

interface FlowEdgeSpec {
  from: string
  to: string
  label: string | null
}

/**
 * Removes the hidden nodes from a flow and joins their neighbours, so the path through a hidden step is
 * still one path. The branch label of the first labelled edge on the way is kept: `true`, then a hidden call,
 * then the next step stays "true".
 */
export function bypass(edges: readonly FlowEdgeSpec[], hidden: ReadonlySet<string>): FlowEdgeSpec[] {
  const out = new Map<string, FlowEdgeSpec[]>()

  for (const edge of edges) {
    out.set(edge.from, [...(out.get(edge.from) ?? []), edge])
  }

  const result: FlowEdgeSpec[] = []
  const seen = new Set<string>()

  const follow = (from: string, node: string, label: string | null, path: ReadonlySet<string>): void => {
    if (!hidden.has(node)) {
      const key = `${from}>${node}>${label ?? ''}`

      if (from !== node && !seen.has(key)) {
        seen.add(key)
        result.push({ from, to: node, label })
      }

      return
    }

    // A cycle made only of hidden nodes has no way out to show.
    if (path.has(node)) {
      return
    }

    for (const next of out.get(node) ?? []) {
      follow(from, next.to, label ?? next.label, new Set([...path, node]))
    }
  }

  for (const edge of edges) {
    if (!hidden.has(edge.from)) {
      follow(edge.from, edge.to, edge.label, new Set())
    }
  }

  return result
}

export interface FlowOptions {
  /** Show the steps for common pure functions (`count`, `trim`...). They are noise more often than not. */
  includeBuiltins?: boolean
}

/** How many steps of a method are builtins, that is, how many the toggle would reveal. */
export function countBuiltins(graph: Graph, methodId: string): number {
  return graph.nodes.filter((node) => node.parent === methodId && node.type === 'builtin').length
}

/**
 * The control flow of one method: its calls, branches, loops and exits in execution order.
 * Returns nothing for a method without a body (abstract or interface methods).
 */
export function toMethodFlow(graph: Graph, methodId: string, options: FlowOptions = {}): { nodes: FlowNode[]; edges: Edge[] } {
  const includeBuiltins = options.includeBuiltins ?? true
  const owner = graph.nodes.find((node) => node.id === methodId)
  // A script is named after its file, which is not something that can be called.
  const entry = owner?.type === 'script' ? (owner.name) : `${owner?.name ?? ''}()`
  const targets = stepTargets(graph, methodId)

  const nodes: FlowNode[] = graph.nodes
    .filter((node) => node.parent === methodId && FLOW_KINDS.has(node.type) && (includeBuiltins || node.type !== 'builtin'))
    .map((node) => {
      const kind = node.type as FlowKind

      return {
        id: node.id,
        type: 'flow',
        position: { x: 0, y: 0 },
        data:
          kind === 'start'
            ? { kind, title: 'Start', subtitle: entry }
            : kind === 'end'
              ? { kind, title: 'End', subtitle: '' }
              : { kind, title: node.name, subtitle: lineRange(node), ...(targets.has(node.id) ? { opens: targets.get(node.id) } : {}) },
      }
    })

  const known = new Set(nodes.map((node) => node.id))
  const hidden = new Set(graph.nodes.filter((node) => node.parent === methodId && node.type === 'builtin' && !includeBuiltins).map((node) => node.id))

  const specs: FlowEdgeSpec[] = graph.edges
    .filter((edge) => edge.type === 'flow' && (known.has(edge.from) || hidden.has(edge.from)) && (known.has(edge.to) || hidden.has(edge.to)))
    .map((edge) => ({ from: edge.from, to: edge.to, label: edge.label }))

  const edges: Edge[] = (hidden.size === 0 ? specs : bypass(specs, hidden))
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
