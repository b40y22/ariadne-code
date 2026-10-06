import type { Edge } from '@vue-flow/core'
import { describe, expect, it } from 'vitest'
import { advance, current, exits, jumpTo, MAX_STEPS, primaryExit, startReplay, stepBack, visitedNodes, walkedEdges } from './replay'

const edge = (id: string, source: string, target: string, label?: string): Edge => ({ id, source, target, label })

// start -> cond -(true)-> a -> end
//                -(false)-> b -> end
const edges: Edge[] = [
  edge('e1', 'start', 'cond'),
  edge('e2', 'cond', 'a', 'true'),
  edge('e3', 'cond', 'b', 'false'),
  edge('e4', 'a', 'end'),
  edge('e5', 'b', 'end'),
]

describe('exits', () => {
  it('lists the edges leaving a node in order', () => {
    expect(exits(edges, 'cond').map((e) => e.id)).toEqual(['e2', 'e3'])
  })

  it('has none for the end', () => {
    expect(exits(edges, 'end')).toEqual([])
  })
})

describe('primaryExit', () => {
  it('takes the only edge', () => {
    expect(primaryExit(exits(edges, 'start'))?.id).toBe('e1')
  })

  it('prefers the main branch of a condition', () => {
    expect(primaryExit(exits(edges, 'cond'))?.id).toBe('e2')
  })

  it('prefers a plain edge over a labelled one', () => {
    expect(primaryExit([edge('x', 'n', 'p', 'false'), edge('y', 'n', 'q')])?.id).toBe('y')
  })

  it('prefers a loop body over its exit', () => {
    expect(primaryExit([edge('x', 'loop', 'after', 'exit'), edge('y', 'loop', 'body', 'body')])?.id).toBe('y')
  })

  it('falls back to the first edge', () => {
    expect(primaryExit([edge('x', 'n', 'p', 'exit'), edge('y', 'n', 'q', 'exception')])?.id).toBe('x')
  })

  it('avoids going back into a loop while something forward exists', () => {
    const back = edge('b', 'n', 'loop', 'next')
    const forward = edge('f', 'n', 'out', 'exit')

    expect(primaryExit([back, forward], (e) => e.id === 'b')?.id).toBe('f')
    expect(primaryExit([back], (e) => e.id === 'b')?.id).toBe('b')
  })

  it('has nothing to take at a dead end', () => {
    expect(primaryExit([])).toBeUndefined()
  })
})

describe('advance and step back', () => {
  it('walks a path and records the branch taken', () => {
    let path = startReplay('start')
    path = advance(path, edge('e1', 'start', 'cond'))
    path = advance(path, edge('e3', 'cond', 'b', 'false'))

    expect(path.map((s) => s.nodeId)).toEqual(['start', 'cond', 'b'])
    expect(current(path)).toEqual({ nodeId: 'b', viaEdgeId: 'e3', label: 'false' })
  })

  it('ignores an edge that does not leave the current node', () => {
    const path = advance(startReplay('start'), edge('e4', 'a', 'end'))

    expect(path).toHaveLength(1)
  })

  it('goes back one step but never past the start', () => {
    const path = advance(startReplay('start'), edge('e1', 'start', 'cond'))

    expect(stepBack(path).map((s) => s.nodeId)).toEqual(['start'])
    expect(stepBack(stepBack(path)).map((s) => s.nodeId)).toEqual(['start'])
  })

  it('lets a loop be walked again', () => {
    let path = startReplay('loop')
    path = advance(path, edge('b', 'loop', 'body', 'body'))
    path = advance(path, edge('n', 'body', 'loop', 'next'))
    path = advance(path, edge('b', 'loop', 'body', 'body'))

    expect(path.map((s) => s.nodeId)).toEqual(['loop', 'body', 'loop', 'body'])
  })

  it('stops growing at the step limit', () => {
    let path = startReplay('a')

    for (let i = 0; i < MAX_STEPS + 20; i++) {
      path = advance(path, edge('x', current(path)?.nodeId ?? '', current(path)?.nodeId === 'a' ? 'b' : 'a'))
    }

    expect(path).toHaveLength(MAX_STEPS)
  })

  it('does not mutate the path it was given', () => {
    const path = startReplay('start')

    advance(path, edge('e1', 'start', 'cond'))

    expect(path).toHaveLength(1)
  })
})

describe('jumpTo', () => {
  const path = [
    { nodeId: 'start', viaEdgeId: null, label: null },
    { nodeId: 'cond', viaEdgeId: 'e1', label: null },
    { nodeId: 'a', viaEdgeId: 'e2', label: 'true' },
  ]

  it('discards everything after the chosen step', () => {
    expect(jumpTo(path, 1).map((s) => s.nodeId)).toEqual(['start', 'cond'])
  })

  it('ignores an index outside the path', () => {
    expect(jumpTo(path, 9)).toHaveLength(3)
    expect(jumpTo(path, -1)).toHaveLength(3)
  })

  it('reports what was walked', () => {
    expect([...walkedEdges(path)]).toEqual(['e1', 'e2'])
    expect([...visitedNodes(path)]).toEqual(['start', 'cond', 'a'])
  })
})
