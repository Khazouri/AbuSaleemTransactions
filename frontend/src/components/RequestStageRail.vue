<script setup>
/**
 * One stage indicator, three shapes, so the detail page, the queue and the
 * intake wizard can never disagree about how a request's progress reads.
 *
 * `stageProgress` is `{current, total}` from `RequestResource` (Stage 93's
 * server-computed denominator — never re-derived here). `stageTimeliness`
 * is the soft-SLA level from Stage 52/71, used only to colour the current
 * tick; its absence (a stage with no sourced target) just leaves it neutral.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { stageProgressLabel } from '../lib/stageProgress'

const props = defineProps({
  stageProgress: { type: Object, default: null },
  stageTimeliness: { type: Object, default: null },
  currentStageName: { type: String, default: '' },
  variant: { type: String, default: 'full' }, // 'full' | 'compact' | 'steps'
  // 'steps' variant only — a fixed, small wizard sequence with no server total.
  steps: { type: Array, default: () => [] },
  activeStep: { type: Number, default: 0 },
})

const { t } = useI18n()

const level = computed(() => props.stageTimeliness?.level ?? null)
const label = computed(() => stageProgressLabel(t, props.stageProgress))
const ticks = computed(() => Array.from({ length: props.stageProgress?.total ?? 0 }, (_, index) => index + 1))
</script>

<template>
  <div v-if="variant === 'steps'" class="rail steps" role="list" :aria-label="t('requestDetail.stageRail.stepsLabel')">
    <div
      v-for="(step, index) in steps"
      :key="step"
      class="step"
      role="listitem"
      :class="{ done: index < activeStep, active: index === activeStep }"
    >
      <span class="step-dot">{{ index < activeStep ? '✓' : index + 1 }}</span>
      <span class="step-label">{{ step }}</span>
    </div>
  </div>

  <div v-else-if="variant === 'compact'" class="rail compact">
    <span
      v-for="tick in ticks"
      :key="tick"
      class="dot"
      :class="{
        past: stageProgress && tick < stageProgress.current,
        current: stageProgress && tick === stageProgress.current,
        [`level-${level}`]: stageProgress && tick === stageProgress.current && level,
      }"
    />
    <small v-if="label" class="rail-label">{{ label }}</small>
  </div>

  <div v-else class="rail full">
    <div class="track" role="img" :aria-label="currentStageName">
      <span
        v-for="tick in ticks"
        :key="tick"
        class="dot"
        :class="{
          past: stageProgress && tick < stageProgress.current,
          current: stageProgress && tick === stageProgress.current,
          [`level-${level}`]: stageProgress && tick === stageProgress.current && level,
        }"
      />
    </div>
    <div class="caption">
      <strong v-if="currentStageName">{{ currentStageName }}</strong>
      <small v-if="label">{{ label }}</small>
    </div>
  </div>
</template>

<style scoped>
.rail { display: grid; gap: 0.4rem; }

/* -- full: the detail-page hero ------------------------------------------ */
.rail.full .track { display: flex; align-items: center; gap: 0.3rem; }
.rail.full .dot {
  inline-size: 0.6rem;
  block-size: 0.6rem;
  border-radius: 50%;
  background: var(--color-border-hover);
  flex: none;
}
.rail.full .dot.past { background: var(--color-brand); }
.rail.full .dot.current {
  inline-size: 0.95rem;
  block-size: 0.95rem;
  background: var(--color-brand);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-brand) 22%, transparent);
}
.rail.full .dot.current.level-yellow { background: var(--color-warning-fg); box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-warning-fg) 22%, transparent); }
.rail.full .dot.current.level-red,
.rail.full .dot.current.level-critical { background: var(--color-danger-fg); box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-danger-fg) 22%, transparent); }
.rail.full .caption { display: flex; align-items: baseline; gap: 0.6rem; }
.rail.full .caption strong { color: var(--color-black-700); font-size: var(--text-lg); }
.rail.full .caption small { color: var(--color-muted); font-size: var(--text-sm); font-variant-numeric: tabular-nums; }

/* -- compact: one line inside a list row or a tracking card --------------- */
.rail.compact { display: flex; align-items: center; gap: 0.15rem; flex-wrap: wrap; }
.rail.compact .dot {
  inline-size: 0.4rem;
  block-size: 0.4rem;
  border-radius: 50%;
  background: var(--color-border-hover);
}
.rail.compact .dot.past { background: var(--color-brand); }
.rail.compact .dot.current { background: var(--color-brand); box-shadow: 0 0 0 2px color-mix(in srgb, var(--color-brand) 25%, transparent); }
.rail.compact .dot.current.level-yellow { background: var(--color-warning-fg); }
.rail.compact .dot.current.level-red,
.rail.compact .dot.current.level-critical { background: var(--color-danger-fg); }
.rail-label { margin-inline-start: 0.4rem; color: var(--color-muted); font-size: var(--text-xs); white-space: nowrap; }

/* -- steps: the intake wizard's own small sequence ------------------------ */
.rail.steps { display: flex; align-items: center; gap: 0.5rem; }
.step { display: flex; align-items: center; gap: 0.4rem; color: var(--color-muted); font-size: var(--text-sm); }
.step:not(:last-child)::after {
  content: '';
  inline-size: 1.5rem;
  block-size: 1px;
  margin-inline-start: 0.2rem;
  background: var(--color-border-hover);
}
.step-dot {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  inline-size: 1.4rem;
  block-size: 1.4rem;
  border-radius: 50%;
  border: 1px solid var(--color-border-hover);
  color: var(--color-muted);
  font-size: var(--text-xs);
}
.step.done .step-dot { border-color: var(--color-brand); color: var(--color-brand); }
.step.active .step-dot { border-color: var(--color-brand); background: var(--color-brand); color: var(--color-on-brand); }
.step.active .step-label,
.step.done .step-label { color: var(--color-black-700); }
</style>
