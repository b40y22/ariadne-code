import { describe, expect, it } from 'vitest'
import { isInView, readableViewport } from './viewport'

const options = { padding: 20, minZoom: 0.8, maxZoom: 1.25 }
const pane = { width: 800, height: 600 }

describe('readableViewport', () => {
  it('fits a small graph and centres it', () => {
    const view = readableViewport({ minX: 0, minY: 0, maxX: 400, maxY: 200 }, pane, options)

    expect(view.zoom).toBeCloseTo(1.25)
    expect(view.x).toBeCloseTo((800 - 400 * 1.25) / 2)
    expect(view.y).toBeCloseTo((600 - 200 * 1.25) / 2)
  })

  it('zooms out to fit a graph that is a little too big', () => {
    // 760x560 is free after padding, so the 600px-high graph is limited by height: 560 / 600.
    const view = readableViewport({ minX: 0, minY: 0, maxX: 700, maxY: 600 }, pane, options)

    expect(view.zoom).toBeCloseTo(560 / 600, 5)
  })

  it('never zooms below the readable minimum and pins a tall graph to the top', () => {
    const view = readableViewport({ minX: 0, minY: 0, maxX: 300, maxY: 2000 }, pane, options)

    expect(view.zoom).toBe(0.8)
    expect(view.y).toBe(20)
    expect(view.x).toBeCloseTo((800 - 300 * 0.8) / 2)
  })

  it('accounts for a graph that does not start at the origin', () => {
    const view = readableViewport({ minX: 100, minY: 50, maxX: 500, maxY: 250 }, pane, options)

    // The graph's left edge lands where it would for a graph at the origin, shifted by its own offset.
    expect(view.x + 100 * view.zoom).toBeCloseTo((800 - 400 * view.zoom) / 2)
    expect(view.y + 50 * view.zoom).toBeCloseTo((600 - 200 * view.zoom) / 2)
  })

  it('survives a pane that has not been measured yet', () => {
    const view = readableViewport({ minX: 0, minY: 0, maxX: 100, maxY: 100 }, { width: 0, height: 0 }, options)

    expect(Number.isFinite(view.x) && Number.isFinite(view.y) && Number.isFinite(view.zoom)).toBe(true)
  })
})

describe('isInView', () => {
  const view = { x: 0, y: 0, zoom: 1 }
  const box = { width: 100, height: 50 }

  it('accepts a rectangle well inside the pane', () => {
    expect(isInView({ x: 200, y: 200, ...box }, view, pane)).toBe(true)
  })

  it('rejects one below the visible area', () => {
    expect(isInView({ x: 200, y: 900, ...box }, view, pane)).toBe(false)
  })

  it('rejects one that touches the edge, inside the margin', () => {
    expect(isInView({ x: 10, y: 200, ...box }, view, pane)).toBe(false)
    expect(isInView({ x: 200, y: 560, ...box }, view, pane)).toBe(false)
  })

  it('accounts for pan and zoom', () => {
    // At zoom 2 and panned up by 500px, graph y=300 lands at screen y=100.
    expect(isInView({ x: 100, y: 300, ...box }, { x: 0, y: -500, zoom: 2 }, pane)).toBe(true)
    expect(isInView({ x: 100, y: 300, ...box }, { x: 0, y: 0, zoom: 2 }, pane)).toBe(false)
  })
})
