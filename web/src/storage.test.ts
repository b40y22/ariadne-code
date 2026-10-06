import { describe, expect, it } from 'vitest'
import { readStored, readStoredNumber, writeStored } from './storage'

const memory = (initial: Record<string, string> = {}) => {
  const data = new Map(Object.entries(initial))

  return { data, getItem: (key: string) => data.get(key) ?? null, setItem: (key: string, value: string) => void data.set(key, value) }
}

const broken = {
  getItem: () => {
    throw new Error('denied')
  },
  setItem: () => {
    throw new Error('full')
  },
}

describe('storage', () => {
  it('reads and writes strings and numbers', () => {
    const storage = memory()

    writeStored('a', 42, storage)
    writeStored('b', 'x', storage)

    expect(readStored('b', storage)).toBe('x')
    expect(readStoredNumber('a', 0, storage)).toBe(42)
  })

  it('falls back when a number is missing or not a number', () => {
    expect(readStoredNumber('missing', 7, memory())).toBe(7)
    expect(readStoredNumber('bad', 7, memory({ bad: 'abc' }))).toBe(7)
    expect(readStoredNumber('inf', 7, memory({ inf: 'Infinity' }))).toBe(7)
  })

  it('never throws when the storage does', () => {
    expect(readStored('a', broken)).toBeNull()
    expect(readStoredNumber('a', 3, broken)).toBe(3)
    expect(() => writeStored('a', 1, broken)).not.toThrow()
  })

  it('never throws when there is no storage at all, as in a bare runtime', () => {
    expect(readStored('a')).toBeNull()
    expect(() => writeStored('a', 1)).not.toThrow()
  })
})
