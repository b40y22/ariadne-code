import { describe, expect, it } from 'vitest'
import { nodeAtLine } from './lookup'
import { toClassMap } from './toFlow'
import type { Graph } from './types'

const node = (id: string, type: Graph['nodes'][number]['type'], lineStart: number | null = null, lineEnd: number | null = null) => ({
  id,
  type,
  name: id.replace(/^[a-z]+:/, '').split('::').pop() ?? id,
  file: 'A.php',
  lineStart,
  lineEnd,
  parent: null,
})

const edge = (from: string, to: string, type: Graph['edges'][number]['type'], line: number | null = null) => ({
  from,
  to,
  type,
  line,
  label: null,
})

const graph: Graph = {
  nodes: [
    node('class:A', 'class', 1, 20),
    node('method:A::a', 'method', 3, 9),
    node('method:A::b', 'method', 11, 15),
    node('unresolved:$x->go', 'unresolved'),
    { ...node('flow:method:A::a#1', 'start', 3, 9), parent: 'method:A::a' },
  ],
  edges: [
    edge('class:A', 'method:A::a', 'contains'),
    edge('class:A', 'method:A::b', 'contains'),
    edge('method:A::a', 'method:A::b', 'calls', 5),
    edge('method:A::a', 'method:A::b', 'calls', 7),
    edge('method:A::a', 'unresolved:$x->go', 'calls', 8),
    edge('flow:method:A::a#1', 'method:A::b', 'flow'),
  ],
}

describe('toClassMap', () => {
  it('keeps classes, methods and unresolved targets but drops flow nodes', () => {
    const { nodes } = toClassMap(graph)

    expect(nodes.map((n) => n.id)).toEqual(['class:A', 'method:A::a', 'method:A::b', 'unresolved:$x->go'])
  })

  it('labels methods with parentheses', () => {
    const titles = toClassMap(graph).nodes.map((n) => n.data.title)

    expect(titles).toContain('a()')
    expect(titles).toContain('A')
  })

  it('describes classes by method count and methods by line range', () => {
    const byId = new Map(toClassMap(graph).nodes.map((n) => [n.id, n.data]))

    expect(byId.get('class:A')).toMatchObject({ kind: 'class', subtitle: '2 methods' })
    expect(byId.get('method:A::a')).toMatchObject({ kind: 'method', subtitle: 'L3–9' })
    expect(byId.get('unresolved:$x->go')?.kind).toBe('unresolved')
  })

  it('nests methods inside their class instead of drawing containment edges', () => {
    const { nodes, edges } = toClassMap(graph)
    const byId = new Map(nodes.map((n) => [n.id, n]))

    expect(byId.get('method:A::a')).toMatchObject({ parentNode: 'class:A', extent: 'parent', type: 'code' })
    expect(byId.get('class:A')).toMatchObject({ type: 'class-group' })
    expect(byId.get('class:A')?.parentNode).toBeUndefined()
    expect(byId.get('unresolved:$x->go')?.parentNode).toBeUndefined()
    expect(edges.every((e) => e.class?.toString().includes('ariadne-edge-calls'))).toBe(true)
  })

  it('collapses repeated calls into one edge with a count', () => {
    const { edges } = toClassMap(graph)
    const call = edges.find((e) => e.source === 'method:A::a' && e.target === 'method:A::b')

    expect(call?.label).toBe('×2')
    expect(edges.filter((e) => e.source === 'method:A::a' && e.target === 'method:A::b')).toHaveLength(1)
  })

  it('leaves single calls unlabelled and omits flow edges', () => {
    const { edges } = toClassMap(graph)

    expect(edges.find((e) => e.target === 'unresolved:$x->go')?.label).toBeUndefined()
    expect(edges.some((e) => e.source.startsWith('flow:'))).toBe(false)
  })
})

describe('nodeAtLine', () => {
  it('prefers the method over its class', () => {
    expect(nodeAtLine(graph, 5)?.id).toBe('method:A::a')
  })

  it('falls back to the class between methods', () => {
    expect(nodeAtLine(graph, 10)?.id).toBe('class:A')
  })

  it('includes both boundary lines', () => {
    expect(nodeAtLine(graph, 3)?.id).toBe('method:A::a')
    expect(nodeAtLine(graph, 15)?.id).toBe('method:A::b')
  })

  it('returns nothing outside every range', () => {
    expect(nodeAtLine(graph, 99)).toBeUndefined()
  })
})
