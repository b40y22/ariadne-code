import { describe, expect, it } from 'vitest'
import { nodeClass } from './classes'

describe('nodeClass', () => {
  it('returns the base class when nothing applies', () => {
    expect(nodeClass('ariadne-node', { selected: false, visited: false })).toBe('ariadne-node')
  })

  it('adds the states in a stable order', () => {
    expect(nodeClass('x', { selected: true, visited: true })).toBe('x is-visited is-selected')
    expect(nodeClass('x', { selected: true, visited: false })).toBe('x is-selected')
  })

  it('has no stray spaces without a base', () => {
    expect(nodeClass('', { selected: true, visited: false })).toBe('is-selected')
    expect(nodeClass('', { selected: false, visited: false })).toBe('')
  })
})
