<script setup>
/**
 * Stage 19 — chronological approval history for one request.
 *
 * Signatures have been removed from the system — an approval no longer
 * carries an image, only who approved it, at what level, and when.
 */
import { useI18n } from 'vue-i18n'

defineProps({
  approvals: { type: Array, default: () => [] },
})
const { t, locale } = useI18n()

const name = (item) => locale.value === 'ar'
  ? item?.name_ar || item?.name_en
  : item?.name_en || item?.name_ar
const dateTime = (value) => value
  ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', {
      dateStyle: 'medium',
      timeStyle: 'short',
    }).format(new Date(value))
  : t('common.none')
</script>

<template>
  <section class="card card-flat card-pad approval-trail">
    <h3>{{ t('approvalTrail.title') }}</h3>
    <p v-if="!approvals.length" class="state">{{ t('approvalTrail.empty') }}</p>
    <ol v-else>
      <li v-for="approval in approvals" :key="approval.id">
        <span class="level">{{ approval.level }}</span>
        <div class="approval-copy">
          <strong>{{ name(approval.role) }}</strong>
          <small>
            {{ t('approvalTrail.approvedBy', { name: approval.approved_by?.name || t('common.none') }) }}
            · {{ dateTime(approval.approved_at) }}
          </small>
          <p v-if="approval.comment">{{ approval.comment }}</p>
        </div>
      </li>
    </ol>
  </section>
</template>

<style scoped>
.approval-trail h3 { margin: 0 0 var(--space-3); color: var(--color-brand-text); font-size: var(--text-lg); }
.approval-trail ol { display: grid; gap: var(--space-3); padding: 0; margin: 0; list-style: none; }
.approval-trail li { display: grid; grid-template-columns: 2rem minmax(0, 1fr); gap: var(--space-3); padding-bottom: var(--space-3); border-bottom: 1px solid var(--color-border); }
.approval-trail li:last-child { padding-bottom: 0; border-bottom: 0; }
.level { display: grid; place-items: center; align-self: start; inline-size: 2rem; block-size: 2rem; border-radius: 50%; color: var(--color-on-brand); background: var(--color-brand); font-size: var(--text-sm); font-weight: 700; font-variant-numeric: tabular-nums; }
.approval-copy { display: grid; gap: .25rem; min-inline-size: 0; }
.approval-copy strong { color: var(--color-black-700); font-size: var(--text-lg); }
.approval-copy small, .state { color: var(--color-muted); font-size: var(--text-sm); }
.approval-copy p { margin: .2rem 0; color: var(--color-black-700); font-size: var(--text-sm); white-space: pre-wrap; }
</style>
