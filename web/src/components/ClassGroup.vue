<script setup lang="ts">
import { Box } from 'lucide-vue-next'

import type { CodeNodeData } from '../graph/toFlow'

defineProps<{ data: CodeNodeData }>()
</script>

<template>
  <div class="class-group">
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
