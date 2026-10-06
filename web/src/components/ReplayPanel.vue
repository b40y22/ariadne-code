<script setup lang="ts">
import { ChevronLeft, ChevronRight, CircleCheck, RotateCcw } from 'lucide-vue-next'
import { computed, nextTick, ref, watch } from 'vue'

import type { Step } from '../graph/replay'
import { clampPanelHeight, toggledPanelHeight } from '../panel'
import type { FlowNodeData } from '../graph/toMethodFlow'

export interface ExitChoice {
  edgeId: string
  label: string | null
  targetTitle: string
}

const props = defineProps<{
  path: Step[]
  nodes: Map<string, FlowNodeData>
  choices: ExitChoice[]
  atEnd: boolean
  height: number
}>()

const emit = defineEmits<{
  next: [edgeId: string]
  back: []
  reset: []
  jump: [index: number]
  'update:height': [height: number]
  /** The height settled (end of a drag, key press, double-click), so it can be remembered. */
  settled: []
}>()

const root = ref<HTMLElement>()
let dragging = false
let startY = 0
let startHeight = 0

/** Room the panel can grow into: its own height plus the graph above it. */
const available = (): number => root.value?.parentElement?.clientHeight ?? props.height + 400

function startDrag(event: PointerEvent): void {
  dragging = true
  startY = event.clientY
  startHeight = props.height
  ;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
}

function drag(event: PointerEvent): void {
  if (dragging) {
    emit('update:height', clampPanelHeight(startHeight + (startY - event.clientY), available()))
  }
}

function endDrag(): void {
  if (dragging) {
    dragging = false
    emit('settled')
  }
}

function nudge(event: KeyboardEvent): void {
  const step = event.key === 'ArrowUp' ? 24 : event.key === 'ArrowDown' ? -24 : 0

  if (step !== 0) {
    event.preventDefault()
    emit('update:height', clampPanelHeight(props.height + step, available()))
    emit('settled')
  }
}

function toggle(): void {
  emit('update:height', toggledPanelHeight(props.height, available()))
  emit('settled')
}

const log = ref<HTMLElement>()

const entries = computed(() =>
  props.path.map((step, index) => {
    const data = props.nodes.get(step.nodeId)

    return { index, step, title: data?.title ?? step.nodeId, subtitle: data?.subtitle ?? '', kind: data?.kind ?? 'call' }
  }),
)

// Keep the newest step in view as the replay advances.
watch(
  () => props.path.length,
  async () => {
    await nextTick()
    log.value?.scrollTo({ top: log.value.scrollHeight, behavior: 'smooth' })
  },
)
</script>

<template>
  <section ref="root" class="replay" aria-label="Execution replay" :style="{ height: `${height}px` }">
    <div
      class="replay-grip"
      role="separator"
      aria-orientation="horizontal"
      aria-label="Resize replay panel"
      title="Drag to resize, double-click to maximize"
      tabindex="0"
      @pointerdown="startDrag"
      @pointermove="drag"
      @pointerup="endDrag"
      @pointercancel="endDrag"
      @keydown="nudge"
      @dblclick="toggle"
    />
    <header class="replay-head">
      <strong>Execution replay</strong>
      <span class="count">step {{ path.length }}</span>
      <span class="spacer" />
      <button type="button" class="icon-button" title="Back (←)" :disabled="path.length < 2" @click="emit('back')">
        <ChevronLeft :size="16" />
      </button>
      <button type="button" class="icon-button" title="Restart" :disabled="path.length < 2" @click="emit('reset')">
        <RotateCcw :size="15" />
      </button>
    </header>

    <ol ref="log" class="replay-log">
      <li
        v-for="entry in entries"
        :key="entry.index"
        :class="{ current: entry.index === entries.length - 1 }"
        @click="emit('jump', entry.index)"
      >
        <span class="n">{{ entry.index + 1 }}</span>
        <CircleCheck :size="14" class="tick" />
        <span class="name">{{ entry.title }}</span>
        <span v-if="entry.step.label" class="chip" :class="`chip-${entry.step.label}`">{{ entry.step.label }}</span>
        <span class="where">{{ entry.subtitle }}</span>
      </li>
    </ol>

    <footer class="replay-foot">
      <span v-if="atEnd" class="done">Reached the end of the method.</span>
      <template v-else>
        <span class="prompt">{{ choices.length > 1 ? 'Where does it go?' : 'Next' }}</span>
        <button
          v-for="(choice, index) in choices"
          :key="choice.edgeId"
          type="button"
          class="choice"
          :class="{ primary: index === 0 }"
          @click="emit('next', choice.edgeId)"
        >
          <span v-if="choice.label" class="chip" :class="`chip-${choice.label}`">{{ choice.label }}</span>
          {{ choice.targetTitle }}
          <ChevronRight :size="14" />
        </button>
      </template>
    </footer>
  </section>
</template>

<style>
.replay {
  position: relative;
  display: flex;
  flex: none;
  flex-direction: column;
  border-top: 1px solid var(--border);
  background: var(--surface);
}

/* A thicker invisible hit area around the 1px border, so the edge is easy to grab. */
.replay-grip {
  position: absolute;
  top: -4px;
  right: 0;
  left: 0;
  z-index: 3;
  height: 9px;
  cursor: row-resize;
  touch-action: none;
}

.replay-grip:hover,
.replay-grip:focus-visible,
.replay-grip:active {
  background: var(--accent);
  outline: none;
  opacity: 0.8;
}

.replay-head,
.replay-foot {
  display: flex;
  flex: none;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
}

.replay-head {
  border-bottom: 1px solid var(--border);
  font-size: 12px;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.replay-head .count {
  color: var(--muted);
  letter-spacing: 0;
  text-transform: none;
}

.spacer {
  flex: 1;
}

.icon-button {
  display: grid;
  place-items: center;
  width: 26px;
  height: 26px;
  border: 1px solid var(--border-strong);
  border-radius: 6px;
  background: var(--surface-raised);
  color: var(--text);
  cursor: pointer;
}

.icon-button:disabled {
  opacity: 0.4;
  cursor: default;
}

.icon-button:hover:not(:disabled) {
  border-color: var(--accent);
}

.replay-log {
  flex: 1;
  margin: 0;
  padding: 4px 0;
  overflow-y: auto;
  list-style: none;
  font: 12.5px var(--font-code);
}

.replay-log li {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 4px 14px;
  color: var(--muted);
  cursor: pointer;
}

.replay-log li:hover {
  background: rgba(255, 255, 255, 0.03);
}

.replay-log li.current {
  background: rgba(var(--accent-rgb), 0.08);
  color: var(--text);
}

.replay-log .n {
  width: 22px;
  color: var(--dim);
  text-align: right;
}

.replay-log .tick {
  flex: none;
  color: #4caf7a;
}

.replay-log li.current .tick {
  color: var(--accent);
}

.replay-log .name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.replay-log .where {
  margin-left: auto;
  color: var(--dim);
  font-family: var(--font-ui);
}

.chip {
  padding: 1px 7px;
  border: 1px solid var(--border-strong);
  border-radius: 999px;
  color: var(--muted);
  font: 11px var(--font-ui);
}

.chip-true,
.chip-body {
  border-color: rgba(var(--accent-rgb), 0.6);
  color: var(--accent);
}

.chip-exception,
.chip-throw {
  border-color: rgba(255, 92, 92, 0.6);
  color: var(--danger);
}

.replay-foot {
  flex-wrap: wrap;
  border-top: 1px solid var(--border);
}

.replay-foot .prompt {
  color: var(--muted);
  font-size: 12px;
}

.replay-foot .done {
  color: #4caf7a;
  font-size: 13px;
}

.choice {
  display: flex;
  align-items: center;
  gap: 8px;
  max-width: 340px;
  padding: 5px 10px;
  overflow: hidden;
  border: 1px solid var(--border-strong);
  border-radius: 8px;
  background: var(--surface-raised);
  color: var(--text);
  font: 12px var(--font-code);
  text-overflow: ellipsis;
  white-space: nowrap;
  cursor: pointer;
}

.choice:hover {
  border-color: var(--accent);
}

.choice.primary {
  border-color: var(--accent);
  background: rgba(var(--accent-rgb), 0.14);
}
</style>
