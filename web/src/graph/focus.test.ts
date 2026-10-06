import { describe, expect, it } from 'vitest'
import { busiestUnit, focusGraph, focusUnits, unitOf, withFlow } from './focus'
import type { Graph } from './types'

const node = (id: string, type: Graph['nodes'][number]['type'], parent: string | null = null) => ({
  id,
  type,
  name: id.replace(/^[a-z]+:/, ''),
  file: 'x.php',
  lineStart: 1,
  lineEnd: 2,
  parent,
})

const edge = (from: string, to: string, type: Graph['edges'][number]['type']) => ({ from, to, type, line: null, label: null })

// Job::run calls Repo::save and an external; Mailer::send calls Job::run; Repo::save calls Mailer::send.
const graph: Graph = {
  nodes: [
    node('class:Job', 'class'),
    node('method:Job::run', 'method'),
    node('method:Job::idle', 'method'),
    node('class:Repo', 'class'),
    node('method:Repo::save', 'method'),
    node('method:Repo::find', 'method'),
    node('class:Mailer', 'class'),
    node('method:Mailer::send', 'method'),
    node('function:helper', 'function'),
    node('external:carbon::now', 'external'),
    node('flow:method:Job::run#1', 'start', 'method:Job::run'),
  ],
  edges: [
    edge('class:Job', 'method:Job::run', 'contains'),
    edge('class:Job', 'method:Job::idle', 'contains'),
    edge('class:Repo', 'method:Repo::save', 'contains'),
    edge('class:Repo', 'method:Repo::find', 'contains'),
    edge('class:Mailer', 'method:Mailer::send', 'contains'),
    edge('method:Job::run', 'method:Repo::save', 'calls'),
    edge('method:Job::run', 'external:carbon::now', 'calls'),
    edge('method:Mailer::send', 'method:Job::run', 'calls'),
    edge('method:Repo::save', 'method:Mailer::send', 'calls'),
  ],
}

const ids = (g: Graph) => g.nodes.map((n) => n.id)

describe('focusGraph', () => {
  it('keeps the unit with all its methods, its callers and callees, and their classes', () => {
    expect(ids(focusGraph(graph, 'class:Job'))).toEqual([
      'class:Job',
      'method:Job::run',
      'method:Job::idle',
      'class:Repo',
      'method:Repo::save',
      'class:Mailer',
      'method:Mailer::send',
      'external:carbon::now',
    ])
  })

  it('shows a neighbour class with only the methods involved', () => {
    const focused = focusGraph(graph, 'class:Job')

    expect(ids(focused)).not.toContain('method:Repo::find')
    expect(focused.edges).toContainEqual(edge('class:Repo', 'method:Repo::save', 'contains'))
  })

  it('draws only the calls that touch the unit', () => {
    const calls = focusGraph(graph, 'class:Job').edges.filter((e) => e.type === 'calls').map((e) => `${e.from} -> ${e.to}`)

    expect(calls).toEqual([
      'method:Job::run -> method:Repo::save',
      'method:Job::run -> external:carbon::now',
      'method:Mailer::send -> method:Job::run',
    ])
  })

  it('focuses on a function alone when nothing calls it', () => {
    expect(ids(focusGraph(graph, 'function:helper'))).toEqual(['function:helper'])
  })
})

describe('unitOf', () => {
  it('finds the unit of a method, of a flow node and of a unit', () => {
    expect(unitOf(graph, 'method:Repo::save')).toBe('class:Repo')
    expect(unitOf(graph, 'flow:method:Job::run#1')).toBe('class:Job')
    expect(unitOf(graph, 'function:helper')).toBe('function:helper')
    expect(unitOf(graph, 'class:Mailer')).toBe('class:Mailer')
  })

  it('has nothing for call targets outside the project or unknown ids', () => {
    expect(unitOf(graph, 'external:carbon::now')).toBeUndefined()
    expect(unitOf(graph, 'nope')).toBeUndefined()
  })
})

describe('focusUnits and busiestUnit', () => {
  it('lists classes, functions and scripts by name', () => {
    expect(focusUnits(graph).map((u) => u.id)).toEqual(['function:helper', 'class:Job', 'class:Mailer', 'class:Repo'])
  })

  it('picks the unit with the most calls in and out', () => {
    expect(busiestUnit(graph)).toBe('class:Job')
  })

  it('has no busiest unit in an empty graph', () => {
    expect(busiestUnit({ nodes: [], edges: [] })).toBeUndefined()
  })
})

describe('withFlow', () => {
  it('adds the nodes and edges of a flow to the map', () => {
    const flow: Graph = { nodes: [node('flow:x#1', 'start', 'method:Job::run')], edges: [] }

    expect(ids(withFlow(focusGraph(graph, 'function:helper'), flow))).toEqual(['function:helper', 'flow:x#1'])
    expect(withFlow(graph, null)).toBe(graph)
  })
})
