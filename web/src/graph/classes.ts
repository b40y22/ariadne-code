export interface NodeState {
  selected: boolean
  visited: boolean
}

/** The CSS classes of a node: its own, plus the states the reader should see. */
export function nodeClass(base: string, state: NodeState): string {
  return [base, state.visited ? 'is-visited' : '', state.selected ? 'is-selected' : ''].filter((part) => part !== '').join(' ')
}
