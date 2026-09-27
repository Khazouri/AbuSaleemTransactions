<script setup>
/**
 * Stage 68 — Appendix 22's بطاقة السند القانوني, filled by the legal member
 * in the request wizard's Checks step (decision wizard, sub-project 2 moved it
 * here from LegalReviewView's modal). Every earlier opinion stays readable, as
 * Art. 21 requires, and Appendix 21 pre-fills the primary legislation where the
 * subject has one. The verdict and the note are the wizard's later steps.
 */
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api, { firstError } from '../lib/api'

const props = defineProps({ requestId: { type: [Number, String], required: true } })
const card = defineModel({ type: Object, required: true })
const { t, locale } = useI18n()

// Appendix 22's own value lists.
const MANDATES = ['decision', 'recommendation', 'opinion', 'study_only']
const CENTRAL_ANSWERS = ['yes', 'no', 'needs_verification']

const detail = ref(null)
const loading = ref(true)
const error = ref('')

function date(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium' }).format(new Date(value))
}

onMounted(async () => {
  try {
    const { data } = await api.get(`/requests/${props.requestId}/legal-reviews`)
    detail.value = data.data ?? null
    if (!card.value.primary_legislation) {
      card.value.primary_legislation = detail.value?.legal_basis?.primary_legislation ?? ''
    }
  } catch (requestError) {
    error.value = firstError(requestError, t('meetingsUnit.legalReview.loadError'))
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <p v-if="loading" class="state">{{ t('common.loading') }}</p>
  <p v-else-if="error" class="alert">{{ error }}</p>
  <div v-else class="legal-card">
    <p v-if="detail?.legal_basis?.procedural_note" class="alert info">
      <strong>{{ t('meetingsUnit.legalReview.form.appendix21') }}</strong>
      {{ detail.legal_basis.procedural_note }}
    </p>

    <div v-if="detail?.reviews?.length" class="history">
      <h5>{{ t('meetingsUnit.legalReview.form.history') }}</h5>
      <ul>
        <li v-for="review in detail.reviews" :key="review.id">
          <span class="pill" :class="review.permits_agenda ? 'good' : 'warn'">
            {{ t(`meetingsUnit.legalReview.verdicts.${review.verdict}`) }}
          </span>
          <span class="meta">{{ review.reviewed_by?.name ?? t('common.none') }}{{ locale === 'ar' ? '، ' : ', ' }}{{ date(review.reviewed_at) }}</span>
          <p v-if="review.legal_note" class="meta">{{ review.legal_note }}</p>
        </li>
      </ul>
    </div>

    <div class="grid">
      <label>
        {{ t('meetingsUnit.legalReview.fields.primaryLegislation') }}
        <input v-model="card.primary_legislation" type="text" />
      </label>
      <label>
        {{ t('meetingsUnit.legalReview.fields.articleReference') }}
        <input v-model="card.article_reference" type="text" />
      </label>
      <label>
        {{ t('meetingsUnit.legalReview.fields.supplementaryDecision') }}
        <input v-model="card.supplementary_decision" type="text" />
      </label>
      <label>
        {{ t('meetingsUnit.legalReview.fields.committeeMandate') }}
        <select v-model="card.committee_mandate">
          <option value="">{{ t('common.none') }}</option>
          <option v-for="code in MANDATES" :key="code" :value="code">{{ t(`meetingsUnit.legalReview.mandates.${code}`) }}</option>
        </select>
      </label>
      <label>
        {{ t('meetingsUnit.legalReview.fields.approvingBody') }}
        <input v-model="card.approving_body" type="text" />
      </label>
      <label>
        {{ t('meetingsUnit.legalReview.fields.requiresCentralApproval') }}
        <select v-model="card.requires_central_approval">
          <option value="">{{ t('common.none') }}</option>
          <option v-for="code in CENTRAL_ANSWERS" :key="code" :value="code">{{ t(`meetingsUnit.legalReview.centralApproval.${code}`) }}</option>
        </select>
      </label>
      <label>
        {{ t('meetingsUnit.legalReview.fields.legalDeadline') }}
        <input v-model="card.legal_deadline" type="text" />
      </label>
      <label class="wide">
        {{ t('meetingsUnit.legalReview.fields.prohibitingConditions') }}
        <textarea v-model="card.prohibiting_conditions" rows="2"></textarea>
      </label>
    </div>
  </div>
</template>

<style scoped>
.legal-card { display: grid; gap: var(--space-3); }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(15rem, 100%), 1fr)); gap: var(--space-3); }
.grid .wide { grid-column: 1 / -1; }
label { display: flex; flex-direction: column; gap: .3rem; font-size: var(--text-base); color: var(--color-black-700); }
textarea { resize: vertical; }
.history { border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: .6rem .8rem; }
.history h5 { margin: 0 0 var(--space-2); color: var(--color-black-700); font-size: var(--text-sm); }
.history ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--space-2); }
.history li { border-block-end: 1px solid var(--color-border); padding-block-end: var(--space-2); }
.history li:last-child { border-block-end: 0; padding-block-end: 0; }
.meta { font-size: var(--text-xs); color: var(--color-muted); margin: .25rem 0 0; }
</style>
