<script setup lang="ts">
import { VueFlow, useVueFlow, type Edge, type Node } from '@vue-flow/core'
import { shallowRef, watch } from 'vue'

import { applyPositions, clearPositions, layoutKey, loadPositions, savePositions } from '../graph/positions'

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
    void fitView({ padding: 0.2 })
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
  <VueFlow
    v-model:nodes="model"
    :edges="edges"
    :min-zoom="0.1"
    :max-zoom="2"
    :nodes-connectable="false"
    :elements-selectable="false"
  />
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
