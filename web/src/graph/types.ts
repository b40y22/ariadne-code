/** The Code Graph contract produced by the analyzer (see the repository README). */

export type NodeType =
  | 'class'
  | 'method'
  | 'function'
  | 'script'
  | 'unresolved'
  | 'external'
  | 'start'
  | 'end'
  | 'call'
  | 'builtin'
  | 'condition'
  | 'loop'
  | 'try'
  | 'catch'
  | 'finally'
  | 'return'
  | 'throw'

export type EdgeType = 'contains' | 'calls' | 'flow' | 'target'

export interface GraphNode {
  id: string
  type: NodeType
  name: string
  file: string | null
  lineStart: number | null
  lineEnd: number | null
  /** Id of the method a flow node belongs to. */
  parent: string | null
}

export interface GraphEdge {
  from: string
  to: string
  type: EdgeType
  line: number | null
  label: string | null
}

export interface Graph {
  nodes: GraphNode[]
  edges: GraphEdge[]
}
