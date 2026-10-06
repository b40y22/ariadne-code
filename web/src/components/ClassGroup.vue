<script setup lang="ts">
import { useVueFlow } from '@vue-flow/core'
import { NodeResizer } from '@vue-flow/node-resizer'
import { Box } from 'lucide-vue-next'
import { computed } from 'vue'

import type { CodeNodeData } from '../graph/toFlow'

import '@vue-flow/node-resizer/dist/style.css'

const props = defineProps<{ id: string; data: CodeNodeData }>()

const { getNodes } = useVueFlow()

const HEADER = 74
const MARGIN = 22
const FALLBACK = { width: 180, height: 110 }

// A class cannot shrink past its methods, so none of them ends up outside.
const minimum = computed(() => {
  const methods = getNodes.value.filter((node) => node.parentNode === props.id)

  if (methods.length === 0) {
    return FALLBACK
  }

  return {
    width: Math.ceil(Math.max(...methods.map((node) => node.position.x + node.dimensions.width)) + MARGIN),
    height: Math.ceil(Math.max(HEADER, ...methods.map((node) => node.position.y + node.dimensions.height)) + MARGIN),
  }
})
</script>

<template>
  <div class="class-group">
    <NodeResizer :min-width="minimum.width" :min-height="minimum.height" color="var(--accent)" />
    <header class="class-header">
      <span class="icon"><Box :size="18" :stroke-width="1.75" /></span>
      <span class="text">
        <span class="title">{{ data.title }}</span>
        <span class="subtitle">{{ data.subtitle }}</span>
      </span>
    </header>
  </div>
</template>

<style>
/* Resize handles appear when the class is hovered or selected, so they do not clutter the graph. */
.vue-flow__node-class-group .vue-flow__resize-control {
  opacity: 0;
  transition: opacity 0.15s;
}

.vue-flow__node-class-group:hover .vue-flow__resize-control,
.vue-flow__node-class-group.is-selected .vue-flow__resize-control {
  opacity: 1;
}

.vue-flow__resize-control.handle {
  width: 9px;
  height: 9px;
  border: 2px solid var(--accent);
  border-radius: 3px;
  background: var(--bg);
}

.class-group {
  width: 100%;
  height: 100%;
  border: 1px solid var(--border-strong);
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.025);
  transition:
    border-color 0.15s,
    box-shadow 0.15s;
}

.class-header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 18px;
}

.class-header .icon {
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: rgba(var(--accent-rgb), 0.1);
  color: var(--accent);
}

.class-header .text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.class-header .title {
  font: 600 14px/1.3 var(--font-code);
}

.class-header .subtitle {
  color: var(--muted);
  font: 11px/1.4 var(--font-ui);
}

.vue-flow__node.is-selected .class-group {
  border-color: var(--accent);
  box-shadow:
    0 0 0 1px var(--accent),
    0 0 22px rgba(var(--accent-rgb), 0.3);
}
</style>
