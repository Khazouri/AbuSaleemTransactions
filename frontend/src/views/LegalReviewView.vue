<script setup>
// Stage 68 — [D] Art. 21 / [E] stage 08: the pre-meeting legal review.
// The legal member's queue plus the review card itself — Appendix 22's
// بطاقة السند القانوني and النموذج 06's five-outcome verdict.
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'

const { t, locale } = useI18n()

const name = (row) => {
  if (!row) return t('common.none')
  return locale.value === 'ar' ? row.name_ar || row.name_en : row.name_en || row.name_ar
}

function date(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium' })
    .format(new Date(value))
}

// [D] Art. 21's five outcomes, in the order the article lists them. The two
// that permit agenda insertion are flagged so the form can say so before the
// verdict is submitted — the server is still what enforces it.
const VERDICTS = [
  { code: 'sound_ready', permits: true },
  { code: 'needs_document', permits: false },
  { code: 'needs_clarification', permits: false },
  { code: 'jurisdiction_note', permits: false },
  { code: 'present_with_note', permits: true },
]

// Appendix 22's own value lists.
const MANDATES = ['decision', 'recommendation', 'opinion', 'study_only']
const CENTRAL_ANSWERS = ['yes', 'no', 'needs_verification']

// Every verdict but sound_ready must carry the legal member's note — mirrors
// StoreRequestLegalReviewRequest::VERDICTS_REQUIRING_NOTE. Client-side nicety
// only; the 422 is the real enforcement.
const noteRequired = (verdict) => verdict !== '' && verdict !== 'sound_ready'

// --- The queue -------------------------------------------------------------

const rows = ref([])
const loading = ref(false)
const loadError = ref('')

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get('/legal-reviews')
    rows.value = data.data ?? []
  } catch (error) {
    loadError.value = error.response?.data?.message ?? t('meetingsUnit.legalReview.loadError')
    rows.value = []
  } finally {
    loading.value = false
  }
}

// --- One request's card ----------------------------------------------------

const openRow = ref(null)
const detail = ref(null)
const detailLoading = ref(false)
const detailError = ref('')

const form = ref(blankForm())
const submitting = ref(false)
const submitError = ref('')

function blankForm() {
  return {
    primary_legislation: '',
    article_reference: '',
    supplementary_decision: '',
    committee_mandate: '',
    approving_body: '',
    requires_central_approval: '',
    legal_deadline: '',
    prohibiting_conditions: '',
    verdict: '',
    legal_note: '',
  }
}

async function openReview(row) {
  openRow.value = row
  detail.value = null
  detailError.value = ''
  submitError.value = ''
  form.value = blankForm()
  detailLoading.value = true
  try {
    const { data } = await api.get(`/requests/${row.id}/legal-reviews`)
    detail.value = data.data ?? null
    // Appendix 21's per-subject legal basis pre-fills Appendix 22's card, so
    // the legal member is not retyping a citation the manual already fixes
    // for this subject. Left blank when the appendix does not cover the type.
    form.value.primary_legislation = detail.value?.legal_basis?.primary_legislation ?? ''
  } catch (error) {
    detailError.value = error.response?.data?.message ?? t('meetingsUnit.legalReview.loadError')
  } finally {
    detailLoading.value = false
  }
}

function closeReview() {
  openRow.value = null
  detail.value = null
}

async function submitReview() {
  if (!openRow.value || !form.value.verdict) return
  submitting.value = true
  submitError.value = ''
  try {
    const payload = {}
    for (const [key, value] of Object.entries(form.value)) {
      if (value !== '' && value !== null) payload[key] = value
    }
    await api.post(`/requests/${openRow.value.id}/legal-reviews`, payload)
    closeReview()
    await load()
  } catch (error) {
    submitError.value = error.response?.data?.errors?.verdict?.[0]
      ?? error.response?.data?.errors?.legal_note?.[0]
      ?? error.response?.data?.message
      ?? t('meetingsUnit.legalReview.submitError')
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="page legal-review">
    <div class="heading">
      <div>
        <h2>{{ t('meetingsUnit.legalReview.title') }}</h2>
        <p class="subtitle">{{ t('meetingsUnit.legalReview.subtitle') }}</p>
      </div>
    </div>

    <p v-if="loading" class="state">{{ t('common.loading') }}</p>
    <div v-else-if="loadError" class="alert" role="alert">
      {{ loadError }} <button class="ghost" type="button" @click="load">{{ t('common.retry') }}</button>
    </div>
    <p v-else-if="!rows.length" class="state">{{ t('meetingsUnit.legalReview.empty') }}</p>

    <div v-else class="card card-flat card-pad list">
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>{{ t('meetingsUnit.legalReview.table.reference') }}</th>
              <th>{{ t('meetingsUnit.legalReview.table.title') }}</th>
              <th>{{ t('meetingsUnit.legalReview.table.employee') }}</th>
              <th>{{ t('meetingsUnit.legalReview.table.requestType') }}</th>
              <th>{{ t('meetingsUnit.legalReview.table.department') }}</th>
              <th>{{ t('meetingsUnit.legalReview.table.submitted') }}</th>
              <th>{{ t('meetingsUnit.legalReview.table.rounds') }}</th>
              <th>{{ t('meetingsUnit.legalReview.table.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id">
              <td class="ltr">{{ row.reference_number || `#${row.id}` }}</td>
              <td>{{ row.title }}</td>
              <td>{{ row.created_by?.name ?? t('common.none') }}</td>
              <td>{{ row.request_type ? name(row.request_type) : t('common.none') }}</td>
              <td>{{ row.department ? name(row.department) : t('common.none') }}</td>
              <td class="nowrap">{{ date(row.submitted_at) }}</td>
              <td>{{ row.legal_reviews_count ?? 0 }}</td>
              <td>
                <div class="row-actions">
                  <RouterLink class="ghost" :to="{ name: 'request_details', params: { id: row.id } }">
                    {{ t('meetingsUnit.legalReview.actions.openFile') }}
                  </RouterLink>
                  <button v-can="'legal_review.add'" class="primary" type="button" @click="openReview(row)">
                    {{ t('meetingsUnit.legalReview.actions.review') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="openRow" class="modal-backdrop" @click.self="closeReview">
      <div class="modal legal-modal">
        <h3>{{ t('meetingsUnit.legalReview.form.title') }}</h3>
        <p class="hint ltr">{{ openRow.reference_number || `#${openRow.id}` }} — {{ openRow.title }}</p>

        <p v-if="detailLoading" class="state">{{ t('common.loading') }}</p>
        <p v-else-if="detailError" class="alert">{{ detailError }}</p>

        <template v-else>
          <p v-if="detail?.legal_basis?.procedural_note" class="alert info">
            <strong>{{ t('meetingsUnit.legalReview.form.appendix21') }}</strong>
            {{ detail.legal_basis.procedural_note }}
          </p>

          <!-- Art. 21 requires every earlier opinion to stay readable in the file. -->
          <div v-if="detail?.reviews?.length" class="history">
            <h4>{{ t('meetingsUnit.legalReview.form.history') }}</h4>
            <ul>
              <li v-for="review in detail.reviews" :key="review.id">
                <span class="pill" :class="review.permits_agenda ? 'good' : 'warn'">
                  {{ t(`meetingsUnit.legalReview.verdicts.${review.verdict}`) }}
                </span>
                <span class="meta">{{ review.reviewed_by?.name ?? t('common.none') }} · {{ date(review.reviewed_at) }}</span>
                <p v-if="review.legal_note" class="meta">{{ review.legal_note }}</p>
              </li>
            </ul>
          </div>

          <h4>{{ t('meetingsUnit.legalReview.form.card') }}</h4>
          <div class="grid">
            <label>
              {{ t('meetingsUnit.legalReview.fields.primaryLegislation') }}
              <input v-model="form.primary_legislation" type="text" />
            </label>
            <label>
              {{ t('meetingsUnit.legalReview.fields.articleReference') }}
              <input v-model="form.article_reference" type="text" />
            </label>
            <label>
              {{ t('meetingsUnit.legalReview.fields.supplementaryDecision') }}
              <input v-model="form.supplementary_decision" type="text" />
            </label>
            <label>
              {{ t('meetingsUnit.legalReview.fields.committeeMandate') }}
              <select v-model="form.committee_mandate">
                <option value="">{{ t('common.none') }}</option>
                <option v-for="code in MANDATES" :key="code" :value="code">
                  {{ t(`meetingsUnit.legalReview.mandates.${code}`) }}
                </option>
              </select>
            </label>
            <label>
              {{ t('meetingsUnit.legalReview.fields.approvingBody') }}
              <input v-model="form.approving_body" type="text" />
            </label>
            <label>
              {{ t('meetingsUnit.legalReview.fields.requiresCentralApproval') }}
              <select v-model="form.requires_central_approval">
                <option value="">{{ t('common.none') }}</option>
                <option v-for="code in CENTRAL_ANSWERS" :key="code" :value="code">
                  {{ t(`meetingsUnit.legalReview.centralApproval.${code}`) }}
                </option>
              </select>
            </label>
            <label>
              {{ t('meetingsUnit.legalReview.fields.legalDeadline') }}
              <input v-model="form.legal_deadline" type="text" />
            </label>
            <label class="wide">
              {{ t('meetingsUnit.legalReview.fields.prohibitingConditions') }}
              <textarea v-model="form.prohibiting_conditions" rows="2"></textarea>
            </label>
          </div>

          <h4>{{ t('meetingsUnit.legalReview.form.verdict') }}</h4>
          <label>
            {{ t('meetingsUnit.legalReview.fields.verdict') }}
            <select v-model="form.verdict">
              <option value="">{{ t('meetingsUnit.legalReview.form.chooseVerdict') }}</option>
              <option v-for="option in VERDICTS" :key="option.code" :value="option.code">
                {{ t(`meetingsUnit.legalReview.verdicts.${option.code}`) }}
              </option>
            </select>
          </label>
          <p v-if="form.verdict" class="hint">
            {{ VERDICTS.find((v) => v.code === form.verdict)?.permits
              ? t('meetingsUnit.legalReview.form.permitsAgenda')
              : t('meetingsUnit.legalReview.form.blocksAgenda') }}
          </p>
          <label>
            {{ t('meetingsUnit.legalReview.fields.legalNote') }}
            <span class="hint">
              {{ noteRequired(form.verdict)
                ? t('meetingsUnit.legalReview.form.noteRequired')
                : t('meetingsUnit.legalReview.form.noteOptional') }}
            </span>
            <textarea v-model="form.legal_note" rows="3"></textarea>
          </label>

          <p v-if="submitError" class="alert">{{ submitError }}</p>
          <div class="modal-actions">
            <button class="ghost" type="button" :disabled="submitting" @click="closeReview">
              {{ t('common.cancel') }}
            </button>
            <button class="primary" type="button" :disabled="submitting || !form.verdict" @click="submitReview">
              {{ submitting ? t('meetingsUnit.legalReview.form.saving') : t('meetingsUnit.legalReview.form.save') }}
            </button>
          </div>
        </template>
      </div>
    </div>
  </section>
</template>

<style scoped>
.list { margin-bottom: var(--space-4); }
.ltr { direction: ltr; unicode-bidi: isolate; }
.nowrap { white-space: nowrap; }
.row-actions { display: flex; flex-wrap: wrap; gap: var(--space-2); justify-content: flex-end; }

/* The dedicated legal-review modal is wider and scrolls internally — the
   global .modal primitive supplies the surface/border/shadow, this adds the
   size/scroll delta on top of it. */
.legal-modal { inline-size: min(46rem, 100%); max-block-size: 90vh; overflow-y: auto; }
.modal h4 { margin: var(--space-4) 0 var(--space-2); color: var(--color-black-700); font-size: var(--text-sm); }
.modal label { display: flex; flex-direction: column; gap: .3rem; font-size: var(--text-base); color: var(--color-black-700); }
.modal textarea { resize: vertical; }

.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: var(--space-3); }
.grid .wide { grid-column: 1 / -1; }

select, input[type='text'], textarea {
  padding: .5rem .6rem;
  border: 1px solid var(--color-border-hover);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
  color: var(--color-foreground);
  font: inherit;
}

.history { border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: .6rem .8rem; }
.history ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--space-2); }
.history li { border-bottom: 1px solid var(--color-border); padding-block-end: var(--space-2); }
.history li:last-child { border-bottom: 0; padding-block-end: 0; }
.meta { font-size: var(--text-xs); color: var(--color-muted); margin: .25rem 0 0; }
</style>
