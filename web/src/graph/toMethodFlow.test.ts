import { describe, expect, it } from 'vitest'
import { bypass, countBuiltins, flowEdgeClass, isBackward, layoutEdges, toMethodFlow } from './toMethodFlow'
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

describe('builtins', () => {
  // start -> b1 (builtin) -> call -> b2 (builtin) -> end, with a condition in front of the first builtin
  const withBuiltins: Graph = {
    nodes: [
      { id: M, type: 'method', name: 'a', file: 'A.php', lineStart: 1, lineEnd: 9, parent: null },
      flowNode(1, 'start', 'start', 1, 9),
      flowNode(2, 'condition', '$x', 2),
      flowNode(3, 'builtin', 'count', 3),
      flowNode(4, 'call', '$this->work', 4),
      flowNode(5, 'builtin', 'trim', 5),
      flowNode(6, 'end', 'end', 1, 9),
    ],
    edges: [flow(1, 2), flow(2, 3, 'true'), flow(3, 4), flow(4, 5), flow(5, 6), flow(2, 6, 'false')],
  }

  it('shows them by default', () => {
    expect(toMethodFlow(withBuiltins, M).nodes.map((n) => n.data.kind)).toContain('builtin')
  })

  it('counts them', () => {
    expect(countBuiltins(withBuiltins, M)).toBe(2)
  })

  it('removes the hidden steps and joins their neighbours', () => {
    const { nodes, edges } = toMethodFlow(withBuiltins, M, { includeBuiltins: false })

    expect(nodes.map((n) => n.data.title)).toEqual(['Start', '$x', '$this->work', 'End'])
    expect(edges.map((e) => `${e.source.split('#')[1]}>${e.target.split('#')[1]}${e.label ? ` [${e.label}]` : ''}`)).toEqual([
      '1>2',
      '2>4 [true]',
      '4>6',
      '2>6 [false]',
    ])
  })

  it('keeps every edge pointing at a node that exists', () => {
    const { nodes, edges } = toMethodFlow(withBuiltins, M, { includeBuiltins: false })
    const ids = new Set(nodes.map((n) => n.id))

    expect(edges.every((e) => ids.has(e.source) && ids.has(e.target))).toBe(true)
  })

  it('gives every joined edge a unique id', () => {
    const { edges } = toMethodFlow(withBuiltins, M, { includeBuiltins: false })

    expect(new Set(edges.map((e) => e.id)).size).toBe(edges.length)
  })
})

describe('bypass', () => {
  const e = (from: string, to: string, label: string | null = null) => ({ from, to, label })

  it('passes through a chain of hidden nodes', () => {
    expect(bypass([e('a', 'h1'), e('h1', 'h2'), e('h2', 'b')], new Set(['h1', 'h2']))).toEqual([e('a', 'b')])
  })

  it('keeps the first label found on the way', () => {
    expect(bypass([e('a', 'h', 'true'), e('h', 'b')], new Set(['h']))).toEqual([e('a', 'b', 'true')])
    expect(bypass([e('a', 'h'), e('h', 'b', 'next')], new Set(['h']))).toEqual([e('a', 'b', 'next')])
    expect(bypass([e('a', 'h', 'true'), e('h', 'b', 'next')], new Set(['h']))).toEqual([e('a', 'b', 'true')])
  })

  it('follows every way out of a hidden node', () => {
    expect(bypass([e('a', 'h'), e('h', 'b', 'x'), e('h', 'c', 'y')], new Set(['h']))).toEqual([e('a', 'b', 'x'), e('a', 'c', 'y')])
  })

  it('drops an edge that would loop a node onto itself', () => {
    expect(bypass([e('loop', 'h', 'body'), e('h', 'loop', 'next')], new Set(['h']))).toEqual([])
  })

  it('does not repeat an edge two paths both produce', () => {
    expect(bypass([e('a', 'h1'), e('a', 'h2'), e('h1', 'b'), e('h2', 'b')], new Set(['h1', 'h2']))).toEqual([e('a', 'b')])
  })

  it('survives a cycle made only of hidden nodes', () => {
    expect(bypass([e('a', 'h1'), e('h1', 'h2'), e('h2', 'h1')], new Set(['h1', 'h2']))).toEqual([])
  })

  it('leaves edges between visible nodes alone', () => {
    expect(bypass([e('a', 'b', 'true')], new Set(['h']))).toEqual([e('a', 'b', 'true')])
  })
})
