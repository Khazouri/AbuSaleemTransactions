<script setup>
// Stage 28 — meetings-unit navigation shell. Real content lands in Track H's
// later stages (29-37); this route exists now purely so the sidebar group
// and permission-gated navigation work end-to-end.
//
// Named "Builder" (not just Agenda) to avoid colliding with the existing
// `meetings.agenda` i18n key used by Stage 20's MeetingDetailView.vue.
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <section class="page">
    <h1>{{ t('meetingsUnit.agenda.title') }}</h1>
    <p class="placeholder">{{ t('meetingsUnit.agenda.placeholder') }}</p>
  </section>
</template>

<style scoped>
.page {
  padding: 1.5rem;
}
.placeholder {
  color: var(--color-muted);
}
</style>
