<script setup lang="ts">
import { Background } from '@vue-flow/background'
import { VueFlow, useVueFlow, type Edge, type Node } from '@vue-flow/core'
import { shallowRef, watch } from 'vue'

import { nodeClass } from '../graph/classes'
import { isInView, readableViewport } from '../graph/viewport'
import { applyPositions, clearPositions, layoutKey, loadPositions, savePositions } from '../graph/positions'
import { theme } from '../theme'
import ClassGroup from './ClassGroup.vue'
import CodeNode from './CodeNode.vue'
import FlowNode from './FlowNode.vue'
import GraphLegend from './GraphLegend.vue'

import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'

const props = defineProps<{
  nodes: Node[]
  edges: Edge[]
  selectedId: string | null
  /** Nodes already passed by a replay. */
  visited?: ReadonlySet<string>
  fileName: string
  mode: 'map' | 'flow'
}>()
const emit = defineEmits<{ select: [id: string]; open: [id: string] }>()

const { fitView, setViewport, setCenter, viewport, dimensions, findNode, getNodes, updateNode, onNodeClick, onNodeDoubleClick, onNodeDragStop, onNodesChange, onNodesInitialized } = useVueFlow()

// Vue Flow owns the node positions from here on. Props only deliver a freshly analyzed graph;
// selection and dragging must never rebuild nodes from them, or dragged blocks jump back.
const model = shallowRef<Node[]>([])
const baseClass = new Map<string, string>()
const applied = new Map<string, string>()
let pendingFit = false

const plainClass = (node: Node): string => (typeof node.class === 'string' ? node.class : '')

const classFor = (id: string): string =>
  nodeClass(baseClass.get(id) ?? '', { selected: props.selectedId === id, visited: props.visited?.has(id) ?? false })

watch(
  () => props.nodes,
  (nodes) => {
    baseClass.clear()
    applied.clear()
    nodes.forEach((node) => baseClass.set(node.id, plainClass(node)))
    pendingFit = true
    model.value = applyPositions(nodes, loadPositions(localStorage, layoutKey(props.fileName))).map((node) => ({ ...node, class: classFor(node.id) }))
    nodes.forEach((node) => applied.set(node.id, classFor(node.id)))
  },
  { immediate: true },
)

// Selection and visited state are class changes on the existing nodes, applied in place, so dragged positions stay.
watch(
  () => [props.selectedId, props.visited] as const,
  () => {
    for (const id of baseClass.keys()) {
      const next = classFor(id)

      if (applied.get(id) !== next) {
        applied.set(id, next)
        updateNode(id, { class: next })
      }
    }
  },
)

// Keep the selected block on screen: a replay (or the code cursor) can select one far outside the view.
watch(
  () => props.selectedId,
  (id) => {
    const node = id === null ? undefined : findNode(id)

    if (node === undefined || !node.dimensions.width) {
      return
    }

    const rect = { x: node.position.x, y: node.position.y, width: node.dimensions.width, height: node.dimensions.height }

    if (!isInView(rect, viewport.value, dimensions.value)) {
      void setCenter(rect.x + rect.width / 2, rect.y + rect.height / 2, { zoom: viewport.value.zoom, duration: 350 })
    }
  },
)

onNodeClick(({ node }) => emit('select', node.id))
onNodeDoubleClick(({ node }) => emit('open', node.id))
const remember = (): void => savePositions(localStorage, layoutKey(props.fileName), getNodes.value)

onNodeDragStop(remember)
// `resizing === false` marks the end of a resize drag; dimension changes during layout carry no flag.
onNodesChange((changes) => {
  if (changes.some((change) => change.type === 'dimensions' && change.resizing === false)) {
    remember()
  }
})

/** A method flow is read like text, so it opens at a readable size from the top. A class map is shown whole. */
function fit(): void {
  if (props.mode === 'map') {
    void fitView({ padding: 0.1, maxZoom: 1.25 })

    return
  }

  const nodes = getNodes.value

  if (nodes.length === 0) {
    return
  }

  const bounds = {
    minX: Math.min(...nodes.map((node) => node.position.x)),
    minY: Math.min(...nodes.map((node) => node.position.y)),
    maxX: Math.max(...nodes.map((node) => node.position.x + node.dimensions.width)),
    maxY: Math.max(...nodes.map((node) => node.position.y + node.dimensions.height)),
  }

  void setViewport(readableViewport(bounds, dimensions.value, { padding: 24, minZoom: 0.85, maxZoom: 1.25 }))
}

onNodesInitialized(() => {
  if (pendingFit) {
    pendingFit = false
    fit()
  }
})

/** Forgets the saved layout and returns to the automatic one. */
function resetLayout(): void {
  clearPositions(localStorage, layoutKey(props.fileName))
  pendingFit = true
  model.value = props.nodes.map((node) => ({ ...node, class: classFor(node.id) }))
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
        <ClassGroup :id="nodeProps.id" :data="nodeProps.data" />
      </template>
      <template #node-code="nodeProps">
        <CodeNode :data="nodeProps.data" />
      </template>
      <template #node-flow="nodeProps">
        <FlowNode :data="nodeProps.data" />
      </template>
      <Background :gap="22" :size="1.3" :pattern-color="theme.dot" />
    </VueFlow>
    <GraphLegend :mode="mode" />
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

/* Method flow: the sequence is quiet, branches and loops are told apart by colour and dash. */
.flow-edge-plain .vue-flow__edge-path,
.flow-edge-secondary .vue-flow__edge-path {
  stroke: var(--muted);
}

.flow-edge-primary .vue-flow__edge-path {
  stroke: var(--accent);
  filter: drop-shadow(0 0 3px rgba(var(--accent-rgb), 0.45));
}

.flow-edge.is-walked .vue-flow__edge-path {
  stroke: var(--accent);
  stroke-width: 2.5;
  stroke-dasharray: none;
  filter: drop-shadow(0 0 4px rgba(var(--accent-rgb), 0.6));
}

.flow-edge-backward .vue-flow__edge-path {
  stroke: var(--dim);
  stroke-dasharray: 5 5;
}

.flow-edge-callback .vue-flow__edge-path {
  stroke: var(--muted);
  stroke-dasharray: 2 5;
  stroke-linecap: round;
}

.flow-edge-exceptional .vue-flow__edge-path {
  stroke: var(--danger);
  stroke-dasharray: 5 5;
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
