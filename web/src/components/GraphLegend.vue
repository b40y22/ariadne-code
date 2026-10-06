<script setup lang="ts">
withDefaults(defineProps<{ mode: 'map' | 'flow'; unresolved?: boolean }>(), { unresolved: true })
</script>

<template>
  <ul v-if="mode === 'map'" class="legend" aria-label="Legend">
    <li><span class="swatch call" />call</li>
    <li><span class="swatch group" />class</li>
    <li v-if="unresolved"><span class="swatch unresolved" />unresolved</li>
    <li class="hint">double-click a method to open its flow</li>
  </ul>
  <ul v-else class="legend" aria-label="Legend">
    <li><span class="swatch call" />true / body</li>
    <li><span class="swatch plain" />next step</li>
    <li><span class="swatch back" />loop back</li>
    <li><span class="swatch callback" />callback</li>
    <li><span class="swatch danger" />exception</li>
  </ul>
</template>

<style>
.legend {
  position: absolute;
  bottom: 14px;
  left: 14px;
  z-index: 5;
  display: flex;
  gap: 16px;
  margin: 0;
  padding: 8px 14px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: rgba(20, 21, 25, 0.85);
  color: var(--muted);
  font-size: 12px;
  list-style: none;
  backdrop-filter: blur(6px);
}

.legend li {
  display: flex;
  align-items: center;
  gap: 8px;
}

.legend .hint {
  color: var(--dim);
}

.swatch {
  width: 22px;
  height: 0;
  border-top: 2px solid var(--accent);
}

.swatch.plain {
  border-top-color: var(--muted);
}

.swatch.back {
  border-top: 2px dashed var(--dim);
}

.swatch.callback {
  border-top: 2px dotted var(--muted);
}

.swatch.danger {
  border-top: 2px dashed var(--danger);
}

.swatch.group {
  height: 12px;
  border: 1.5px solid var(--border-strong);
  border-radius: 4px;
  background: rgba(255, 255, 255, 0.03);
}

.swatch.unresolved {
  height: 10px;
  border: 1.5px dashed var(--muted);
  border-radius: 4px;
}
</style>
