<script setup lang="ts">
import type { Edge, Node } from '@vue-flow/core'
import { computed, onMounted, ref } from 'vue'

import { AnalyzeError, analyze } from './api'
import CodeEditor, { type Highlight } from './components/CodeEditor.vue'
import GraphView from './components/GraphView.vue'
import { layout } from './graph/layout'
import { nodeAtLine } from './graph/lookup'
import { toClassMap } from './graph/toFlow'
import type { Graph } from './graph/types'
import { SAMPLE_CODE, SAMPLE_FILE } from './sample'

const code = ref(SAMPLE_CODE)
const fileName = ref(SAMPLE_FILE)
const graph = ref<Graph | null>(null)
const nodes = ref<Node[]>([])
const edges = ref<Edge[]>([])
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

async function run(): Promise<void> {
  loading.value = true
  error.value = null

  try {
    const result = await analyze(code.value, fileName.value)
    const map = toClassMap(result)

    nodes.value = await layout(map.nodes, map.edges)
    edges.value = map.edges
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

onMounted(run)
</script>

<template>
  <div class="app">
    <header class="toolbar">
      <strong class="title">Ariadne Code</strong>
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
      <span class="file">{{ fileName }}</span>
      <span v-if="error" class="error" role="alert">{{ error }}</span>
    </header>

    <main class="panes">
      <section class="pane graph">
        <GraphView
          ref="graphView"
          :nodes="nodes"
          :edges="edges"
          :selected-id="selected?.id ?? null"
          :file-name="fileName"
          @select="onGraphSelect"
        />
      </section>
      <section class="pane">
        <CodeEditor v-model="code" :highlight="highlight" @cursor-line="onCursorLine" />
      </section>
    </main>
  </div>
</template>

<style>
html,
body,
#app {
  height: 100%;
  margin: 0;
}

body {
  background: #1e1e1e;
  color: #e6e6e6;
  font-family: system-ui, sans-serif;
}

.app {
  display: grid;
  grid-template-rows: 48px 1fr;
  height: 100%;
}

.toolbar {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0 16px;
  background: #252526;
  border-bottom: 1px solid #333;
}

.title {
  margin-right: 8px;
}

.button {
  padding: 6px 12px;
  border: 1px solid #555;
  border-radius: 4px;
  background: #333;
  color: inherit;
  font: inherit;
  cursor: pointer;
}

.button.primary {
  background: #0e639c;
  border-color: #0e639c;
}

.button:disabled {
  opacity: 0.6;
  cursor: default;
}

.file {
  color: #9da5b4;
  font-size: 13px;
}

.error {
  color: #f48771;
  font-size: 13px;
}

.panes {
  display: grid;
  grid-template-columns: 1fr 1fr;
  min-height: 0;
}

.pane {
  min-width: 0;
  min-height: 0;
  height: 100%;
}

.pane.graph {
  border-right: 1px solid #333;
}
</style>
