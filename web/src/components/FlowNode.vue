<script setup lang="ts">
import { Handle, Position } from '@vue-flow/core'
import { Flag, GitBranch, Play, Repeat, Shield, ShieldAlert, ShieldCheck, TriangleAlert, Undo2, Zap } from 'lucide-vue-next'
import { computed } from 'vue'

import type { FlowKind, FlowNodeData } from '../graph/toMethodFlow'

const props = defineProps<{ data: FlowNodeData }>()

const ICONS = {
  start: Play,
  end: Flag,
  call: Zap,
  condition: GitBranch,
  loop: Repeat,
  try: Shield,
  catch: ShieldAlert,
  finally: ShieldCheck,
  return: Undo2,
  throw: TriangleAlert,
} satisfies Record<FlowKind, unknown>

const icon = computed(() => ICONS[props.data.kind])
</script>

<template>
  <div class="flow-node" :class="`kind-${data.kind}`" :title="data.title">
    <Handle type="target" :position="Position.Top" />
    <span class="icon"><component :is="icon" :size="16" :stroke-width="1.75" /></span>
    <span class="text">
      <span class="title">{{ data.title }}</span>
      <span v-if="data.subtitle" class="subtitle">{{ data.subtitle }}</span>
    </span>
    <Handle type="source" :position="Position.Bottom" />
    <!-- Loop-backs travel along the right edge instead of cutting through the nodes in between. -->
    <Handle id="loop-out" type="source" :position="Position.Right" />
    <Handle id="loop-in" type="target" :position="Position.Right" />
  </div>
</template>

<style>
.flow-node {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  height: 100%;
  padding: 0 14px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: linear-gradient(180deg, var(--surface-raised), var(--surface));
  color: var(--text);
  transition:
    border-color 0.15s,
    box-shadow 0.15s;
}

.vue-flow__node:hover .flow-node {
  border-color: var(--border-strong);
}

.flow-node .icon {
  display: grid;
  place-items: center;
  flex: none;
  width: 28px;
  height: 28px;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.06);
  color: var(--muted);
}

.flow-node .text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.flow-node .title {
  display: -webkit-box;
  overflow: hidden;
  font: 600 12.5px/1.3 var(--font-code);
  overflow-wrap: anywhere;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.flow-node .subtitle {
  color: var(--muted);
  font: 11px/1.4 var(--font-ui);
}

/* Calls are the steps of the story, so they carry the accent. */
.flow-node.kind-call .icon {
  background: rgba(var(--accent-rgb), 0.1);
  color: var(--accent);
}

/* Control flow: structure, not action. */
.flow-node.kind-condition,
.flow-node.kind-loop {
  border-color: var(--border-strong);
}

.flow-node.kind-condition .icon,
.flow-node.kind-loop .icon {
  background: rgba(255, 255, 255, 0.09);
  color: var(--text);
}

.flow-node.kind-try,
.flow-node.kind-catch,
.flow-node.kind-finally {
  border-style: dashed;
  border-color: var(--border-strong);
}

.flow-node.kind-throw {
  border-color: rgba(255, 92, 92, 0.5);
}

.flow-node.kind-throw .icon {
  background: rgba(255, 92, 92, 0.12);
  color: var(--danger);
}

.flow-node.kind-catch .icon {
  color: var(--danger);
}

/* Entry and exit are pills. */
.flow-node.kind-start,
.flow-node.kind-end {
  justify-content: center;
  border-radius: 999px;
  background: var(--surface);
}

.flow-node.kind-start .icon {
  background: rgba(var(--accent-rgb), 0.14);
  color: var(--accent);
}

.vue-flow__node.is-visited .flow-node {
  border-color: rgba(var(--accent-rgb), 0.45);
}

.vue-flow__node.is-selected .flow-node {
  border-color: var(--accent);
  box-shadow:
    0 0 0 1px var(--accent),
    0 0 22px rgba(var(--accent-rgb), 0.35);
}

.flow-node .vue-flow__handle {
  opacity: 0;
}
</style>
