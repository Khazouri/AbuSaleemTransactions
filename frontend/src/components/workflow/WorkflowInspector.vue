<script setup>
/**
 * Workflow designer — slice 2. The details of whatever is selected on the
 * canvas — and, with nothing selected, every stage as a button: the way in
 * for a keyboard or phone user who can't drive a canvas.
 */
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { actionLabel } from '../../lib/workflowActions'

const props = defineProps({
  version: { type: Object, required: true },
  selection: { type: Object, default: null },
  currentStageId: { type: Number, default: null },
})
const emit = defineEmits(['select'])
const { t, te, locale } = useI18n()

const name = (item) => (locale.value === 'ar' ? item?.name_ar || item?.name_en : item?.name_en || item?.name_ar)
const ruleLabel = (rule) => actionLabel(rule, { t, te, locale: locale.value })
const stageById = computed(() => Object.fromEntries(props.version.stages.map((stage) => [stage.id, stage])))
const stages = computed(() => [...props.version.stages].sort((a, b) => a.order_no - b.order_no))
const edgeEnds = computed(() => (props.selection?.kind === 'edge' ? props.selection.id.split('-').map(Number) : null))

const stage = computed(() => (props.selection?.kind === 'stage' ? stageById.value[props.selection.id] : null))
const rules = computed(() => {
  if (stage.value) return props.version.transitions.filter((rule) => rule.from_stage_id === stage.value.id)
  if (edgeEnds.value) {
    const [from, to] = edgeEnds.value
    return props.version.transitions.filter((rule) => rule.from_stage_id === from && rule.to_stage_id === to)
  }
  return []
})
const edgeTitle = computed(() => {
  if (!edgeEnds.value) return ''
  const [from, to] = edgeEnds.value
  return t('workflows.edgeRules', { from: name(stageById.value[from]), to: name(stageById.value[to]) })
})

const firstStageId = computed(() => stages.value[0]?.id)
const reachedIds = computed(() => new Set(props.version.transitions
  .filter((rule) => rule.from_stage_id !== rule.to_stage_id)
  .map((rule) => rule.to_stage_id)))
const drainOnly = computed(() => Boolean(stage.value && rules.value.length
  && stage.value.id !== firstStageId.value && !reachedIds.value.has(stage.value.id)))

// Below 1024px this panel sits under the canvas, and the canvas swallows touch
// scrolling — so a tapped stage's details would land off-screen. Bring them in.
const root = ref(null)
watch(() => props.selection, async (selection) => {
  if (!selection || !window.matchMedia('(max-width: 1023px)').matches) return
  await nextTick()
  root.value?.scrollIntoView({ block: 'start', behavior: 'smooth' })
})

function actor(rule) {
  if (rule.requires_submitter_manager) return t('workflows.actor.manager')
  if (rule.requires_creator) return t('workflows.actor.creator')
  if (rule.required_role) return name(rule.required_role)
  return t('workflows.actor.anyone')
}
</script>

<template>
  <aside ref="root" class="inspector card card-flat card-pad" aria-live="polite">
    <template v-if="!selection">
      <p class="hint">{{ t('workflows.selectStage') }}</p>
      <ol class="stage-list">
        <li v-for="item in stages" :key="item.id">
          <button type="button" class="ghost" @click="emit('select', { kind: 'stage', id: item.id })">
            <span class="order">{{ item.order_no }}</span> {{ name(item) }}
            <span v-if="item.id === currentStageId" class="pill success">{{ t('workflows.current') }}</span>
          </button>
        </li>
      </ol>
    </template>

    <template v-else>
      <button type="button" class="ghost back" @click="emit('select', null)">{{ t('workflows.back') }}</button>
      <h3 v-if="stage">{{ stage.order_no }}. {{ name(stage) }}</h3>
      <h3 v-else>{{ edgeTitle }}</h3>

      <dl v-if="stage" class="facts">
        <template v-if="stage.responsible_role">
          <dt>{{ t('workflows.responsibleRole') }}</dt><dd>{{ name(stage.responsible_role) }}</dd>
        </template>
        <template v-if="stage.target_days_max">
          <dt>{{ t('workflows.targetDays') }}</dt>
          <dd>{{ t('workflows.days', { min: stage.target_days_min ?? stage.target_days_max, max: stage.target_days_max }) }}</dd>
        </template>
      </dl>

      <p v-if="drainOnly" class="hint">{{ t('workflows.drainOnlyHint') }}</p>
      <h4 v-if="stage">{{ t('workflows.rulesOut') }}</h4>
      <p v-if="!rules.length" class="hint">{{ stage && !version.transitions.some((rule) => rule.to_stage_id === stage.id) ? t('workflows.unusedHint') : t('workflows.noRules') }}</p>
      <ul v-else class="rules">
        <li v-for="rule in rules" :key="rule.id">
          <strong>{{ ruleLabel(rule) }}</strong>
          <span>{{ actor(rule) }}</span>
          <span>{{ rule.to_stage_id === rule.from_stage_id ? t('workflows.staysHere') : t('workflows.to', { stage: name(stageById[rule.to_stage_id]) }) }}</span>
          <span v-if="rule.set_status">{{ t('workflows.setsStatus', { status: name(rule.set_status) }) }}</span>
          <span class="flags">
            <span v-if="rule.is_exception" class="pill warning">{{ t('workflows.flag.exception') }}</span>
            <span v-if="rule.requires_comment" class="pill">{{ t('workflows.flag.comment') }}</span>
            <span v-if="rule.required_status" class="pill">{{ t('workflows.flag.requiredStatus', { status: name(rule.required_status) }) }}</span>
            <span v-if="rule.request_type" class="pill">{{ t('workflows.flag.requestType', { type: name(rule.request_type) }) }}</span>
          </span>
        </li>
      </ul>
    </template>
  </aside>
</template>

<style scoped>
.inspector { display: grid; gap: var(--space-3); align-content: start; min-inline-size: 0; }
.hint { margin: 0; color: var(--color-muted); }
.stage-list, .rules { list-style: none; margin: 0; padding: 0; display: grid; gap: var(--space-2); }
.stage-list button { display: flex; align-items: center; inline-size: 100%; justify-content: flex-start; text-align: start; gap: var(--space-2); }
.order { font-weight: 700; color: var(--color-muted); }
.back { justify-self: start; }
h3, h4 { margin: 0; }
.facts { display: grid; grid-template-columns: auto 1fr; gap: var(--space-1) var(--space-3); margin: 0; }
.facts dt { color: var(--color-muted); }
.facts dd { margin: 0; }
.rules li { display: grid; gap: .15rem; padding: var(--space-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.flags { display: flex; flex-wrap: wrap; gap: var(--space-1); }
</style>
