import { describe, expect, it } from 'vitest'
import { clampSplit, DEFAULT_SPLIT, MAX_SPLIT, MIN_SPLIT, splitFromPointer } from './split'

describe('clampSplit', () => {
  it('keeps values inside the bounds', () => {
    expect(clampSplit(50)).toBe(50)
    expect(clampSplit(5)).toBe(MIN_SPLIT)
    expect(clampSplit(99)).toBe(MAX_SPLIT)
  })

  it('falls back to the default for non-numbers', () => {
    expect(clampSplit(Number.NaN)).toBe(DEFAULT_SPLIT)
    expect(clampSplit(Number.POSITIVE_INFINITY)).toBe(DEFAULT_SPLIT)
  })
})

describe('splitFromPointer', () => {
  it('maps the pointer to a percentage of the container', () => {
    expect(splitFromPointer(300, 100, 400)).toBe(50)
  })

  it('clamps a pointer dragged outside the container', () => {
    expect(splitFromPointer(-50, 100, 400)).toBe(MIN_SPLIT)
    expect(splitFromPointer(900, 100, 400)).toBe(MAX_SPLIT)
  })

  it('ignores a zero-width container', () => {
    expect(splitFromPointer(10, 0, 0)).toBe(DEFAULT_SPLIT)
  })
})
