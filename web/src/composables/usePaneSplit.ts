import { ref, useTemplateRef } from 'vue'

import { readStoredNumber, writeStored } from '../storage'
import { clampSplit, DEFAULT_SPLIT, splitFromPointer } from '../split'

const SPLIT_KEY = 'ariadne:split'

/** The draggable divider between the graph and the code: its position, and the handlers that move it. */
export function usePaneSplit() {
  const split = ref(clampSplit(readStoredNumber(SPLIT_KEY, DEFAULT_SPLIT)))
  // The element carrying `ref="panes"` in the component that uses this.
  const panes = useTemplateRef<HTMLElement>('panes')
  let resizing = false

  const save = (): void => writeStored(SPLIT_KEY, split.value)

  function startResize(event: PointerEvent): void {
    resizing = true
    ;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
  }

  function onResize(event: PointerEvent): void {
    if (!resizing || !panes.value) {
      return
    }

    const box = panes.value.getBoundingClientRect()
    split.value = splitFromPointer(event.clientX, box.left, box.width)
  }

  function endResize(): void {
    if (resizing) {
      resizing = false
      save()
    }
  }

  function nudgeSplit(event: KeyboardEvent): void {
    const step = event.key === 'ArrowLeft' ? -2 : event.key === 'ArrowRight' ? 2 : 0

    if (step !== 0) {
      split.value = clampSplit(split.value + step)
      save()
    }
  }

  return { split, startResize, onResize, endResize, nudgeSplit }
}
