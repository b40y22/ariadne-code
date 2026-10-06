import type { Node } from '@vue-flow/core'
import { describe, expect, it } from 'vitest'
import { applyPositions, clearPositions, loadPositions, savePositions } from './positions'

const node = (id: string, x: number, y: number): Node => ({ id, position: { x, y }, label: id })

function memoryStorage(initial: Record<string, string> = {}) {
  const data = new Map(Object.entries(initial))

  return {
    data,
    getItem: (key: string) => data.get(key) ?? null,
    setItem: (key: string, value: string) => void data.set(key, value),
    removeItem: (key: string) => void data.delete(key),
  }
}

describe('positions', () => {
  it('round-trips rounded positions through storage', () => {
    const storage = memoryStorage()

    savePositions(storage, 'k', [node('a', 10.4, 20.6), node('b', -5, 0)])

    expect(loadPositions(storage, 'k')).toEqual({ a: { x: 10, y: 21 }, b: { x: -5, y: 0 } })
  })

  it('remembers the size of resizable class groups only', () => {
    const storage = memoryStorage()
    const group = { id: 'g', type: 'class-group', position: { x: 1, y: 2 }, dimensions: { width: 300.4, height: 200.6 } }
    const plain = { id: 'm', type: 'code', position: { x: 5, y: 6 }, dimensions: { width: 190, height: 58 } }

    savePositions(storage, 'k', [group, plain])

    expect(loadPositions(storage, 'k')).toEqual({ g: { x: 1, y: 2, width: 300, height: 201 }, m: { x: 5, y: 6 } })
  })

  it('restores a saved size on class groups but not on other nodes', () => {
    const nodes: Node[] = [
      { id: 'g', type: 'class-group', position: { x: 0, y: 0 }, style: { width: '100px', height: '80px' } },
      { id: 'm', type: 'code', position: { x: 0, y: 0 }, style: { width: '190px', height: '58px' } },
    ]

    const [group, method] = applyPositions(nodes, { g: { x: 9, y: 9, width: 400, height: 300 }, m: { x: 4, y: 4, width: 1, height: 1 } })

    expect(group?.style).toEqual({ width: '400px', height: '300px' })
    expect(method?.style).toEqual({ width: '190px', height: '58px' })
  })

  it('ignores a half-saved size', () => {
    expect(loadPositions(memoryStorage({ k: '{"g":{"x":1,"y":2,"width":300}}' }), 'k')).toEqual({ g: { x: 1, y: 2 } })
  })

  it('treats a missing key as nothing saved', () => {
    expect(loadPositions(memoryStorage(), 'k')).toEqual({})
  })

  it('ignores corrupt or malformed data', () => {
    expect(loadPositions(memoryStorage({ k: '{not json' }), 'k')).toEqual({})
    expect(loadPositions(memoryStorage({ k: '42' }), 'k')).toEqual({})
    expect(loadPositions(memoryStorage({ k: '{"a":{"x":1,"y":2},"b":{"x":"1"},"c":null}' }), 'k')).toEqual({
      a: { x: 1, y: 2 },
    })
  })

  it('survives a storage that throws', () => {
    const broken = {
      getItem: () => {
        throw new Error('denied')
      },
      setItem: () => {
        throw new Error('full')
      },
      removeItem: () => {
        throw new Error('denied')
      },
    }

    expect(loadPositions(broken, 'k')).toEqual({})
    expect(() => savePositions(broken, 'k', [node('a', 1, 2)])).not.toThrow()
    expect(() => clearPositions(broken, 'k')).not.toThrow()
  })

  it('clears saved positions', () => {
    const storage = memoryStorage({ k: '{"a":{"x":1,"y":2}}' })

    clearPositions(storage, 'k')

    expect(loadPositions(storage, 'k')).toEqual({})
  })

  it('moves only the nodes that have a saved position', () => {
    const result = applyPositions([node('a', 0, 0), node('b', 5, 5)], { a: { x: 100, y: 200 } })

    expect(result.map((n) => n.position)).toEqual([
      { x: 100, y: 200 },
      { x: 5, y: 5 },
    ])
  })

  it('does not mutate the input nodes', () => {
    const input = [node('a', 0, 0)]

    applyPositions(input, { a: { x: 9, y: 9 } })

    expect(input[0]?.position).toEqual({ x: 0, y: 0 })
  })
})
