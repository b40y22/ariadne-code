<script setup lang="ts">
import type { Edge, Node } from '@vue-flow/core'
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'

import { AnalyzeError, analyze } from './api'
import CodeEditor, { type Highlight } from './components/CodeEditor.vue'
import GraphView from './components/GraphView.vue'
import ReplayPanel from './components/ReplayPanel.vue'
import { usePaneSplit } from './composables/usePaneSplit'
import { useReplay } from './composables/useReplay'
import { busiestUnit, focusGraph, focusUnits, unitOf, withFlow } from './graph/focus'
import { layout } from './graph/layout'
import { nodeAtLine } from './graph/lookup'
import { LOOSE_TYPES, toClassMap } from './graph/toFlow'
import { countBuiltins, layoutEdges, toMethodFlow } from './graph/toMethodFlow'
import type { Graph } from './graph/types'
import { SAMPLE_CODE, SAMPLE_FILE } from './sample'
import { DEFAULT_PANEL } from './panel'
import { fetchFlow, fetchProject, fetchSource, isProjectMode, type ProjectOverview } from './project'
import { readStored, readStoredNumber, writeStored } from './storage'
import { focusFromHash, hashFor, MAP, sameView, viewFromHash, type Selection, type View } from './view'

// File mode analyzes the code in the editor. Project mode (`?project`) explores the directory the API was started
// with: the map is focused on one unit at a time, and the editor shows the file of whatever is selected.
const projectMode = isProjectMode(location.search)
const project = shallowRef<ProjectOverview | null>(null)
const focus = ref<string | null>(null)
const flow = shallowRef<Graph | null>(null)
const sources = new Map<string, string>()

const code = ref(projectMode ? '' : SAMPLE_CODE)
/** The file the editor shows. */
const fileName = ref(projectMode ? '' : SAMPLE_FILE)
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
// In project mode the map depends on the focus, not on the file in the editor.
const layoutBase = computed(() => (projectMode ? `project:${project.value?.name ?? ''}#${focus.value ?? ''}` : fileName.value))
const layoutId = computed(() => {
  if (flowMethodId.value !== undefined) {
    // Hiding builtins removes nodes, so the two flows keep separate layouts.
    return `${layoutBase.value}#${flowMethodId.value}${showBuiltins.value ? '#builtins' : ''}`
  }

  return showUnresolved.value ? `${layoutBase.value}#unresolved` : layoutBase.value
})

const units = computed(() => (project.value === null ? [] : focusUnits(project.value.graph)))
const focusName = computed(() => units.value.find((unit) => unit.id === focus.value)?.name ?? '')

/** The unit of the selected node, when it is not the one in focus: the map can move there. */
const focusTarget = computed(() => {
  const target = project.value === null || selected.value === null ? undefined : unitOf(project.value.graph, selected.value.id)

  return target === focus.value ? undefined : target
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

  // In project mode the editor shows one file of many; a node of another file has nothing to highlight in it.
  if (!node || node.lineStart === null || node.lineEnd === null || (projectMode && node.file !== fileName.value)) {
    return null
  }

  return { start: node.lineStart, end: node.lineEnd, reveal: selected.value?.reveal ?? false }
})

function syncHash(next: View): void {
  history.replaceState(null, '', location.pathname + location.search + hashFor(next, projectMode ? focus.value : null))
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
    if (projectMode) {
      await loadProject()

      return
    }

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

/**
 * Reads the project from the API (which re-analyzes it if a file changed) and shows the unit from the address,
 * the one already in focus, or else the busiest one.
 */
async function loadProject(): Promise<void> {
  const first = project.value === null
  const overview = await fetchProject()
  const wanted = first ? viewFromHash(location.hash) : view.value
  const known = (id: string | null | undefined): id is string => id != null && overview.graph.nodes.some((node) => node.id === id)

  project.value = overview
  sources.clear()

  const fromView = wanted.kind === 'flow' ? unitOf(overview.graph, wanted.methodId) : undefined
  const unit = [fromView, first ? focusFromHash(location.hash) : focus.value, busiestUnit(overview.graph)].find(known)

  if (overview.errors.length > 0) {
    error.value = `${overview.errors.length} ${overview.errors.length === 1 ? 'file' : 'files'} could not be parsed and ${overview.errors.length === 1 ? 'is' : 'are'} left out: ${overview.errors.join('; ')}`
  }

  if (unit === undefined) {
    error.value = 'The project has no PHP classes, functions or scripts.'

    return
  }

  await focusOn(unit, fromView === unit ? wanted : MAP)
}

/** Shows the map around a unit, or the flow of one of its methods, and the unit's file in the editor. */
async function focusOn(unit: string, next: View = MAP): Promise<void> {
  const overview = project.value

  if (overview === null) {
    return
  }

  focus.value = unit
  flow.value = next.kind === 'flow' ? await fetchFlow(next.methodId).catch(() => null) : null

  const result = withFlow(focusGraph(overview.graph, unit), flow.value)

  graph.value = result

  if (next.kind === 'map' || flow.value === null || !(await show(result, next))) {
    await show(result, MAP)
  }

  const shownId = view.value.kind === 'flow' ? view.value.methodId : unit
  await showFile(overview.graph.nodes.find((node) => node.id === shownId)?.file)
}

let wantedFile: string | null = null

/** Puts a file of the project in the editor. A later call wins, even if its file loads first. */
async function showFile(file: string | null | undefined): Promise<void> {
  if (!projectMode || file == null || file === wantedFile) {
    return
  }

  wantedFile = file

  try {
    const text = sources.get(file) ?? (await fetchSource(file))

    sources.set(file, text)

    if (wantedFile === file) {
      code.value = text
      fileName.value = file
    }
  } catch (failure) {
    wantedFile = null
    error.value = failure instanceof AnalyzeError ? failure.message : 'Unexpected error while reading the file.'
  }
}

// In project mode the editor follows the selection, which may belong to another file.
watch(selected, (selection) => {
  if (projectMode && selection !== null) {
    void showFile(graph.value?.nodes.find((node) => node.id === selection.id)?.file)
  }
})

function onPick(event: Event): void {
  const name = (event.target as HTMLInputElement).value
  const unit = units.value.find((candidate) => candidate.name === name)

  if (unit !== undefined && unit.id !== focus.value) {
    void focusOn(unit.id)
  }
}

/** Pasting a link or using the browser's back and forward buttons changes only the hash; follow it. */
async function onHashChange(): Promise<void> {
  const wanted = viewFromHash(location.hash)

  if (projectMode) {
    const unit = focusFromHash(location.hash) ?? focus.value

    if (unit !== null && (unit !== focus.value || !sameView(wanted, view.value))) {
      await focusOn(unit, wanted)
    }

    return
  }

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
  if (projectMode) {
    const unit = project.value === null ? undefined : unitOf(project.value.graph, methodId)

    if (unit !== undefined && HAS_FLOW.has(project.value?.graph.nodes.find((node) => node.id === methodId)?.type ?? '')) {
      error.value = null
      await focusOn(unit, { kind: 'flow', methodId })
    }

    return
  }

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
  if (projectMode && focus.value !== null) {
    error.value = null
    await focusOn(focus.value)
  } else if (graph.value !== null) {
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
  const current = graph.value
  // Lines only mean something within the file in the editor.
  const inFile = current !== null && projectMode ? { ...current, nodes: current.nodes.filter((node) => node.file === fileName.value) } : current
  const node = inFile ? nodeAtLine(inFile, line, flowMethodId.value) : undefined

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
      <template v-if="projectMode">
        <input
          class="picker"
          list="focus-units"
          placeholder="Focus on a class…"
          aria-label="Focus on a class, function or script"
          :value="focusName"
          @change="onPick"
        />
        <datalist id="focus-units">
          <option v-for="unit in units" :key="unit.id" :value="unit.name" />
        </datalist>
        <button type="button" class="button" :disabled="loading" title="Read the project again; changed files are re-analyzed" @click="run">
          {{ loading ? 'Analyzing…' : 'Reload' }}
        </button>
      </template>
      <template v-else>
        <label class="button">
          Open .php
          <input type="file" accept=".php,text/x-php" hidden @change="onFile" />
        </label>
        <button type="button" class="button primary" :disabled="loading" @click="run">
          {{ loading ? 'Analyzing…' : 'Analyze' }}
        </button>
      </template>
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
          v-if="projectMode"
          type="button"
          class="button"
          :disabled="focusTarget === undefined"
          title="Focus the map on the class of the selected block"
          @click="focusTarget !== undefined && focusOn(focusTarget)"
        >
          Focus
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
      <span class="file"><template v-if="project">{{ project.name }} / </template>{{ fileName }}</span>
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
        <CodeEditor v-model="code" :highlight="highlight" :read-only="projectMode" @cursor-line="onCursorLine" />
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

.picker {
  width: 280px;
  padding: 7px 12px;
  border: 1px solid var(--border-strong);
  border-radius: 8px;
  background: var(--bg);
  color: var(--text);
  font: 13px var(--font-code);
}

.picker:focus {
  border-color: var(--accent);
  outline: none;
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
