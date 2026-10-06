<script setup lang="ts">
import type { Edge, Node } from '@vue-flow/core'
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'

import { AnalyzeError, analyze } from './api'
import CodeEditor, { type Highlight } from './components/CodeEditor.vue'
import GraphView from './components/GraphView.vue'
import ReplayPanel from './components/ReplayPanel.vue'
import { usePaneSplit } from './composables/usePaneSplit'
import { useReplay } from './composables/useReplay'
import { layout } from './graph/layout'
import { nodeAtLine } from './graph/lookup'
import { LOOSE_TYPES, toClassMap } from './graph/toFlow'
import { countBuiltins, layoutEdges, toMethodFlow } from './graph/toMethodFlow'
import type { Graph } from './graph/types'
import { SAMPLE_CODE, SAMPLE_FILE } from './sample'
import { DEFAULT_PANEL } from './panel'
import { readStored, readStoredNumber, writeStored } from './storage'
import { hashFor, MAP, sameView, viewFromHash, type Selection, type View } from './view'

const code = ref(SAMPLE_CODE)
const fileName = ref(SAMPLE_FILE)
const graph = ref<Graph | null>(null)
const view = ref<View>(MAP)
const nodes = shallowRef<Node[]>([])
const edges = shallowRef<Edge[]>([])
const selected = ref<Selection | null>(null)
const error = ref<string | null>(null)
const loading = ref(false)
const graphView = ref<InstanceType<typeof GraphView>>()

const replay = useReplay({ view, nodes, edges, selected })
const { path, flowData, visited, shownEdges, choices, atEnd, takeExit, back, restart, jump } = replay

// Unresolved and external calls are hidden on the class map until asked for: there are often more of them than real nodes.
const UNRESOLVED_KEY = 'ariadne:show-unresolved'
const showUnresolved = ref(readStored(UNRESOLVED_KEY) === '1')
const BUILTINS_KEY = 'ariadne:show-builtins'
const showBuiltins = ref(readStored(BUILTINS_KEY) === '1')
const unresolvedCount = computed(() => graph.value?.nodes.filter((node) => LOOSE_TYPES.has(node.type)).length ?? 0)

onMounted(() => window.addEventListener('hashchange', onHashChange))
onBeforeUnmount(() => window.removeEventListener('hashchange', onHashChange))

/** The kinds of node that have a flow of their own. */
const HAS_FLOW: ReadonlySet<string> = new Set(['method', 'function', 'script'])

const flowMethodId = computed(() => (view.value.kind === 'flow' ? view.value.methodId : undefined))

// Each method flow keeps its own saved layout next to the class map's.
// The class map keeps one layout with unresolved calls and one without, since they place different nodes.
const layoutId = computed(() => {
  if (flowMethodId.value !== undefined) {
    // Hiding builtins removes nodes, so the two flows keep separate layouts.
    return `${fileName.value}#${flowMethodId.value}${showBuiltins.value ? '#builtins' : ''}`
  }

  return showUnresolved.value ? `${fileName.value}#unresolved` : fileName.value
})

const selectedMethodId = computed(() => {
  const node = graph.value?.nodes.find((candidate) => candidate.id === selected.value?.id)

  return node !== undefined && HAS_FLOW.has(node.type) ? node.id : undefined
})

const builtinCount = computed(() => (graph.value !== null && flowMethodId.value !== undefined ? countBuiltins(graph.value, flowMethodId.value) : 0))

const breadcrumb = computed(() => {
  const methodId = flowMethodId.value
  const current = graph.value

  if (methodId === undefined || current === null) {
    return null
  }

  const method = current.nodes.find((node) => node.id === methodId)
  const owner = current.edges.find((edge) => edge.type === 'contains' && edge.to === methodId)
  const cls = current.nodes.find((node) => node.id === owner?.from)

  // A function or a script has no class around it; a script is named after its file.
  return { cls: cls?.name ?? '', method: method?.type === 'script' ? (method.name) : `${method?.name ?? ''}()` }
})

const highlight = computed<Highlight | null>(() => {
  const node = graph.value?.nodes.find((candidate) => candidate.id === selected.value?.id)

  if (!node || node.lineStart === null || node.lineEnd === null) {
    return null
  }

  return { start: node.lineStart, end: node.lineEnd, reveal: selected.value?.reveal ?? false }
})

function syncHash(next: View): void {
  history.replaceState(null, '', location.pathname + location.search + hashFor(next))
}

/** Lays out and shows a view of the graph. Returns false, leaving the current view, when it cannot be shown. */
async function show(result: Graph, next: View): Promise<boolean> {
  if (next.kind === 'flow') {
    const flow = toMethodFlow(result, next.methodId, { includeBuiltins: showBuiltins.value })

    if (flow.nodes.length === 0) {
      error.value = 'This method has no body, so there is no flow to show.'

      return false
    }

    nodes.value = await layout(flow.nodes, layoutEdges(flow.edges), { direction: 'DOWN' })
    edges.value = flow.edges
    replay.begin(flow.nodes.find((node) => node.data.kind === 'start')?.id)
  } else {
    replay.begin(undefined)
    const map = toClassMap(result, { includeUnresolved: showUnresolved.value })

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
    const wanted = graph.value === null ? viewFromHash(location.hash) : view.value
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

/** Pasting a link or using the browser's back and forward buttons changes only the hash; follow it. */
async function onHashChange(): Promise<void> {
  const wanted = viewFromHash(location.hash)

  if (graph.value === null || sameView(wanted, view.value)) {
    return
  }

  if (wanted.kind === 'flow') {
    await openFlow(wanted.methodId)
  } else {
    await backToMap()
  }
}

async function openFlow(methodId: string): Promise<void> {
  const current = graph.value

  if (current === null || !HAS_FLOW.has(current.nodes.find((node) => node.id === methodId)?.type ?? '')) {
    return
  }

  error.value = null
  await show(current, { kind: 'flow', methodId })
}

async function toggleUnresolved(): Promise<void> {
  showUnresolved.value = !showUnresolved.value

  writeStored(UNRESOLVED_KEY, showUnresolved.value ? '1' : '0')

  if (graph.value !== null && view.value.kind === 'map') {
    await show(graph.value, MAP)
  }
}

async function toggleBuiltins(): Promise<void> {
  showBuiltins.value = !showBuiltins.value
  writeStored(BUILTINS_KEY, showBuiltins.value ? '1' : '0')

  if (graph.value !== null && view.value.kind === 'flow') {
    await show(graph.value, view.value)
  }
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

const PANEL_KEY = 'ariadne:replay-height'
const replayHeight = ref(readStoredNumber(PANEL_KEY, DEFAULT_PANEL))
const saveReplayHeight = (): void => writeStored(PANEL_KEY, replayHeight.value)

const { split, startResize, onResize, endResize, nudgeSplit } = usePaneSplit()

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
        <button
          type="button"
          class="button toggle"
          :aria-pressed="showBuiltins"
          :title="'Common pure functions such as count, trim and array_merge'"
          @click="toggleBuiltins"
        >
          Builtins ({{ builtinCount }})
        </button>
        <span class="crumbs">
          <template v-if="breadcrumb.cls"><span class="crumb-class">{{ breadcrumb.cls }}</span> › </template>{{ breadcrumb.method }}
        </span>
      </template>
      <template v-else>
        <button
          type="button"
          class="button"
          :disabled="selectedMethodId === undefined"
          @click="selectedMethodId !== undefined && openFlow(selectedMethodId)"
        >
          Show flow
        </button>
        <button
          type="button"
          class="button toggle"
          :aria-pressed="showUnresolved"
          :disabled="graph === null"
          title="Calls into code outside the analyzed files (libraries, the framework) and calls the analyzer could not trace to a declaration"
          @click="toggleUnresolved"
        >
          External &amp; unresolved ({{ unresolvedCount }})
        </button>
      </template>
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
            :unresolved="showUnresolved"
            @select="onGraphSelect"
            @open="openFlow"
          />
        </div>
        <ReplayPanel
          v-if="view.kind === 'flow' && path.length > 0"
          :path="path"
          :nodes="flowData"
          :choices="choices"
          v-model:height="replayHeight"
          :at-end="atEnd"
          @settled="saveReplayHeight"
          @next="takeExit"
          @back="back"
          @reset="restart"
          @jump="jump"
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

.button.toggle[aria-pressed='true'] {
  border-color: var(--accent);
  background: rgba(var(--accent-rgb), 0.14);
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
