<script setup>
/**
 * Workflow designer — slice 2. One stage on the canvas. Handles are fixed
 * per side (see lib/workflowGraph.js) and never connectable in this
 * read-only slice; they exist only so edges know where to attach.
 */
import { Handle, Position } from '@vue-flow/core'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  data: { type: Object, required: true },
  selected: { type: Boolean, default: false },
})
const { t, locale } = useI18n()
const name = (item) => (locale.value === 'ar' ? item?.name_ar || item?.name_en : item?.name_en || item?.name_ar)
</script>

<template>
  <div class="stage-node" :class="{ current: props.data.current, aside: props.data.aside, selected: props.selected }">
    <Handle id="top" type="target" :position="Position.Top" :connectable="false" />
    <Handle id="bottom" type="source" :position="Position.Bottom" :connectable="false" />
    <Handle id="skip-in" type="target" :position="Position.Left" :connectable="false" />
    <Handle id="skip-out" type="source" :position="Position.Left" :connectable="false" />
    <Handle id="back-in" type="target" :position="Position.Right" :connectable="false" />
    <Handle id="back-out" type="source" :position="Position.Right" :connectable="false" />

    <span class="order">{{ props.data.stage.order_no }}</span>
    <div class="body" dir="auto">
      <strong>{{ name(props.data.stage) }}</strong>
      <span v-if="props.data.current" class="pill current-pill">{{ t('workflows.current') }}</span>
      <span v-else-if="props.data.unused" class="muted">{{ t('workflows.unused') }}</span>
      <span v-else-if="props.data.drainOnly" class="muted">{{ t('workflows.drainOnly') }}</span>
      <span v-if="props.data.stage.responsible_role" class="muted">{{ name(props.data.stage.responsible_role) }}</span>
      <span v-if="props.data.loops.length" class="muted">{{ t('workflows.staysHere') }}: {{ props.data.loops.length }}</span>
    </div>
  </div>
</template>

<style scoped>
.stage-node {
  display: flex;
  gap: var(--space-3);
  align-items: flex-start;
  inline-size: 17rem;
  padding: var(--space-3);
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  color: var(--color-foreground);
  box-shadow: 0 1px 2px var(--color-overlay);
}
.stage-node.selected { border-color: var(--color-brand-text); }
.stage-node.current { border: 2px solid var(--color-brand); background: var(--color-success-bg); }
.stage-node.aside { border-style: dashed; opacity: .7; }
.order {
  flex: none;
  display: grid;
  place-items: center;
  inline-size: 1.75rem;
  block-size: 1.75rem;
  border-radius: var(--radius-full);
  background: var(--color-black-100);
  color: var(--color-black-700);
  font-weight: 700;
  font-size: var(--text-sm);
}
.body { display: grid; gap: .15rem; min-inline-size: 0; text-align: start; }
.body strong { font-size: var(--text-sm); line-height: 1.35; }
.muted { color: var(--color-muted); font-size: var(--text-xs); }
.current-pill { justify-self: start; background: var(--color-brand); color: var(--color-on-brand); border-color: var(--color-brand); }
.stage-node :deep(.vue-flow__handle) { opacity: 0; pointer-events: none; }
</style>
