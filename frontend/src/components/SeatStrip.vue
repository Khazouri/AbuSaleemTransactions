<script setup>
/**
 * Decision wizard — sub-project 2. The five Art. 10 (أ) seats and where each
 * stands on the act at hand (answered, present, voted, signed…). The state is
 * always said in words; the rule above each cell only echoes it.
 */
import { useI18n } from 'vue-i18n'

defineProps({ seats: { type: Array, required: true } })
const { t } = useI18n()
</script>

<template>
  <section class="seat-strip">
    <h4>{{ t('decisionWizard.seats.title') }}</h4>
    <ul>
      <li v-for="seat in seats" :key="seat.seat" :class="seat.state">
        <span class="seat-name">{{ t(`committees.seats.${seat.seat}`) }}</span>
        <strong>{{ seat.user?.name ?? t('decisionWizard.seats.states.vacant') }}</strong>
        <small>{{ t(`decisionWizard.seats.states.${seat.state}`) }}</small>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.seat-strip h4 { margin: 0 0 var(--space-2); color: var(--color-brand-text); font-size: var(--text-lg); }
ul { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(8.5rem, 100%), 1fr)); gap: var(--space-2); padding: 0; margin: 0; list-style: none; }
li { display: grid; gap: 0.1rem; padding: var(--space-2) var(--space-3); border-block-start: 3px solid var(--color-border-hover); border-radius: var(--radius-sm); background: var(--color-surface-hover); }
li.voted, li.signed, li.accepted, li.present { border-block-start-color: var(--color-brand-text); }
li.declined, li.absent, li.recused, li.unsigned { border-block-start-color: var(--color-warning-fg); }
li.vacant { border-block-start-style: dashed; }
.seat-name { color: var(--color-muted); font-size: var(--text-xs); }
strong { color: var(--color-black-700); font-size: var(--text-sm); overflow-wrap: anywhere; }
small { color: var(--color-black-700); font-size: var(--text-xs); }
</style>
