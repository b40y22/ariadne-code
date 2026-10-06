<script setup lang="ts">
import type { Edge, Node } from '@vue-flow/core'
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'

import { AnalyzeError, analyze } from './api'
import CodeEditor, { type Highlight } from './components/CodeEditor.vue'
import GraphView from './components/GraphView.vue'
import ReplayPanel, { type ExitChoice } from './components/ReplayPanel.vue'
import { layout } from './graph/layout'
import { nodeAtLine } from './graph/lookup'
import { advance, current, exits, jumpTo, primaryExit, startReplay, stepBack, visitedNodes, walkedEdges, type Step } from './graph/replay'
import { toClassMap } from './graph/toFlow'
import { isBackward, layoutEdges, toMethodFlow, type FlowNodeData } from './graph/toMethodFlow'
import type { Graph } from './graph/types'
import { SAMPLE_CODE, SAMPLE_FILE } from './sample'
import { clampSplit, DEFAULT_SPLIT, splitFromPointer } from './split'

type View = { kind: 'map' } | { kind: 'flow'; methodId: string }

const MAP: View = { kind: 'map' }

const code = ref(SAMPLE_CODE)
const fileName = ref(SAMPLE_FILE)
const graph = ref<Graph | null>(null)
const view = ref<View>(MAP)
const nodes = shallowRef<Node[]>([])
const edges = shallowRef<Edge[]>([])
const selected = ref<{ id: string; reveal: boolean } | null>(null)
const error = ref<string | null>(null)
const loading = ref(false)
const graphView = ref<InstanceType<typeof GraphView>>()

// --- Execution replay: a walk through the method flow, one step at a time.
const path = ref<Step[]>([])
const flowData = computed(() => new Map(nodes.value.map((node) => [node.id, node.data as FlowNodeData])))
const walked = computed(() => walkedEdges(path.value))
const visited = computed<ReadonlySet<string>>(() => (view.value.kind === 'flow' ? visitedNodes(path.value) : new Set<string>()))

const shownEdges = computed<Edge[]>(() =>
  walked.value.size === 0 ? edges.value : edges.value.map((edge) => (walked.value.has(edge.id) ? { ...edge, class: `${String(edge.class ?? '')} is-walked` } : edge)),
)

const backward = (edge: Edge): boolean => isBackward(edge.source, edge.target)

/** The ways out of the current step, the one that "next" would take first. */
const choices = computed<ExitChoice[]>(() => {
  const here = current(path.value)
  const options = here === undefined ? [] : exits(edges.value, here.nodeId)
  const first = primaryExit(options, backward)
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

function stepNext(): void {
  const first = choices.value[0]

  if (first !== undefined) {
    takeExit(first.edgeId)
  }
}

// The replay drives the selection: the current step is highlighted in the graph and in the code.
watch(path, (steps) => {
  const here = current(steps)

  if (view.value.kind === 'flow' && here !== undefined) {
    selected.value = { id: here.nodeId, reveal: true }
  }
})

function onKey(event: KeyboardEvent): void {
  // Arrow keys belong to the editor and to the pane divider when they have the focus.
  const inControl = event.target instanceof Element && event.target.closest('.monaco-editor, [role="separator"], input, textarea') !== null

  if (view.value.kind !== 'flow' || inControl || event.altKey || event.ctrlKey || event.metaKey) {
    return
  }

  if (event.key === 'ArrowRight') {
    event.preventDefault()
    stepNext()
  } else if (event.key === 'ArrowLeft') {
    event.preventDefault()
    path.value = stepBack(path.value)
  }
}

onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

const flowMethodId = computed(() => (view.value.kind === 'flow' ? view.value.methodId : undefined))

// Each method flow keeps its own saved layout next to the class map's.
const layoutId = computed(() => (flowMethodId.value === undefined ? fileName.value : `${fileName.value}#${flowMethodId.value}`))

const selectedMethodId = computed(() => {
  const node = graph.value?.nodes.find((candidate) => candidate.id === selected.value?.id)

  return node?.type === 'method' ? node.id : undefined
})

const breadcrumb = computed(() => {
  const methodId = flowMethodId.value
  const current = graph.value

  if (methodId === undefined || current === null) {
    return null
  }

  const method = current.nodes.find((node) => node.id === methodId)
  const owner = current.edges.find((edge) => edge.type === 'contains' && edge.to === methodId)
  const cls = current.nodes.find((node) => node.id === owner?.from)

  return { cls: cls?.name ?? '', method: `${method?.name ?? ''}()` }
})

const highlight = computed<Highlight | null>(() => {
  const node = graph.value?.nodes.find((candidate) => candidate.id === selected.value?.id)

  if (!node || node.lineStart === null || node.lineEnd === null) {
    return null
  }

  return { start: node.lineStart, end: node.lineEnd, reveal: selected.value?.reveal ?? false }
})

const HASH_KEY = 'method'

function viewFromHash(): View {
  const methodId = new URLSearchParams(location.hash.slice(1)).get(HASH_KEY)

  return methodId === null ? MAP : { kind: 'flow', methodId }
}

function syncHash(next: View): void {
  const hash = next.kind === 'flow' ? `#${HASH_KEY}=${encodeURIComponent(next.methodId)}` : ''

  history.replaceState(null, '', location.pathname + location.search + hash)
}

/** Lays out and shows a view of the graph. Returns false, leaving the current view, when it cannot be shown. */
async function show(result: Graph, next: View): Promise<boolean> {
  if (next.kind === 'flow') {
    const flow = toMethodFlow(result, next.methodId)

    if (flow.nodes.length === 0) {
      error.value = 'This method has no body, so there is no flow to show.'

      return false
    }

    nodes.value = await layout(flow.nodes, layoutEdges(flow.edges), { direction: 'DOWN' })
    edges.value = flow.edges
    const entry = flow.nodes.find((node) => node.data.kind === 'start')
    path.value = entry === undefined ? [] : startReplay(entry.id)
  } else {
    path.value = []
    const map = toClassMap(result)

    nodes.value = await layout(map.nodes, map.edges)
    edges.value = map.edges
  }

  view.value = next
  selected.value = null
  syncHash(next)

  return true
}

async function run(): Promise<void> {
  loading.value = true
  error.value = null

  try {
    const result = await analyze(code.value, fileName.value)
    const wanted = graph.value === null ? viewFromHash() : view.value
    const stillThere = wanted.kind === 'map' || result.nodes.some((node) => node.id === wanted.methodId)

    graph.value = result

    if (!(await show(result, stillThere ? wanted : MAP))) {
      await show(result, MAP)
    }
  } catch (failure) {
    error.value = failure instanceof AnalyzeError ? failure.message : 'Unexpected error while analyzing.'
  } finally {
    loading.value = false
  }
}

async function openFlow(methodId: string): Promise<void> {
  const current = graph.value

  if (current === null || current.nodes.find((node) => node.id === methodId)?.type !== 'method') {
    return
  }

  error.value = null
  await show(current, { kind: 'flow', methodId })
}

async function backToMap(): Promise<void> {
  if (graph.value !== null) {
    error.value = null
    await show(graph.value, MAP)
  }
}

async function onFile(event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0]

  if (!file) {
    return
  }

  code.value = await file.text()
  fileName.value = file.name
  await run()
}

function onGraphSelect(id: string): void {
  selected.value = { id, reveal: true }
}

function onCursorLine(line: number): void {
  const node = graph.value ? nodeAtLine(graph.value, line, flowMethodId.value) : undefined

  if (node && node.id !== selected.value?.id) {
    selected.value = { id: node.id, reveal: false }
  }
}

const SPLIT_KEY = 'ariadne:split'
const split = ref(clampSplit(Number(localStorage.getItem(SPLIT_KEY) ?? DEFAULT_SPLIT)))
const panes = ref<HTMLElement>()
let resizing = false

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
  if (!resizing) {
    return
  }

  resizing = false
  saveSplit()
}

function nudgeSplit(event: KeyboardEvent): void {
  const step = event.key === 'ArrowLeft' ? -2 : event.key === 'ArrowRight' ? 2 : 0

  if (step !== 0) {
    split.value = clampSplit(split.value + step)
    saveSplit()
  }
}

function saveSplit(): void {
  try {
    localStorage.setItem(SPLIT_KEY, String(split.value))
  } catch {
    // The split just won't be remembered.
  }
}

onMounted(run)
</script>

<template>
  <div class="app">
    <header class="toolbar">
      <strong class="title"><span class="dot" />Ariadne Code</strong>
      <label class="button">
        Open .php
        <input type="file" accept=".php,text/x-php" hidden @change="onFile" />
      </label>
      <button type="button" class="button primary" :disabled="loading" @click="run">
        {{ loading ? 'Analyzing…' : 'Analyze' }}
      </button>
      <button type="button" class="button" :disabled="graph === null" @click="graphView?.resetLayout()">
        Reset layout
      </button>
      <template v-if="breadcrumb">
        <button type="button" class="button" @click="backToMap">← Class map</button>
        <span class="crumbs"><span class="crumb-class">{{ breadcrumb.cls }}</span> › {{ breadcrumb.method }}</span>
      </template>
      <button
        v-else
        type="button"
        class="button"
        :disabled="selectedMethodId === undefined"
        @click="selectedMethodId !== undefined && openFlow(selectedMethodId)"
      >
        Show flow
      </button>
      <span class="file">{{ fileName }}</span>
      <span v-if="error" class="error" role="alert">{{ error }}</span>
    </header>

    <main ref="panes" class="panes" :style="{ gridTemplateColumns: `${split}% 6px minmax(0, 1fr)` }">
      <section class="pane graph-pane">
        <div class="graph-slot">
          <GraphView
            ref="graphView"
            :nodes="nodes"
            :edges="shownEdges"
            :selected-id="selected?.id ?? null"
            :visited="visited"
            :file-name="layoutId"
            :mode="view.kind"
            @select="onGraphSelect"
            @open="openFlow"
          />
        </div>
        <ReplayPanel
          v-if="view.kind === 'flow' && path.length > 0"
          :path="path"
          :nodes="flowData"
          :choices="choices"
          :at-end="atEnd"
          @next="takeExit"
          @back="path = stepBack(path)"
          @reset="path = path.slice(0, 1)"
          @jump="(index: number) => (path = jumpTo(path, index))"
        />
      </section>
      <div
        class="divider"
        role="separator"
        aria-orientation="vertical"
        aria-label="Resize panes"
        tabindex="0"
        @pointerdown="startResize"
        @pointermove="onResize"
        @pointerup="endResize"
        @pointercancel="endResize"
        @keydown="nudgeSplit"
      />
      <section class="pane">
        <CodeEditor v-model="code" :highlight="highlight" @cursor-line="onCursorLine" />
      </section>
    </main>
  </div>
</template>

<style>
.app {
  display: grid;
  grid-template-rows: 52px minmax(0, 1fr);
  height: 100%;
}

.toolbar {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 18px;
  border-bottom: 1px solid var(--border);
  background: var(--surface);
}

.title {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-right: 14px;
  font-size: 15px;
  letter-spacing: 0.01em;
}

.title .dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: var(--accent);
  box-shadow: 0 0 12px rgba(var(--accent-rgb), 0.8);
}

.button {
  padding: 7px 14px;
  border: 1px solid var(--border-strong);
  border-radius: 8px;
  background: var(--surface-raised);
  color: var(--text);
  font: 500 13px var(--font-ui);
  cursor: pointer;
  transition:
    border-color 0.15s,
    background 0.15s;
}

.button:hover:not(:disabled) {
  border-color: var(--accent);
}

.button.primary {
  border-color: var(--accent);
  background: var(--accent);
  color: #1a0d03;
}

.button.primary:hover:not(:disabled) {
  background: #ff7d36;
}

.button:disabled {
  opacity: 0.5;
  cursor: default;
}

.crumbs {
  color: var(--text);
  font: 13px var(--font-code);
}

.crumb-class {
  color: var(--muted);
}

.file {
  margin-left: auto;
  color: var(--muted);
  font: 12px var(--font-code);
}

.error {
  padding: 4px 10px;
  border: 1px solid rgba(255, 92, 92, 0.4);
  border-radius: 8px;
  background: rgba(255, 92, 92, 0.08);
  color: var(--danger);
  font-size: 12px;
}

.panes {
  display: grid;
  min-height: 0;
}

.pane {
  min-width: 0;
  min-height: 0;
  height: 100%;
}

.graph-pane {
  display: flex;
  flex-direction: column;
}

.graph-slot {
  flex: 1;
  min-height: 0;
}

.divider {
  cursor: col-resize;
  background: var(--border);
  touch-action: none;
  transition: background 0.15s;
}

.divider:hover,
.divider:focus-visible,
.divider:active {
  background: var(--accent);
  outline: none;
}
</style>
