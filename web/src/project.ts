import { request } from './api'
import type { Graph } from './graph/types'

/** What `GET /api/project` answers: a directory analyzed as one project, with the map of its graph. */
export interface ProjectOverview {
  name: string
  /** Paths relative to the project root. */
  files: string[]
  /** Files that did not parse, with the parser's message. */
  errors: string[]
  /** Declarations and the calls between them; the flow of each method comes from {@link fetchFlow}. */
  graph: Graph
}

function get<T>(path: string, query: Record<string, string> = {}): Promise<T> {
  const search = new URLSearchParams(query).toString()

  return request(search === '' ? path : `${path}?${search}`)
}

export const fetchProject = (): Promise<ProjectOverview> => get('/api/project')

export const fetchFlow = (id: string): Promise<Graph> => get('/api/project/flow', { id })

export async function fetchSource(file: string): Promise<string> {
  return (await get<{ code: string }>('/api/project/source', { file })).code
}

/** The page is in project mode when its address asks for it: `http://localhost:5180/?project`. */
export const isProjectMode = (search: string): boolean => new URLSearchParams(search).has('project')
