import { describe, expect, it } from 'vitest'
import { widthOf } from './layout'
import { stepTargets, toMethodFlow } from './toMethodFlow'
import type { Graph, GraphEdge, GraphNode } from './types'

const node = (id: string, type: GraphNode['type'], parent: string | null = null): GraphNode => ({
  id,
  type,
  name: id,
  file: 'A.php',
  lineStart: 1,
  lineEnd: 1,
  parent,
})

const edge = (from: string, to: string, type: GraphEdge['type']): GraphEdge => ({ from, to, type, line: 1, label: null })

const M = 'method:A::a'
const graph: Graph = {
  nodes: [
    node(M, 'method'),
    node('method:A::b', 'method'),
    node('function:helper', 'function'),
    node('external:lib::x', 'external'),
    node('unresolved:$y->z', 'unresolved'),
    node(`flow:${M}#1`, 'start', M),
    node(`flow:${M}#2`, 'call', M),
    node(`flow:${M}#3`, 'call', M),
    node(`flow:${M}#4`, 'call', M),
    node(`flow:${M}#5`, 'builtin', M),
    node(`flow:${M}#6`, 'end', M),
    node('flow:method:A::b#2', 'call', 'method:A::b'),
  ],
  edges: [
    edge(`flow:${M}#2`, 'method:A::b', 'target'),
    edge(`flow:${M}#3`, 'external:lib::x', 'target'),
    edge(`flow:${M}#4`, 'unresolved:$y->z', 'target'),
    edge(`flow:${M}#5`, 'function:helper', 'target'),
    edge('flow:method:A::b#2', M, 'target'),
    edge(M, 'method:A::b', 'calls'),
  ],
}

describe('stepTargets', () => {
  it('maps the steps of one flow to the methods and functions they open', () => {
    expect([...stepTargets(graph, M)]).toEqual([
      [`flow:${M}#2`, 'method:A::b'],
      [`flow:${M}#5`, 'function:helper'],
    ])
  })

  it('opens nothing for external and unresolved targets', () => {
    const targets = stepTargets(graph, M)

    expect(targets.has(`flow:${M}#3`)).toBe(false)
    expect(targets.has(`flow:${M}#4`)).toBe(false)
  })

  it('puts the target on the data of the step', () => {
    const data = new Map(toMethodFlow(graph, M).nodes.map((n) => [n.id, n.data]))

    expect(data.get(`flow:${M}#2`)?.opens).toBe('method:A::b')
    expect(data.get(`flow:${M}#3`)?.opens).toBeUndefined()
  })

  it('draws no target edges in the flow', () => {
    expect(toMethodFlow(graph, M).edges).toEqual([])
  })
})

describe('widthOf a step that opens a flow', () => {
  it('leaves room for the mark', () => {
    const step = { id: 's', position: { x: 0, y: 0 }, data: { title: '$this->repository->create', subtitle: 'L34' } }

    expect(widthOf({ ...step, data: { ...step.data, opens: 'method:A::b' } })).toBe(widthOf(step) + 24)
  })
})
