import type { Graph } from './graph/types'

export class AnalyzeError extends Error {}

/** Sends source code to the analyzer API. The server only parses it; nothing is stored. */
export async function analyze(code: string, file: string): Promise<Graph> {
  let response: Response

  try {
    response = await fetch('/api/analyze', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code, file }),
    })
  } catch {
    throw new AnalyzeError('Cannot reach the analyzer API. Is it running?')
  }

  const payload: unknown = await response.json().catch(() => null)

  if (!response.ok) {
    const message =
      typeof payload === 'object' && payload !== null && 'error' in payload ? String(payload.error) : response.statusText
    throw new AnalyzeError(message)
  }

  return payload as Graph
}
