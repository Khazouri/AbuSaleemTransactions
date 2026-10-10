<script setup>
/**
 * Workflow designer — slice 2. Draws a version (read-only). The container is
 * dir="ltr" on purpose: the canvas is geometry, and mirroring it under Arabic
 * would flip handles and edge routes; node text keeps its own direction
 * through dir="auto" in StageNode.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { VueFlow } from '@vue-flow/core'
import StageNode from './StageNode.vue'
import { buildGraph } from '../../lib/workflowGraph'

const props = defineProps({
  version: { type: Object, required: true },
  currentStageId: { type: Number, default: null },
})
const emit = defineEmits(['select'])
const { t, te } = useI18n()

const actionLabel = (action) => (te(`workflow.actions.${action}`) ? t(`workflow.actions.${action}`) : action)
const graph = computed(() => buildGraph(props.version))
const nodes = computed(() => graph.value.nodes.map((node) => ({
  ...node,
  data: { ...node.data, current: Number(node.id) === props.currentStageId },
})))
const edges = computed(() => graph.value.edges.map((edge) => ({
  ...edge,
  label: edge.data.actions.map(actionLabel).join(' · '),
  class: { exception: edge.data.exception },
})))

const flow = ref(null)
const fit = () => flow.value?.fitView({ padding: 0.15 })
</script>

<template>
  <div class="canvas-wrap">
    <div class="canvas-tools">
      <button class="ghost" type="button" @click="fit">{{ t('workflows.fitView') }}</button>
    </div>
    <div class="canvas" dir="ltr" role="figure" :aria-label="t('workflows.canvasLabel')">
      <VueFlow
        :nodes="nodes"
        :edges="edges"
        :nodes-draggable="false"
        :nodes-connectable="false"
        :min-zoom="0.2"
        fit-view-on-init
        @pane-ready="(instance) => (flow = instance)"
        @node-click="({ node }) => emit('select', { kind: 'stage', id: Number(node.id) })"
        @edge-click="({ edge }) => emit('select', { kind: 'edge', id: edge.id })"
      >
        <template #node-stage="nodeProps">
          <StageNode v-bind="nodeProps" />
        </template>
      </VueFlow>
    </div>
  </div>
</template>

<style scoped>
.canvas-wrap { display: grid; gap: var(--space-2); min-inline-size: 0; }
.canvas-tools { display: flex; justify-content: flex-end; }
.canvas {
  block-size: min(70vh, 46rem);
  min-block-size: 24rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: var(--color-background);
}
.canvas :deep(.vue-flow__edge-path) { stroke: var(--color-black-400); stroke-width: 1.5; }
.canvas :deep(.vue-flow__edge.exception .vue-flow__edge-path) { stroke: var(--color-warning-fg); stroke-dasharray: 6 4; }
.canvas :deep(.vue-flow__edge.selected .vue-flow__edge-path) { stroke: var(--color-brand-text); stroke-width: 2.5; }
.canvas :deep(.vue-flow__edge-textbg) { fill: var(--color-surface); }
.canvas :deep(.vue-flow__edge-text) { fill: var(--color-foreground); font-size: var(--text-xs); }
</style>
