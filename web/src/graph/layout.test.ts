import type { Node } from '@vue-flow/core'
import { describe, expect, it } from 'vitest'
import { gridOf, GRID_THRESHOLD, layout, type LayoutNode } from './layout'

const method = (id: string, title = id): LayoutNode => ({ id, position: { x: 0, y: 0 }, data: { title, subtitle: 'L1' } }) as LayoutNode

const methods = (count: number): LayoutNode[] => Array.from({ length: count }, (_, i) => method(`m${i}`))

describe('gridOf', () => {
  it('fills rows left to right, in source order', () => {
    const grid = gridOf(methods(6), 0)

    const at = (id: string) => grid.cells.get(id)
    expect(at('m0')?.y).toBe(at('m3')?.y)
    expect(at('m4')?.y).toBeGreaterThan(at('m0')?.y ?? 0)
    expect(at('m1')?.x).toBeGreaterThan(at('m0')?.x ?? 0)
    expect(at('m4')?.x).toBe(at('m0')?.x)
  })

  it('uses four columns for a big class', () => {
    const columns = new Set([...gridOf(methods(20), 0).cells.values()].map((cell) => cell.x))

    expect(columns.size).toBe(4)
  })

  it('uses fewer columns when there are fewer children', () => {
    expect(new Set([...gridOf(methods(2), 0).cells.values()].map((cell) => cell.x)).size).toBe(2)
  })

  it('sizes a column by its widest member', () => {
    const grid = gridOf([method('a', 'x'), method('b', 'a-very-long-method-name-that-needs-room'), method('c', 'y'), method('d', 'z'), method('e', 'w')], 0)

    expect(grid.cells.get('b')?.width).toBeGreaterThan(grid.cells.get('a')?.width ?? 0)
    // `e` is in the same column as `a`, so it shares its width.
    expect(grid.cells.get('e')?.width).toBe(grid.cells.get('a')?.width)
  })

  it('keeps every cell inside the group, with room for its header', () => {
    const grid = gridOf(methods(13), 0)

    for (const cell of grid.cells.values()) {
      expect(cell.x).toBeGreaterThanOrEqual(22)
      expect(cell.y).toBeGreaterThanOrEqual(74)
      expect(cell.x + cell.width).toBeLessThanOrEqual(grid.width)
      expect(cell.y + cell.height).toBeLessThanOrEqual(grid.height)
    }
  })

  it('never makes the group narrower than its own title needs', () => {
    expect(gridOf(methods(9), 900).width).toBe(900)
  })

  it('does not let two cells overlap', () => {
    const cells = [...gridOf(methods(17), 0).cells.values()]

    for (const a of cells) {
      for (const b of cells) {
        if (a === b) continue
        const apart = a.x + a.width <= b.x || b.x + b.width <= a.x || a.y + a.height <= b.y || b.y + b.height <= a.y
        expect(apart).toBe(true)
      }
    }
  })
})

describe('layout of a big class', () => {
  const group = { id: 'class:A', position: { x: 0, y: 0 }, data: { title: 'A', subtitle: 'n methods' } } as LayoutNode
  const inside = (count: number): LayoutNode[] => methods(count).map((node) => ({ ...node, parentNode: 'class:A' }) as LayoutNode)

  it('puts methods in a grid and sizes the class around them', async () => {
    const children = inside(GRID_THRESHOLD + 4)
    const placed = await layout([group, ...children], [])
    const box = placed.find((node) => node.id === 'class:A')

    const width = Number.parseInt(String(box?.style && 'width' in box.style ? box.style.width : '0'))
    const height = Number.parseInt(String(box?.style && 'height' in box.style ? box.style.height : '0'))
    expect(width).toBeGreaterThan(0)

    for (const child of placed.filter((node) => node.parentNode === 'class:A')) {
      expect(child.position.x + 190).toBeLessThanOrEqual(width)
      expect(child.position.y + 58).toBeLessThanOrEqual(height)
    }
  })

  it('lays out the call edges of a grid class without failing, even between its own methods', async () => {
    const children = inside(GRID_THRESHOLD + 1)
    const edges = [
      { id: 'e1', source: 'm0', target: 'm1' },
      { id: 'e2', source: 'm1', target: 'outside' },
    ]
    const outside = { id: 'outside', position: { x: 0, y: 0 }, data: { title: 'x', subtitle: '' } } as LayoutNode

    const placed = await layout([group, ...children, outside], edges as never)

    expect(placed).toHaveLength(children.length + 2)
    expect(placed.every((node: Node) => Number.isFinite(node.position.x) && Number.isFinite(node.position.y))).toBe(true)
  })

  it('keeps a small class on the call-driven layout', async () => {
    const children = inside(3)
    const placed = await layout([group, ...children], [{ id: 'e', source: 'm0', target: 'm1' }] as never)

    expect(placed.filter((node) => node.parentNode === 'class:A')).toHaveLength(3)
  })
})
