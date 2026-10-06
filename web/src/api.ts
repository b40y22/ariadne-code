import type { Graph } from './graph/types'

export class AnalyzeError extends Error {}

/** Calls the analyzer API and reads its JSON answer; an error answer becomes an {@link AnalyzeError} with its message. */
export async function request<T>(url: string, init?: RequestInit): Promise<T> {
  let response: Response

  try {
    response = await fetch(url, init)
  } catch {
    throw new AnalyzeError('Cannot reach the analyzer API. Is it running?')
  }

  const payload: unknown = await response.json().catch(() => null)

  if (!response.ok) {
    const message =
      typeof payload === 'object' && payload !== null && 'error' in payload ? String(payload.error) : response.statusText
    throw new AnalyzeError(message)
  }

  return payload as T
}

/** Sends source code to the analyzer API. The server only parses it; nothing is stored. */
export function analyze(code: string, file: string): Promise<Graph> {
  return request('/api/analyze', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ code, file }),
  })
}
