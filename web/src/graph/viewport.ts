export interface Bounds {
  minX: number
  minY: number
  maxX: number
  maxY: number
}

export interface Viewport {
  x: number
  y: number
  zoom: number
}

export interface FitOptions {
  /** Space kept free around the graph, in screen pixels. */
  padding: number
  minZoom: number
  maxZoom: number
}

/**
 * The viewport that shows a graph at a readable size.
 *
 * It zooms to fit like `fitView`, but never below `minZoom`. A tall graph then no longer shrinks until its
 * text is unreadable; it is anchored at the top instead, so the reader starts at the entry point and pans down.
 */
export function readableViewport(bounds: Bounds, pane: { width: number; height: number }, options: FitOptions): Viewport {
  const width = Math.max(1, bounds.maxX - bounds.minX)
  const height = Math.max(1, bounds.maxY - bounds.minY)
  const available = { width: Math.max(1, pane.width - options.padding * 2), height: Math.max(1, pane.height - options.padding * 2) }

  const fit = Math.min(available.width / width, available.height / height)
  const zoom = Math.min(options.maxZoom, Math.max(options.minZoom, fit))

  return {
    zoom,
    x: (pane.width - width * zoom) / 2 - bounds.minX * zoom,
    // Centred when the graph fits, otherwise pinned to the top edge.
    y: height * zoom <= available.height ? (pane.height - height * zoom) / 2 - bounds.minY * zoom : options.padding - bounds.minY * zoom,
  }
}

export interface Rect {
  x: number
  y: number
  width: number
  height: number
}

/** Whether a graph-space rectangle is fully visible in the pane, keeping `margin` pixels clear of its edges. */
export function isInView(rect: Rect, viewport: Viewport, pane: { width: number; height: number }, margin = 60): boolean {
  const left = rect.x * viewport.zoom + viewport.x
  const top = rect.y * viewport.zoom + viewport.y

  return (
    left >= margin &&
    top >= margin &&
    left + rect.width * viewport.zoom <= pane.width - margin &&
    top + rect.height * viewport.zoom <= pane.height - margin
  )
}
