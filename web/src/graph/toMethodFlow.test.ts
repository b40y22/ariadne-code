import { describe, expect, it } from 'vitest'
import { flowEdgeClass, isBackward, layoutEdges, toMethodFlow } from './toMethodFlow'
import type { Graph, GraphEdge, GraphNode } from './types'

const M = 'method:A::a'

const flowNode = (n: number, type: GraphNode['type'], name: string, lineStart: number | null = null, lineEnd: number | null = lineStart): GraphNode => ({
  id: `flow:${M}#${n}`,
  type,
  name,
  file: 'A.php',
  lineStart,
  lineEnd,
  parent: M,
})

const flow = (from: number, to: number, label: string | null = null): GraphEdge => ({
  from: `flow:${M}#${from}`,
  to: `flow:${M}#${to}`,
  type: 'flow',
  line: null,
  label,
})

const graph: Graph = {
  nodes: [
    { id: 'class:A', type: 'class', name: 'A', file: 'A.php', lineStart: 1, lineEnd: 30, parent: null },
    { id: M, type: 'method', name: 'a', file: 'A.php', lineStart: 3, lineEnd: 20, parent: null },
    flowNode(1, 'start', 'start', 3, 20),
    flowNode(2, 'condition', '$x', 5),
    flowNode(3, 'call', '$this->b', 6),
    flowNode(4, 'loop', 'foreach ($xs as $x)', 8, 12),
    flowNode(5, 'end', 'end', 3, 20),
    // A different method's flow must not leak in.
    { ...flowNode(1, 'start', 'start', 22, 30), id: 'flow:method:A::z#1', parent: 'method:A::z' },
  ],
  edges: [
    flow(1, 2),
    flow(2, 3, 'true'),
    flow(2, 4, 'false'),
    flow(4, 5, 'exit'),
    { from: 'flow:method:A::z#1', to: `flow:${M}#5`, type: 'flow', line: null, label: null },
    { from: M, to: 'unresolved:x', type: 'calls', line: 6, label: null },
  ],
}

describe('toMethodFlow', () => {
  it('keeps only the flow nodes that belong to the method', () => {
    const { nodes } = toMethodFlow(graph, M)

    expect(nodes.map((n) => n.data.kind)).toEqual(['start', 'condition', 'call', 'loop', 'end'])
    expect(nodes.every((n) => n.type === 'flow')).toBe(true)
  })

  it('keeps only flow edges between those nodes', () => {
    const { edges } = toMethodFlow(graph, M)

    expect(edges).toHaveLength(4)
    expect(edges.map((e) => e.label)).toEqual([undefined, 'true', 'false', 'exit'])
  })

  it('titles start with the method and everything else with its source text and line', () => {
    const byKind = new Map(toMethodFlow(graph, M).nodes.map((n) => [n.data.kind, n.data]))

    expect(byKind.get('start')).toEqual({ kind: 'start', title: 'Start', subtitle: 'a()' })
    expect(byKind.get('end')).toEqual({ kind: 'end', title: 'End', subtitle: '' })
    expect(byKind.get('call')).toEqual({ kind: 'call', title: '$this->b', subtitle: 'L6' })
    expect(byKind.get('loop')?.subtitle).toBe('L8–12')
  })

  it('gives every edge a unique id even when two edges join the same nodes', () => {
    const doubled: Graph = { ...graph, edges: [flow(2, 5, 'true'), flow(2, 5, 'false')] }
    const ids = toMethodFlow(doubled, M).edges.map((e) => e.id)

    expect(new Set(ids).size).toBe(2)
  })

  it('names the start of a script after its file and of a function after the call', () => {
    const script: Graph = {
      nodes: [
        { id: 'script:A.php', type: 'script', name: 'A.php', file: 'A.php', lineStart: 1, lineEnd: 9, parent: null },
        { ...flowNode(1, 'start', 'start', 1, 9), id: 'flow:script:A.php#1', parent: 'script:A.php' },
      ],
      edges: [],
    }
    const fn: Graph = {
      nodes: [
        { id: 'function:helper', type: 'function', name: 'helper', file: 'A.php', lineStart: 3, lineEnd: 5, parent: null },
        { ...flowNode(1, 'start', 'start', 3, 5), id: 'flow:function:helper#1', parent: 'function:helper' },
      ],
      edges: [],
    }

    expect(toMethodFlow(script, 'script:A.php').nodes[0]?.data.subtitle).toBe('A.php')
    expect(toMethodFlow(fn, 'function:helper').nodes[0]?.data.subtitle).toBe('helper()')
  })

  it('returns nothing for a method without flow', () => {
    expect(toMethodFlow(graph, 'method:A::abstract')).toEqual({ nodes: [], edges: [] })
  })
})

describe('layoutEdges', () => {
  it('leaves out loop-backs so the layout sees an acyclic flow', () => {
    const loop: Graph = { ...graph, edges: [flow(2, 3, 'body'), flow(3, 2, 'next'), flow(3, 2, 'continue'), flow(2, 5, 'exit')] }
    const labels = layoutEdges(toMethodFlow(loop, M).edges).map((e) => e.label)

    expect(labels).toEqual(['body', 'exit'])
  })

  it('recognises a loop-back by order even when its label is "true"', () => {
    const loop: Graph = { ...graph, edges: [flow(2, 3, 'body'), flow(3, 2, 'true'), flow(2, 5, 'exit')] }
    const { edges } = toMethodFlow(loop, M)

    expect(edges.map((e) => String(e.class).includes('backward'))).toEqual([false, true, false])
    expect(edges[1]?.label).toBe('true')
    expect(layoutEdges(edges).map((e) => e.label)).toEqual(['body', 'exit'])
  })

  it('keeps every other kind of edge', () => {
    const all = toMethodFlow(graph, M).edges

    expect(layoutEdges(all)).toHaveLength(all.length)
  })
})

describe('isBackward', () => {
  it('compares build order, not string order', () => {
    expect(isBackward(`flow:${M}#10`, `flow:${M}#9`)).toBe(true)
    expect(isBackward(`flow:${M}#9`, `flow:${M}#10`)).toBe(false)
    expect(isBackward(`flow:${M}#4`, `flow:${M}#4`)).toBe(true)
  })

  it('treats ids without an order as forward', () => {
    expect(isBackward('x', 'y')).toBe(false)
  })
})

describe('flowEdgeClass', () => {
  it.each([
    [null, 'flow-edge-plain'],
    ['true', 'flow-edge-primary'],
    ['body', 'flow-edge-primary'],
    ['false', 'flow-edge-secondary'],
    ['exit', 'flow-edge-secondary'],
    ['next', 'flow-edge-backward'],
    ['continue', 'flow-edge-backward'],
    ['callback', 'flow-edge-callback'],
    ['exception', 'flow-edge-exceptional'],
    ['throw', 'flow-edge-exceptional'],
  ])('maps %s to %s', (label, expected) => {
    expect(flowEdgeClass(label)).toContain(expected)
  })
})
