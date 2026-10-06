<script setup lang="ts">
import { Background } from '@vue-flow/background'
import { VueFlow, useVueFlow, type Edge, type Node } from '@vue-flow/core'
import { shallowRef, watch } from 'vue'

import { applyPositions, clearPositions, layoutKey, loadPositions, savePositions } from '../graph/positions'
import { theme } from '../theme'
import ClassGroup from './ClassGroup.vue'
import CodeNode from './CodeNode.vue'
import GraphLegend from './GraphLegend.vue'

import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'

const props = defineProps<{ nodes: Node[]; edges: Edge[]; selectedId: string | null; fileName: string }>()
const emit = defineEmits<{ select: [id: string] }>()

const { fitView, getNodes, updateNode, onNodeClick, onNodeDragStop, onNodesInitialized } = useVueFlow()

// Vue Flow owns the node positions from here on. Props only deliver a freshly analyzed graph;
// selection and dragging must never rebuild nodes from them, or dragged blocks jump back.
const model = shallowRef<Node[]>([])
const baseClass = new Map<string, string | undefined>()
let highlighted: string | null = null
let pendingFit = false

const plainClass = (node: Node): string => (typeof node.class === 'string' ? node.class : '')

watch(
  () => props.nodes,
  (nodes) => {
    baseClass.clear()
    nodes.forEach((node) => baseClass.set(node.id, plainClass(node)))
    highlighted = null
    pendingFit = true
    model.value = applyPositions(nodes, loadPositions(localStorage, layoutKey(props.fileName)))
  },
  { immediate: true },
)

// Selection is a class change on the existing node, applied in place.
watch(
  () => props.selectedId,
  (id) => {
    if (highlighted !== null) {
      updateNode(highlighted, { class: baseClass.get(highlighted) ?? '' })
    }

    if (id !== null && baseClass.has(id)) {
      updateNode(id, { class: `${baseClass.get(id) ?? ''} is-selected` })
      highlighted = id
    } else {
      highlighted = null
    }
  },
)

onNodeClick(({ node }) => emit('select', node.id))
onNodeDragStop(() => savePositions(localStorage, layoutKey(props.fileName), getNodes.value))

onNodesInitialized(() => {
  if (pendingFit) {
    pendingFit = false
    void fitView({ padding: 0.1, maxZoom: 1.25 })
  }
})

/** Forgets the saved layout and returns to the automatic one. */
function resetLayout(): void {
  clearPositions(localStorage, layoutKey(props.fileName))
  const selected = highlighted
  highlighted = null
  pendingFit = true
  model.value = props.nodes.map((node) => (node.id === selected ? { ...node, class: `${plainClass(node)} is-selected` } : node))
  highlighted = selected
}

defineExpose({ resetLayout })
</script>

<template>
  <div class="graph-view">
    <VueFlow
      v-model:nodes="model"
      :edges="edges"
      :min-zoom="0.1"
      :max-zoom="2"
      :nodes-connectable="false"
      :elements-selectable="false"
    >
      <template #node-class-group="nodeProps">
        <ClassGroup :data="nodeProps.data" />
      </template>
      <template #node-code="nodeProps">
        <CodeNode :data="nodeProps.data" />
      </template>
      <Background :gap="22" :size="1.3" :pattern-color="theme.dot" />
    </VueFlow>
    <GraphLegend />
  </div>
</template>

<style>
.graph-view {
  position: relative;
  width: 100%;
  height: 100%;
}

.vue-flow__edge-path {
  stroke-width: 1.75;
}

.ariadne-edge-calls .vue-flow__edge-path {
  stroke: var(--accent);
  filter: drop-shadow(0 0 3px rgba(var(--accent-rgb), 0.45));
}

.vue-flow__edge-textbg {
  fill: var(--surface);
  stroke: var(--border);
}

.vue-flow__edge-text {
  fill: var(--text);
  font: 11px var(--font-code);
}
</style>
