<script setup>
/**
 * Decision wizard — sub-project 2. The frame the request, agenda-item and
 * meeting wizards share: the step strip, one pane at a time, and the nav bar.
 * Each wizard owns its panes (one named slot per step code) and what
 * submitting means; the shell only moves between them.
 */
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AppModal from './AppModal.vue'

const props = defineProps({
  title: { type: String, required: true },
  steps: { type: Array, required: true },
  canAdvance: { type: Boolean, default: true },
  submitLabel: { type: String, default: '' },
  submitDisabled: { type: Boolean, default: false },
  submitting: { type: Boolean, default: false },
  destructive: { type: Boolean, default: false },
})
const step = defineModel('step', { type: String, required: true })
const emit = defineEmits(['submit', 'close'])
const { t } = useI18n()

const index = computed(() => props.steps.indexOf(step.value))
const go = (offset) => { step.value = props.steps[index.value + offset] }
function close() {
  if (!props.submitting) emit('close')
}

// F5 — a check answered elsewhere can drop the current step out of `steps`
// (e.g. the last blocking check clears and `checks` disappears); land
// somewhere still in the strip rather than stranding the wizard on a pane
// that no longer renders.
watch(() => props.steps, (steps) => {
  if (!steps.includes(step.value)) {
    step.value = steps.includes('choose') ? 'choose' : steps[0]
  }
})
</script>

<template>
  <AppModal wide :title="title" @close="close">
    <div class="wizard">
      <ol class="steps">
        <li
          v-for="(code, position) in steps"
          :key="code"
          class="step-pill"
          :class="{ active: step === code, done: position < index }"
          :aria-current="step === code ? 'step' : undefined"
        >
          {{ position + 1 }}. {{ t(`decisionWizard.steps.${code}`) }}
        </li>
      </ol>

      <Transition name="step" mode="out-in">
        <section :key="step" class="pane"><slot :name="step" /></section>
      </Transition>

      <div class="nav">
        <button v-if="index > 0" class="ghost" type="button" :disabled="submitting" @click="go(-1)">
          {{ t('decisionWizard.back') }}
        </button>
        <span class="spacer" />
        <button class="ghost" type="button" :disabled="submitting" @click="close">{{ t('common.cancel') }}</button>
        <button v-if="index < steps.length - 1" class="primary" type="button" :disabled="!canAdvance" @click="go(1)">
          {{ t('decisionWizard.next') }}
        </button>
        <button
          v-else
          :class="destructive ? 'btn danger' : 'primary'"
          type="button"
          :disabled="submitting || submitDisabled"
          @click="emit('submit')"
        >
          {{ submitting ? t('requestDetail.processing') : submitLabel }}
        </button>
      </div>
    </div>
  </AppModal>
</template>

<style scoped>
/* Step strip: the same pills as MeetingSchedulingWizard, so the app's wizards
   read as one family. */
.steps { display: flex; flex-wrap: wrap; gap: 0.4rem; padding: 0; margin: 0 0 var(--space-4); list-style: none; }
.step-pill { padding: 0.25rem 0.6rem; border-radius: var(--radius-full); background: var(--color-surface-hover); color: var(--color-muted); font-size: var(--text-xs); }
.step-pill.active { background: var(--color-brand); color: var(--color-on-brand); }
.step-pill.done { background: var(--color-success-bg); color: var(--color-success-fg); }
.pane { display: grid; gap: var(--space-3); }
.nav { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); margin-block-start: var(--space-5); padding-block-start: var(--space-4); border-block-start: 1px solid var(--color-border); }
.spacer { flex: 1; }

/* The one motion: the next pane arrives from the reading direction's end. */
.step-enter-active, .step-leave-active { transition: opacity 160ms ease, transform 160ms ease; }
.step-enter-from { opacity: 0; transform: translateX(0.75rem); }
.step-enter-from:dir(rtl) { transform: translateX(-0.75rem); }
.step-leave-to { opacity: 0; }
@media (prefers-reduced-motion: reduce) {
  .step-enter-active, .step-leave-active { transition: none; }
}
</style>

<!-- The panes are each wizard's own markup, but the three share one vocabulary
     of parts; unscoped and rooted at .wizard so none of it leaks. -->
<style>
.wizard .pane h4 { margin: var(--space-3) 0 0; color: var(--color-brand-text); font-size: var(--text-lg); }
.wizard .lede { margin: 0; color: var(--color-black-700); font-size: var(--text-base); max-inline-size: 60ch; }
.wizard .hint { margin: 0; color: var(--color-muted); font-size: var(--text-sm); max-inline-size: 60ch; }
.wizard .facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(12rem, 100%), 1fr)); gap: var(--space-3); margin: 0; }
.wizard .facts div { display: grid; gap: 0.15rem; }
.wizard .facts dt { color: var(--color-muted); font-size: var(--text-xs); }
.wizard .facts dd { margin: 0; color: var(--color-black-700); font-weight: 600; }
.wizard .plain-list { display: grid; gap: var(--space-2); padding: 0; margin: 0; list-style: none; }
.wizard .plain-list li { display: grid; gap: 0.15rem; padding-block-end: var(--space-2); border-block-end: 1px solid var(--color-border); }
.wizard .plain-list small { color: var(--color-muted); font-size: var(--text-xs); }
.wizard .check + .check { padding-block-start: var(--space-4); border-block-start: 1px solid var(--color-border); }
.wizard .check h4 { margin-block-start: 0; }

/* Choose: rows, not a card grid — one decision reads down one column. */
.wizard .options { display: grid; gap: var(--space-2); padding: 0; margin: 0; border: 0; }
.wizard .group-label { margin: var(--space-3) 0 0; padding-block-start: var(--space-3); border-block-start: 1px solid var(--color-border); color: var(--color-muted); font-size: var(--text-sm); }
.wizard .option { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: var(--space-3); align-items: start; padding: var(--space-3) var(--space-4); border: 1px solid var(--color-border); border-inline-start: 4px solid var(--color-brand-text); border-radius: var(--radius-lg); background: var(--color-surface); cursor: pointer; }
.wizard .option.destructive { border-inline-start-color: var(--color-danger-fg); }
.wizard .option.chosen { border-color: var(--color-brand-text); background: var(--color-surface-hover); }
.wizard .option.destructive.chosen { border-color: var(--color-danger-fg); }
.wizard .option:has(input:focus-visible) { outline: 2px solid var(--color-brand-text); outline-offset: 2px; }
.wizard .option input { margin-block-start: 0.3rem; accent-color: var(--color-brand); }
.wizard .option-text { display: grid; gap: 0.2rem; }
.wizard .option-text strong { color: var(--color-black-700); font-size: var(--text-lg); }
.wizard .option-text span { color: var(--color-black-700); font-size: var(--text-sm); }
.wizard .option-text small { color: var(--color-muted); font-size: var(--text-xs); }
.wizard .option.blocked { border-inline-start-color: var(--color-border-hover); background: var(--color-surface-hover); cursor: default; }
.wizard .option.blocked strong { color: var(--color-muted); }
.wizard .option.blocked span { color: var(--color-warning-fg); }
.wizard .link { justify-self: start; padding: 0; border: 0; background: none; color: var(--color-brand-text); font: inherit; font-size: var(--text-sm); text-decoration: underline; cursor: pointer; }
.wizard .sr-only { position: absolute; inline-size: 1px; block-size: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
.wizard input[type='text'], .wizard textarea, .wizard select { padding: 0.5rem 0.6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); color: var(--color-foreground); font: inherit; box-sizing: border-box; }
</style>
