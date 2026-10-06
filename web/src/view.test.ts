import { describe, expect, it } from 'vitest'
import { hashFor, MAP, sameView, viewFromHash } from './view'

describe('view and hash', () => {
  it('round-trips a method id with colons and backslashes', () => {
    const view = { kind: 'flow', methodId: 'method:App\\Services\\OrderService::createOrder' } as const

    expect(viewFromHash(hashFor(view))).toEqual(view)
  })

  it('round-trips ids with spaces, plus signs and hashes', () => {
    const view = { kind: 'flow', methodId: 'function:a b+c#d' } as const

    expect(viewFromHash(hashFor(view))).toEqual(view)
  })

  it('treats an empty or unrelated hash as the class map', () => {
    expect(viewFromHash('')).toBe(MAP)
    expect(viewFromHash('#')).toBe(MAP)
    expect(viewFromHash('#other=1')).toBe(MAP)
  })

  it('treats an empty method as the class map', () => {
    expect(viewFromHash('#method=')).toBe(MAP)
  })

  it('writes nothing for the class map', () => {
    expect(hashFor(MAP)).toBe('')
  })

  it('compares views by kind and method', () => {
    expect(sameView(MAP, { kind: 'map' })).toBe(true)
    expect(sameView(MAP, { kind: 'flow', methodId: 'a' })).toBe(false)
    expect(sameView({ kind: 'flow', methodId: 'a' }, { kind: 'flow', methodId: 'a' })).toBe(true)
    expect(sameView({ kind: 'flow', methodId: 'a' }, { kind: 'flow', methodId: 'b' })).toBe(false)
    expect(sameView({ kind: 'flow', methodId: 'a' }, MAP)).toBe(false)
  })
})
