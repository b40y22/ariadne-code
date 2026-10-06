<script setup lang="ts">
import { Handle, Position } from '@vue-flow/core'
import { Box, Braces, CircleHelp, FileCode, SquareFunction } from 'lucide-vue-next'
import { computed } from 'vue'

import type { CodeNodeData } from '../graph/toFlow'

const props = defineProps<{ data: CodeNodeData }>()

const icon = computed(() => ({ class: Box, method: Braces, function: SquareFunction, script: FileCode, unresolved: CircleHelp })[props.data.kind])
</script>

<template>
  <div class="code-node" :class="`kind-${data.kind}`">
    <Handle type="target" :position="Position.Left" />
    <span class="icon"><component :is="icon" :size="18" :stroke-width="1.75" /></span>
    <span class="text">
      <span class="title">{{ data.title }}</span>
      <span class="subtitle">{{ data.subtitle }}</span>
    </span>
    <Handle type="source" :position="Position.Right" />
  </div>
</template>

<style>
.code-node {
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

.vue-flow__node:hover .code-node {
  border-color: var(--border-strong);
}

.code-node .icon {
  display: grid;
  place-items: center;
  flex: none;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: rgba(var(--accent-rgb), 0.1);
  color: var(--accent);
}

.code-node .text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.code-node .title {
  overflow: hidden;
  font: 600 13px/1.3 var(--font-code);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.code-node .subtitle {
  color: var(--muted);
  font: 11px/1.4 var(--font-ui);
}

.code-node.kind-class {
  border-color: var(--border-strong);
}

.code-node.kind-script {
  border-color: rgba(var(--accent-rgb), 0.5);
}

.code-node.kind-script .icon {
  background: rgba(var(--accent-rgb), 0.18);
}

.code-node.kind-unresolved {
  border-style: dashed;
  background: transparent;
}

.code-node.kind-unresolved .icon {
  background: rgba(255, 255, 255, 0.05);
  color: var(--muted);
}

.code-node.kind-unresolved .title {
  color: var(--muted);
  font-weight: 500;
}

.vue-flow__node.is-selected .code-node {
  border-color: var(--accent);
  box-shadow:
    0 0 0 1px var(--accent),
    0 0 22px rgba(var(--accent-rgb), 0.35);
}

.vue-flow__handle {
  width: 9px;
  height: 9px;
  border: 2px solid var(--accent);
  background: var(--bg);
}

.vue-flow__handle-left {
  left: -5px;
}

.vue-flow__handle-right {
  right: -5px;
  background: var(--accent);
}

/* Handles stay in the DOM (edge routing measures them) but unused ones are invisible. */
.code-node.kind-class .vue-flow__handle-left,
.code-node.kind-unresolved .vue-flow__handle-right {
  opacity: 0;
}
</style>
