<script setup lang="ts">
import { VueFlow, useVueFlow, type Edge, type Node } from '@vue-flow/core'
import { computed, nextTick, watch } from 'vue'

import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'

const props = defineProps<{ nodes: Node[]; edges: Edge[]; selectedId: string | null }>()
const emit = defineEmits<{ select: [id: string] }>()

const { fitView, onNodeClick } = useVueFlow()

onNodeClick(({ node }) => emit('select', node.id))

// The selection is a visual state only, so it is derived here instead of stored on the nodes.
const displayed = computed(() =>
  props.nodes.map((node) => (node.id === props.selectedId ? { ...node, class: `${node.class ?? ''} is-selected` } : node)),
)

// Re-fit when a new graph arrives, but not on selection changes.
watch(
  () => props.nodes.map((node) => node.id).join('|'),
  async () => {
    await nextTick()
    await fitView({ padding: 0.2 })
  },
)
</script>

<template>
  <VueFlow :nodes="displayed" :edges="edges" :min-zoom="0.1" :max-zoom="2" :nodes-connectable="false" :elements-selectable="false" />
</template>

<style>
.ariadne-node {
  border: 1px solid #5a6270;
  border-radius: 6px;
  background: #2b2f38;
  color: #e6e6e6;
  font: 13px/1.2 ui-monospace, 'SFMono-Regular', Menlo, monospace;
  display: flex;
  align-items: center;
  justify-content: center;
}

.ariadne-class {
  border-color: #c586c0;
  font-weight: 600;
}

.ariadne-method {
  border-color: #569cd6;
}

.ariadne-unresolved {
  border-style: dashed;
  border-color: #808080;
  color: #a0a0a0;
}

.ariadne-node.is-selected {
  box-shadow: 0 0 0 2px #ffd166;
}

.ariadne-edge-contains path {
  stroke: #c586c0;
  stroke-dasharray: 4 4;
  opacity: 0.6;
}

.ariadne-edge-calls path {
  stroke: #569cd6;
}

.vue-flow__edge-text {
  fill: #e6e6e6;
  font-size: 11px;
}
</style>
