<script setup lang="ts">
import type { Edge, Node } from '@vue-flow/core'
import { computed, onMounted, ref, shallowRef } from 'vue'

import { AnalyzeError, analyze } from './api'
import CodeEditor, { type Highlight } from './components/CodeEditor.vue'
import GraphView from './components/GraphView.vue'
import { layout } from './graph/layout'
import { nodeAtLine } from './graph/lookup'
import { toClassMap } from './graph/toFlow'
import { layoutEdges, toMethodFlow } from './graph/toMethodFlow'
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
  } else {
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
      <section class="pane">
        <GraphView
          ref="graphView"
          :nodes="nodes"
          :edges="edges"
          :selected-id="selected?.id ?? null"
          :file-name="layoutId"
          :mode="view.kind"
          @select="onGraphSelect"
          @open="openFlow"
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
