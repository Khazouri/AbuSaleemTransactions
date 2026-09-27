<script setup>
/**
 * Decision wizard — sub-project 2. The Confirm step's paper: one frame, four
 * kinds, each the document the act produces in the committee's world — a
 * تأشيرة on a file, a ballot, a draft decision, a line in the session
 * register. The body is the wizard's; the frame names who is signing and when.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'

defineProps({
  kind: { type: String, default: 'tashira' },
  reference: { type: String, default: '' },
  destructive: { type: Boolean, default: false },
})
const { t, locale } = useI18n()
const auth = useAuthStore()

const name = (item) => (locale.value === 'ar' ? item?.name_ar || item?.name_en : item?.name_en || item?.name_ar) ?? ''
const roleNames = computed(() => (auth.user?.roles ?? []).map(name).join(locale.value === 'ar' ? '، ' : ', '))
const today = computed(() => new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'long' }).format(new Date()))
</script>

<template>
  <article class="slip" :class="{ destructive }">
    <header class="slip-head">
      <span>{{ t(`decisionWizard.slip.kinds.${kind}`) }}</span>
      <span v-if="reference" class="ltr-num">{{ reference }}</span>
    </header>
    <slot />
    <footer class="slip-foot">
      <span>
        <strong>{{ auth.user?.name }}</strong>
        <small>{{ roleNames }}</small>
      </span>
      <time>{{ today }}</time>
    </footer>
  </article>
</template>

<style scoped>
/* The one element here with a voice of its own: a minute written on the file. */
.slip { display: grid; gap: var(--space-3); max-inline-size: 60ch; padding: var(--space-5); border: 1px solid var(--color-border-hover); border-inline-start: 8px solid var(--color-brand-text); border-radius: var(--radius-sm); background: var(--color-surface); }
.slip.destructive { border-inline-start-color: var(--color-danger-fg); }
.slip-head { display: flex; justify-content: space-between; gap: var(--space-3); color: var(--color-muted); font-size: var(--text-sm); }
.ltr-num { direction: ltr; font-variant-numeric: tabular-nums; }
.slip-foot { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: end; gap: var(--space-3); padding-block-start: var(--space-3); border-block-start: 1px solid var(--color-border); }
.slip-foot span { display: grid; gap: 0.1rem; }
.slip-foot strong { color: var(--color-black-700); }
.slip-foot small, .slip-foot time { color: var(--color-muted); font-size: var(--text-sm); }

:slotted(.slip-action) { margin: 0; color: var(--color-brand-text); font-size: clamp(1.5rem, 4vw, 2.1rem); font-weight: 700; line-height: 1.2; }
.slip.destructive :slotted(.slip-action) { color: var(--color-danger-fg); }
:slotted(.slip-destination) { margin: 0; color: var(--color-black-700); }
:slotted(.slip-note) { display: grid; gap: 0.3rem; color: var(--color-muted); font-size: var(--text-sm); }
:slotted(.slip-note textarea) { padding: 0.6rem 0.7rem; border: 0; border-block-end: 1px dashed var(--color-border-hover); border-radius: 0; background: transparent; color: var(--color-foreground); font: inherit; font-size: var(--text-lg); line-height: 1.7; resize: vertical; }
:slotted(.slip-note textarea:focus-visible) { outline: 2px solid var(--color-brand-text); outline-offset: 2px; }
</style>
