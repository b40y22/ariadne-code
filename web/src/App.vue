<script setup lang="ts">
import type { Edge, Node } from '@vue-flow/core'
import { computed, onMounted, ref, shallowRef } from 'vue'

import { AnalyzeError, analyze } from './api'
import CodeEditor, { type Highlight } from './components/CodeEditor.vue'
import GraphView from './components/GraphView.vue'
import { layout } from './graph/layout'
import { nodeAtLine } from './graph/lookup'
import { toClassMap } from './graph/toFlow'
import type { Graph } from './graph/types'
import { SAMPLE_CODE, SAMPLE_FILE } from './sample'
import { clampSplit, DEFAULT_SPLIT, splitFromPointer } from './split'

const code = ref(SAMPLE_CODE)
const fileName = ref(SAMPLE_FILE)
const graph = ref<Graph | null>(null)
const nodes = shallowRef<Node[]>([])
const allEdges = shallowRef<Edge[]>([])
const showDeclares = ref(false)
// Declarations are drawn only on request: they cut across the call flow that the layout follows.
const edges = computed<Edge[]>(() => (showDeclares.value ? allEdges.value : allEdges.value.filter(isCall)))
const selected = ref<{ id: string; reveal: boolean } | null>(null)
const error = ref<string | null>(null)
const loading = ref(false)
const graphView = ref<InstanceType<typeof GraphView>>()

const highlight = computed<Highlight | null>(() => {
  const node = graph.value?.nodes.find((candidate) => candidate.id === selected.value?.id)

  if (!node || node.lineStart === null || node.lineEnd === null) {
    return null
  }

  return { start: node.lineStart, end: node.lineEnd, reveal: selected.value?.reveal ?? false }
})

const isCall = (edge: Edge): boolean => edge.class?.toString().includes('ariadne-edge-calls') ?? false

async function run(): Promise<void> {
  loading.value = true
  error.value = null

  try {
    const result = await analyze(code.value, fileName.value)
    const map = toClassMap(result)

    nodes.value = await layout(map.nodes, map.edges.filter(isCall))
    allEdges.value = map.edges
    graph.value = result
    selected.value = null
  } catch (failure) {
    error.value = failure instanceof AnalyzeError ? failure.message : 'Unexpected error while analyzing.'
  } finally {
    loading.value = false
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
  const node = graph.value ? nodeAtLine(graph.value, line) : undefined

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
      <button
        type="button"
        class="button toggle"
        :aria-pressed="showDeclares"
        :disabled="graph === null"
        @click="showDeclares = !showDeclares"
      >
        Declarations
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
          :file-name="fileName"
          @select="onGraphSelect"
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
