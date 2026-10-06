import { describe, expect, it } from 'vitest'
import { clampPanelHeight, DEFAULT_PANEL, MIN_GRAPH, MIN_PANEL, toggledPanelHeight } from './panel'

describe('clampPanelHeight', () => {
  it('keeps a reasonable height as it is', () => {
    expect(clampPanelHeight(300, 900)).toBe(300)
  })

  it('never goes below the minimum', () => {
    expect(clampPanelHeight(20, 900)).toBe(MIN_PANEL)
  })

  it('leaves the graph its room', () => {
    expect(clampPanelHeight(5000, 900)).toBe(900 - MIN_GRAPH)
  })

  it('still honours the minimum in a tiny window', () => {
    expect(clampPanelHeight(500, 200)).toBe(MIN_PANEL)
  })

  it('falls back to the default for non-numbers', () => {
    expect(clampPanelHeight(Number.NaN, 900)).toBe(DEFAULT_PANEL)
  })

  it('rounds to whole pixels', () => {
    expect(clampPanelHeight(240.6, 900)).toBe(241)
  })
})

describe('toggledPanelHeight', () => {
  it('grows to the maximum', () => {
    expect(toggledPanelHeight(DEFAULT_PANEL, 900)).toBe(900 - MIN_GRAPH)
  })

  it('returns to the default when already at the maximum', () => {
    expect(toggledPanelHeight(900 - MIN_GRAPH, 900)).toBe(DEFAULT_PANEL)
  })

  it('treats almost-maximum as maximum', () => {
    expect(toggledPanelHeight(900 - MIN_GRAPH - 5, 900)).toBe(DEFAULT_PANEL)
  })
})
