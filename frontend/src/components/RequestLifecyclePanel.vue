<script setup>
// Stage 83 — [D]'s lifecycle edge cases on one card: Appendix 30's document
// conflicts, Appendix 31's per-document validity, Appendix 53's correction
// memos, Appendix 60's six special cases and Appendices 68/69's withdrawal.
//
// Read-only since decision wizard sub-project 3: every record is written and
// settled through the request wizard (components/acts/). The card is one GET,
// so its sections can never disagree; the host remounts it after an act.
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'

const props = defineProps({
  requestId: { type: [Number, String], required: true },
})

const { t } = useI18n()

const data = ref(null)
const loading = ref(false)
const error = ref('')

watch(() => props.requestId, load, { immediate: true })

async function load() {
  if (!props.requestId) return
  loading.value = true
  error.value = ''
  try {
    const response = await api.get(`/requests/${props.requestId}/lifecycle`)
    data.value = response.data.data
  } catch (requestError) {
    error.value = requestError.response?.data?.message ?? t('lifecycle.error')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="lifecycle">
    <p v-if="loading" class="hint">{{ t('lifecycle.loading') }}</p>
    <p v-if="error" class="alert warning">{{ error }}</p>

    <template v-if="data">
      <!-- Appendix 30 — تعارض المستندات. An unresolved row is what stops the
           file reaching an agenda, so it leads the card. -->
      <section>
        <h3>{{ t('lifecycle.conflicts.title') }}</h3>
        <p v-if="data.document_conflicts.some((row) => !row.resolved_at)" class="alert warning">{{ t('lifecycle.conflicts.blocking') }}</p>
        <ul v-if="data.document_conflicts.length" class="rounds">
          <li v-for="row in data.document_conflicts" :key="row.id">
            <p class="round-head">
              <strong>{{ row.kind_label }}</strong>
              <span v-if="row.recorded_at">{{ row.recorded_at.slice(0, 10) }}</span>
            </p>
            <p class="round-detail">{{ row.detail }}</p>
            <template v-if="row.resolved_at">
              <p class="round-detail">{{ t('lifecycle.conflicts.fields.authority_consulted') }}: {{ row.authority_consulted }}</p>
              <p class="round-detail">{{ t('lifecycle.conflicts.fields.authoritative_document') }}: {{ row.authoritative_document }}</p>
              <p class="round-detail">{{ t('lifecycle.conflicts.fields.correction_note') }}: {{ row.correction_note }}</p>
            </template>
            <p v-else class="round-open">{{ t('lifecycle.conflicts.stillOpen') }}</p>
          </li>
        </ul>
        <p v-else class="hint">{{ t('lifecycle.conflicts.none') }}</p>
      </section>

      <!-- Appendix 31 — التحقق من صحة المستندات, per document. -->
      <section v-if="data.document_validity.length">
        <h3>{{ t('lifecycle.validity.title') }}</h3>
        <p class="hint">{{ t('lifecycle.validity.note') }}</p>
        <ul class="rounds">
          <li v-for="row in data.document_validity" :key="row.attachment_id">
            <p class="round-head">
              <strong>{{ row.original_name }}</strong>
              <span v-if="row.card" :class="['verdict', row.card.verdict]">{{ t(`lifecycle.validity.verdicts.${row.card.verdict}`) }}</span>
              <span v-else class="verdict unchecked">{{ t('lifecycle.validity.unchecked') }}</span>
            </p>
          </li>
        </ul>
      </section>

      <!-- Appendix 60 — الحالات الخاصة والاستثنائية. -->
      <section>
        <h3>{{ t('lifecycle.specialCases.title') }}</h3>
        <ul v-if="data.special_cases.length" class="rounds">
          <li v-for="row in data.special_cases" :key="row.id">
            <p class="round-head">
              <strong>{{ row.kind_label }}</strong>
              <span v-if="row.recorded_at">{{ row.recorded_at.slice(0, 10) }}</span>
            </p>
            <p v-for="(value, key) in row.determinations" :key="key" class="round-detail">
              {{ t(`lifecycle.specialCases.fields.${key}`) }}: {{ value }}
            </p>
            <p v-if="row.halt_progress && !row.resolved_at" class="round-open">{{ t('lifecycle.specialCases.halted') }}</p>
            <p v-if="row.resolved_at" class="round-detail">{{ row.resolution_note }}</p>
          </li>
        </ul>
        <p v-else class="hint">{{ t('lifecycle.specialCases.none') }}</p>
      </section>

      <!-- Appendix 53 — مذكرة تصحيح معتمدة. -->
      <section>
        <h3>{{ t('lifecycle.corrections.title') }}</h3>
        <ul v-if="data.corrections.length" class="rounds">
          <li v-for="row in data.corrections" :key="row.id">
            <p class="round-head">
              <strong>{{ row.kind_label }}</strong>
              <span v-if="row.approved_at">{{ t('lifecycle.corrections.approved') }}</span>
              <span v-else class="round-open">{{ t('lifecycle.corrections.pending') }}</span>
            </p>
            <p class="round-detail">{{ row.detail }}</p>
            <p class="round-detail">{{ row.incorrect_value }} ← {{ row.corrected_value }}</p>
          </li>
        </ul>
        <p v-else class="hint">{{ t('lifecycle.corrections.none') }}</p>
      </section>

      <!-- Appendices 68/69 — سحب الطلب. -->
      <section>
        <h3>{{ t('lifecycle.withdrawals.title') }}</h3>
        <ul v-if="data.withdrawals.length" class="rounds">
          <li v-for="row in data.withdrawals" :key="row.id">
            <p class="round-head">
              <strong>{{ row.outcome_label ?? t('lifecycle.withdrawals.pending') }}</strong>
              <span v-if="row.requested_at">{{ row.requested_at.slice(0, 10) }}</span>
            </p>
            <p class="round-detail">{{ row.reason }}</p>
            <p v-if="row.determination_note" class="round-detail">{{ row.determination_note }}</p>
          </li>
        </ul>
        <p v-else class="hint">{{ t('lifecycle.withdrawals.none') }}</p>
        <p v-if="data.committee_has_decided" class="hint">{{ t('lifecycle.withdrawals.afterDecisionNote') }}</p>
      </section>
    </template>
  </div>
</template>

<style scoped>
/* The register list and alerts are the shared .rounds/.alert primitives; only
   this card's section rhythm and the validity verdict colours are local. */
.lifecycle { display: flex; flex-direction: column; gap: var(--space-5); }
.lifecycle section { display: flex; flex-direction: column; gap: var(--space-2); }
.lifecycle h3 { margin: 0; font-size: var(--text-lg); }
.verdict.sound { color: var(--color-success-fg); }
.verdict.doubtful { color: var(--color-danger-fg); }
.verdict.unchecked { color: var(--color-black-500); }
</style>
