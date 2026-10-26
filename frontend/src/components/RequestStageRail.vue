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
import { stageIcon } from '../lib/stageIcons'
import AppIcon from './AppIcon.vue'

const props = defineProps({
  stageProgress: { type: Object, default: null },
  stageTimeliness: { type: Object, default: null },
  currentStageName: { type: String, default: '' },
  variant: { type: String, default: 'full' }, // 'full' | 'compact' | 'steps'
  // 'full' variant only — every stage of the file's version, `{order_no, code,
  // name, reached}`. `reached` comes from the timeline rather than order_no,
  // so a stage the path skipped stays grey behind the current one.
  stages: { type: Array, default: () => [] },
  // 'steps' variant only — a fixed, small wizard sequence with no server total.
  steps: { type: Array, default: () => [] },
  activeStep: { type: Number, default: 0 },
})

const { t } = useI18n()

const level = computed(() => props.stageTimeliness?.level ?? null)
const label = computed(() => stageProgressLabel(t, props.stageProgress))
const ticks = computed(() => Array.from({ length: props.stageProgress?.total ?? 0 }, (_, index) => index + 1))
const isCurrent = (stage) => stage.order_no === props.stageProgress?.current
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
    <ol class="track" :aria-label="t('requestDetail.stageRail.label')">
      <li
        v-for="stage in stages"
        :key="stage.code"
        class="stage"
        :class="{
          progressed: stageProgress && stage.order_no <= stageProgress.current,
          reached: stage.reached,
          current: isCurrent(stage),
          [`level-${level}`]: isCurrent(stage) && level,
        }"
        :title="stage.name"
        :aria-current="isCurrent(stage) ? 'step' : undefined"
      >
        <span class="badge"><AppIcon :name="stageIcon(stage.code)" /></span>
        <span class="sr-only">{{ t(`requestDetail.stageRail.${isCurrent(stage) ? 'current' : stage.reached ? 'reached' : 'pending'}`, { name: stage.name }) }}</span>
      </li>
    </ol>
    <div class="caption">
      <strong v-if="currentStageName">{{ currentStageName }}</strong>
      <small v-if="label">{{ label }}</small>
    </div>
  </div>
</template>

<style scoped>
.rail { display: grid; gap: 0.4rem; }

/* -- full: the detail-page hero ------------------------------------------ */
/* Every stage shares the width equally, so the rail spans the page at any
   size; the badge shrinks with its slot on a phone. A stage not yet reached
   is dashed and grey, and the line is dashed past the current stage. */
.rail.full { gap: var(--space-3); }
.rail.full .track { display: flex; margin: 0; padding: 0; list-style: none; }
.stage { position: relative; display: flex; flex: 1 1 0; justify-content: center; min-inline-size: 0; }
.stage:not(:first-child)::before {
  content: '';
  position: absolute;
  inset-block-start: calc(50% - 1px);
  inset-inline-end: 50%;
  inline-size: 100%;
  border-block-start: 2px dashed var(--color-border-hover);
}
.stage.progressed:not(:first-child)::before { border-block-start: 2px solid var(--color-brand); }
.badge {
  position: relative;
  z-index: 1;
  display: grid;
  place-items: center;
  inline-size: min(2.75rem, calc(100% - 4px));
  aspect-ratio: 1;
  border: 1.5px dashed var(--color-border-hover);
  border-radius: 50%;
  background: var(--color-surface);
  color: var(--color-muted);
}
.badge .app-icon { inline-size: 52%; block-size: auto; }
.stage:not(.reached, .current) .badge .app-icon { opacity: 0.55; }
.stage.reached .badge {
  border: 1.5px solid var(--color-brand);
  background: color-mix(in srgb, var(--color-brand) 14%, var(--color-surface));
  color: var(--color-brand-text);
}
.stage.current .badge {
  --stage-tone: var(--color-brand);
  border: 1.5px solid var(--stage-tone);
  background: var(--stage-tone);
  color: var(--color-on-brand);
  box-shadow: 0 0 0 4px color-mix(in srgb, var(--stage-tone) 24%, transparent);
}
/* The warning and danger tones are light in the dark theme, where white on
   them would vanish; the surface colour reads on both palettes. */
.stage.current.level-yellow .badge { --stage-tone: var(--color-warning-fg); color: var(--color-surface); }
.stage.current.level-red .badge,
.stage.current.level-critical .badge { --stage-tone: var(--color-danger-fg); color: var(--color-surface); }
.sr-only { position: absolute; inline-size: 1px; block-size: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
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
