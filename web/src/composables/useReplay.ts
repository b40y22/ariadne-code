import type { Edge, Node } from '@vue-flow/core'
import { computed, onBeforeUnmount, onMounted, ref, watch, type Ref, type ShallowRef } from 'vue'

import type { ExitChoice } from '../components/ReplayPanel.vue'
import { advance, current, exits, jumpTo, primaryExit, startReplay, stepBack, visitedNodes, walkedEdges, type Step } from '../graph/replay'
import { isBackward, type FlowNodeData } from '../graph/toMethodFlow'
import type { Selection, View } from '../view'

interface ReplaySources {
  view: Ref<View>
  nodes: ShallowRef<Node[]>
  edges: ShallowRef<Edge[]>
  selected: Ref<Selection | null>
}

/**
 * Execution replay: a walk through the flow of one method, a step at a time.
 *
 * The current step drives the selection, so the graph and the code follow it. The arrow keys step forward and
 * back, except while the editor or the pane divider has the focus: they use the arrows themselves.
 */
export function useReplay({ view, nodes, edges, selected }: ReplaySources) {
  const path = ref<Step[]>([])

  const flowData = computed(() => new Map(nodes.value.map((node) => [node.id, node.data as FlowNodeData])))
  const walked = computed(() => walkedEdges(path.value))
  const visited = computed<ReadonlySet<string>>(() => (view.value.kind === 'flow' ? visitedNodes(path.value) : new Set<string>()))

  /** The edges, with the ones already walked marked so they light up. */
  const shownEdges = computed<Edge[]>(() =>
    walked.value.size === 0
      ? edges.value
      : edges.value.map((edge) => (walked.value.has(edge.id) ? { ...edge, class: `${String(edge.class ?? '')} is-walked` } : edge)),
  )

  /** The ways out of the current step, the one that "next" would take first. */
  const choices = computed<ExitChoice[]>(() => {
    const here = current(path.value)
    const options = here === undefined ? [] : exits(edges.value, here.nodeId)
    const first = primaryExit(options, (edge) => isBackward(edge.source, edge.target))
    const ordered = first === undefined ? options : [first, ...options.filter((edge) => edge !== first)]

    return ordered.map((edge) => ({
      edgeId: edge.id,
      label: edge.label === undefined ? null : String(edge.label),
      targetTitle: flowData.value.get(edge.target)?.title ?? edge.target,
    }))
  })

  const atEnd = computed(() => flowData.value.get(current(path.value)?.nodeId ?? '')?.kind === 'end')

  function takeExit(edgeId: string): void {
    const edge = edges.value.find((candidate) => candidate.id === edgeId)

    if (edge !== undefined) {
      path.value = advance(path.value, edge)
    }
  }

  function next(): void {
    const first = choices.value[0]

    if (first !== undefined) {
      takeExit(first.edgeId)
    }
  }

  /** Starts over at the entry node of a flow, or clears the replay when there is none (the class map). */
  function begin(entryId: string | undefined): void {
    path.value = entryId === undefined ? [] : startReplay(entryId)
  }

  const back = (): void => {
    path.value = stepBack(path.value)
  }
  const restart = (): void => {
    path.value = path.value.slice(0, 1)
  }
  const jump = (index: number): void => {
    path.value = jumpTo(path.value, index)
  }

  // The replay drives the selection: the current step is highlighted in the graph and in the code.
  watch(path, (steps) => {
    const here = current(steps)

    if (view.value.kind === 'flow' && here !== undefined) {
      selected.value = { id: here.nodeId, reveal: true }
    }
  })

  function onKey(event: KeyboardEvent): void {
    const inControl = event.target instanceof Element && event.target.closest('.monaco-editor, [role="separator"], input, textarea') !== null

    if (view.value.kind !== 'flow' || inControl || event.altKey || event.ctrlKey || event.metaKey) {
      return
    }

    if (event.key === 'ArrowRight') {
      event.preventDefault()
      next()
    } else if (event.key === 'ArrowLeft') {
      event.preventDefault()
      back()
    }
  }

  onMounted(() => window.addEventListener('keydown', onKey))
  onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

  return { path, flowData, visited, shownEdges, choices, atEnd, takeExit, begin, back, restart, jump }
}
