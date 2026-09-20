<script setup>
// Stage 83 — [D]'s lifecycle edge cases on one card: Appendix 30's document
// conflicts, Appendix 31's per-document validity, Appendix 53's correction
// memos, Appendix 60's six special cases and Appendices 68/69's withdrawal.
//
// One panel rather than five, because they are five answers to the same
// question — "what has happened to this file that the ordinary pipeline does
// not describe?" — and a reader checking a file wants them together. Each
// section fetches nothing of its own: the whole card is one GET, refetched
// after any write, so the sections can never disagree about the file's state.
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import {
  CONFLICT_KINDS,
  HALTING_CASES,
  MATERIAL_ERROR_KINDS,
  SPECIAL_CASES,
  SUBSTANTIVE_ERROR_KINDS,
  VALIDITY_ANSWERS,
  VALIDITY_CHECKS,
  WITHDRAWAL_OUTCOMES,
} from '../lib/lifecycle'

const props = defineProps({
  requestId: { type: [Number, String], required: true },
  // Whether the signed-in user is this request's own submitter — Appendix 68's
  // withdrawal is the requester's own act, so only they see that form.
  isRequester: { type: Boolean, default: false },
})

const { t } = useI18n()

const data = ref(null)
const loading = ref(false)
const error = ref('')
const saving = ref(false)
const openForm = ref('')

const conflict = reactive({ conflict_kind: CONFLICT_KINDS[0], detail: '' })
const resolve = reactive({ id: null, authority_consulted: '', authoritative_document: '', correction_note: '' })
const specialCase = reactive({ case_kind: SPECIAL_CASES[0].code, detail: '', halt_progress: false, determinations: {} })
const correction = reactive({ error_kind: MATERIAL_ERROR_KINDS[0], detail: '', incorrect_value: '', corrected_value: '', memo_reference: '' })
const withdrawal = reactive({ reason: '' })
const determination = reactive({ id: null, outcome: WITHDRAWAL_OUTCOMES[0], determination_note: '' })
const validity = reactive({ attachmentId: null, checks: {} })

const selectedCase = computed(() => SPECIAL_CASES.find((entry) => entry.code === specialCase.case_kind))
const caseCanHalt = computed(() => HALTING_CASES.includes(specialCase.case_kind))
const openConflicts = computed(() => (data.value?.document_conflicts ?? []).filter((row) => !row.resolved_at))

/**
 * Appendix 69 decides which outcomes exist: once the committee has decided,
 * granting a withdrawal is not available at all, and before it, recording one
 * without effect is not either.
 */
const availableOutcomes = computed(() => WITHDRAWAL_OUTCOMES.filter((outcome) => (
  data.value?.committee_has_decided
    ? outcome !== 'granted'
    : outcome !== 'recorded_only'
)))

watch(() => props.requestId, load, { immediate: true })
watch(openForm, () => {
  error.value = ''
})

// The determinations a special case needs change with the case, so the form's
// own answers are rebuilt rather than carried across.
watch(() => specialCase.case_kind, () => {
  specialCase.determinations = {}
  specialCase.halt_progress = false
})

watch(availableOutcomes, (outcomes) => {
  if (!outcomes.includes(determination.outcome)) {
    determination.outcome = outcomes[0]
  }
})

async function load() {
  if (!props.requestId) return
  loading.value = true
  error.value = ''
  try {
    const response = await api.get(`/requests/${props.requestId}/lifecycle`)
    data.value = response.data.data
  } catch (requestError) {
    error.value = describe(requestError)
  } finally {
    loading.value = false
  }
}

function describe(requestError) {
  const errors = requestError.response?.data?.errors
  return errors
    ? Object.values(errors).flat().join(' — ')
    : (requestError.response?.data?.message ?? t('lifecycle.error'))
}

async function send(method, path, payload) {
  saving.value = true
  error.value = ''
  try {
    await api[method](`/requests/${props.requestId}${path}`, payload)
    openForm.value = ''
    await load()
    return true
  } catch (requestError) {
    error.value = describe(requestError)
    return false
  } finally {
    saving.value = false
  }
}

async function submitConflict() {
  if (await send('post', '/document-conflicts', { ...conflict })) {
    conflict.detail = ''
  }
}

async function submitResolve() {
  const { id, ...payload } = resolve
  if (await send('patch', `/document-conflicts/${id}/resolve`, payload)) {
    Object.assign(resolve, { id: null, authority_consulted: '', authoritative_document: '', correction_note: '' })
  }
}

async function submitSpecialCase() {
  if (await send('post', '/special-cases', { ...specialCase })) {
    specialCase.detail = ''
    specialCase.determinations = {}
  }
}

function resolveCase(id) {
  const note = window.prompt(t('lifecycle.specialCases.resolvePrompt'))
  if (note) send('patch', `/special-cases/${id}/resolve`, { resolution_note: note })
}

async function submitCorrection() {
  if (await send('post', '/corrections', { ...correction })) {
    Object.assign(correction, { error_kind: MATERIAL_ERROR_KINDS[0], detail: '', incorrect_value: '', corrected_value: '', memo_reference: '' })
  }
}

async function submitWithdrawal() {
  if (await send('post', '/withdrawals', { ...withdrawal })) {
    withdrawal.reason = ''
  }
}

async function submitDetermination() {
  const { id, ...payload } = determination
  if (await send('patch', `/withdrawals/${id}/determine`, payload)) {
    determination.determination_note = ''
  }
}

function startValidity(row) {
  validity.attachmentId = row.attachment_id
  validity.checks = Object.fromEntries(VALIDITY_CHECKS.map(({ code }) => [
    code,
    row.card?.checks?.find((check) => check.key === code)?.answer ?? 'yes',
  ]))
  openForm.value = 'validity'
}

async function submitValidity() {
  if (await send('patch', `/attachments/${validity.attachmentId}/validity`, { checks: { ...validity.checks } })) {
    validity.attachmentId = null
  }
}

defineExpose({ reload: load })
</script>

<template>
  <div class="lifecycle">
    <p v-if="loading" class="hint">{{ t('lifecycle.loading') }}</p>
    <p v-if="error" class="alert warning">{{ error }}</p>

    <template v-if="data">
      <!-- Appendix 30 — تعارض المستندات. An unresolved row here is what stops
           the file reaching an agenda, so it leads the card. -->
      <section>
        <h3>{{ t('lifecycle.conflicts.title') }}</h3>
        <p v-if="openConflicts.length" class="alert warning">{{ t('lifecycle.conflicts.blocking') }}</p>

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
            <template v-else>
              <p class="round-open">{{ t('lifecycle.conflicts.stillOpen') }}</p>
              <button
                v-can="'meeting_outputs.edit'"
                class="btn btn-sm primary"
                type="button"
                @click="resolve.id = row.id; openForm = 'resolveConflict'"
              >
                {{ t('lifecycle.conflicts.resolveAction') }}
              </button>
            </template>
          </li>
        </ul>
        <p v-else class="hint">{{ t('lifecycle.conflicts.none') }}</p>

        <button
          v-if="openForm !== 'conflict'"
          v-can="'meeting_outputs.edit'"
          class="btn btn-sm primary"
          type="button"
          @click="openForm = 'conflict'"
        >
          {{ t('lifecycle.conflicts.action') }}
        </button>

        <form v-else class="gate-form" @submit.prevent="submitConflict">
          <label>
            <span>{{ t('lifecycle.conflicts.fields.conflict_kind') }} *</span>
            <select v-model="conflict.conflict_kind">
              <option v-for="kind in CONFLICT_KINDS" :key="kind" :value="kind">
                {{ t(`lifecycle.conflicts.kinds.${kind}`) }}
              </option>
            </select>
          </label>
          <label>
            <span>{{ t('lifecycle.conflicts.fields.detail') }} *</span>
            <textarea v-model="conflict.detail" rows="3" required />
          </label>
          <div class="actions">
            <button class="primary" type="submit" :disabled="saving">{{ t('lifecycle.save') }}</button>
            <button class="ghost" type="button" @click="openForm = ''">{{ t('lifecycle.cancel') }}</button>
          </div>
        </form>

        <!-- All three fields together: the appendix refuses a resolution that
             rests on nobody's authority ("ولا يجوز للجنة اختيار أحد المستندين
             بناءً على تقدير شخصي"). -->
        <form v-if="openForm === 'resolveConflict'" class="gate-form" @submit.prevent="submitResolve">
          <p class="hint">{{ t('lifecycle.conflicts.resolveNote') }}</p>
          <label>
            <span>{{ t('lifecycle.conflicts.fields.authority_consulted') }} *</span>
            <input v-model="resolve.authority_consulted" required>
          </label>
          <label>
            <span>{{ t('lifecycle.conflicts.fields.authoritative_document') }} *</span>
            <textarea v-model="resolve.authoritative_document" rows="2" required />
          </label>
          <label>
            <span>{{ t('lifecycle.conflicts.fields.correction_note') }} *</span>
            <textarea v-model="resolve.correction_note" rows="2" required />
          </label>
          <div class="actions">
            <button class="primary" type="submit" :disabled="saving">{{ t('lifecycle.save') }}</button>
            <button class="ghost" type="button" @click="openForm = ''">{{ t('lifecycle.cancel') }}</button>
          </div>
        </form>
      </section>

      <!-- Appendix 31 — التحقق من صحة المستندات, per document. -->
      <section v-if="data.document_validity.length">
        <h3>{{ t('lifecycle.validity.title') }}</h3>
        <p class="hint">{{ t('lifecycle.validity.note') }}</p>
        <ul class="rounds">
          <li v-for="row in data.document_validity" :key="row.attachment_id">
            <p class="round-head">
              <strong>{{ row.original_name }}</strong>
              <span v-if="row.card" :class="['verdict', row.card.verdict]">
                {{ t(`lifecycle.validity.verdicts.${row.card.verdict}`) }}
              </span>
              <span v-else class="verdict unchecked">{{ t('lifecycle.validity.unchecked') }}</span>
            </p>
            <button v-can="'meeting_outputs.edit'" class="btn btn-sm primary" type="button" @click="startValidity(row)">
              {{ row.card ? t('lifecycle.validity.recheckAction') : t('lifecycle.validity.action') }}
            </button>
          </li>
        </ul>

        <form v-if="openForm === 'validity'" class="gate-form" @submit.prevent="submitValidity">
          <label v-for="check in VALIDITY_CHECKS" :key="check.code">
            <span>{{ t(`lifecycle.validity.checks.${check.code}`) }}</span>
            <select v-model="validity.checks[check.code]">
              <option
                v-for="answer in VALIDITY_ANSWERS"
                :key="answer"
                :value="answer"
                :disabled="answer === 'not_applicable' && !check.conditional"
              >
                {{ t(`lifecycle.validity.answers.${answer}`) }}
              </option>
            </select>
          </label>
          <div class="actions">
            <button class="primary" type="submit" :disabled="saving">{{ t('lifecycle.save') }}</button>
            <button class="ghost" type="button" @click="openForm = ''">{{ t('lifecycle.cancel') }}</button>
          </div>
        </form>
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
            <template v-if="row.resolved_at">
              <p class="round-detail">{{ row.resolution_note }}</p>
            </template>
            <button
              v-else
              v-can="'meeting_outputs.edit'"
              class="btn btn-sm primary"
              type="button"
              @click="resolveCase(row.id)"
            >
              {{ t('lifecycle.specialCases.resolveAction') }}
            </button>
          </li>
        </ul>
        <p v-else class="hint">{{ t('lifecycle.specialCases.none') }}</p>

        <button
          v-if="openForm !== 'specialCase'"
          v-can="'meeting_outputs.edit'"
          class="btn btn-sm primary"
          type="button"
          @click="openForm = 'specialCase'"
        >
          {{ t('lifecycle.specialCases.action') }}
        </button>

        <form v-else class="gate-form" @submit.prevent="submitSpecialCase">
          <label>
            <span>{{ t('lifecycle.specialCases.fields.case_kind') }} *</span>
            <select v-model="specialCase.case_kind">
              <option v-for="entry in SPECIAL_CASES" :key="entry.code" :value="entry.code">
                {{ t(`lifecycle.specialCases.kinds.${entry.code}`) }}
              </option>
            </select>
          </label>

          <label v-for="field in selectedCase.fields" :key="field.code">
            <span>{{ t(`lifecycle.specialCases.fields.${field.code}`) }} *</span>
            <select v-if="field.options" v-model="specialCase.determinations[field.code]">
              <option v-for="option in field.options" :key="option" :value="option">
                {{ t(`lifecycle.specialCases.options.${field.code}.${option}`) }}
              </option>
            </select>
            <input v-else v-model="specialCase.determinations[field.code]" required>
          </label>

          <!-- "يوقف الانتقال للمرحلة التالية **عند الحاجة**" — the appendix's
               own qualifier, so the halt is declared rather than automatic. -->
          <label v-if="caseCanHalt" class="inline">
            <input v-model="specialCase.halt_progress" type="checkbox">
            <span>{{ t('lifecycle.specialCases.haltProgress') }}</span>
          </label>

          <p v-if="specialCase.case_kind === 'document_lost'" class="hint">
            {{ t('lifecycle.specialCases.lostDocumentRule') }}
          </p>

          <label>
            <span>{{ t('lifecycle.specialCases.fields.detail') }}</span>
            <textarea v-model="specialCase.detail" rows="2" />
          </label>

          <div class="actions">
            <button class="primary" type="submit" :disabled="saving">{{ t('lifecycle.save') }}</button>
            <button class="ghost" type="button" @click="openForm = ''">{{ t('lifecycle.cancel') }}</button>
          </div>
        </form>
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
            <button
              v-if="!row.approved_at"
              v-can="'meeting_outputs.edit'"
              class="btn btn-sm primary"
              type="button"
              :disabled="saving"
              @click="send('patch', `/corrections/${row.id}/approve`, {})"
            >
              {{ t('lifecycle.corrections.approveAction') }}
            </button>
          </li>
        </ul>
        <p v-else class="hint">{{ t('lifecycle.corrections.none') }}</p>

        <button
          v-if="openForm !== 'correction'"
          v-can="'meeting_outputs.edit'"
          class="btn btn-sm primary"
          type="button"
          @click="openForm = 'correction'"
        >
          {{ t('lifecycle.corrections.action') }}
        </button>

        <form v-else class="gate-form" @submit.prevent="submitCorrection">
          <!-- Only the five material kinds are offered: the appendix's six
               substantive ones are not corrections at all, and the note below
               names the route they take instead. -->
          <label>
            <span>{{ t('lifecycle.corrections.fields.error_kind') }} *</span>
            <select v-model="correction.error_kind">
              <option v-for="kind in MATERIAL_ERROR_KINDS" :key="kind" :value="kind">
                {{ t(`lifecycle.corrections.kinds.${kind}`) }}
              </option>
            </select>
          </label>
          <p class="hint">
            {{ t('lifecycle.corrections.substantiveNote') }}
            {{ SUBSTANTIVE_ERROR_KINDS.map((kind) => t(`lifecycle.corrections.kinds.${kind}`)).join('، ') }}
          </p>
          <label>
            <span>{{ t('lifecycle.corrections.fields.detail') }} *</span>
            <textarea v-model="correction.detail" rows="2" required />
          </label>
          <label>
            <span>{{ t('lifecycle.corrections.fields.incorrect_value') }} *</span>
            <input v-model="correction.incorrect_value" required>
          </label>
          <label>
            <span>{{ t('lifecycle.corrections.fields.corrected_value') }} *</span>
            <input v-model="correction.corrected_value" required>
          </label>
          <label>
            <span>{{ t('lifecycle.corrections.fields.memo_reference') }}</span>
            <input v-model="correction.memo_reference">
          </label>
          <div class="actions">
            <button class="primary" type="submit" :disabled="saving">{{ t('lifecycle.save') }}</button>
            <button class="ghost" type="button" @click="openForm = ''">{{ t('lifecycle.cancel') }}</button>
          </div>
        </form>
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
            <button
              v-if="!row.determined_at"
              v-can="'meeting_outputs.edit'"
              class="btn btn-sm primary"
              type="button"
              @click="determination.id = row.id; openForm = 'determination'"
            >
              {{ t('lifecycle.withdrawals.determineAction') }}
            </button>
          </li>
        </ul>
        <p v-else class="hint">{{ t('lifecycle.withdrawals.none') }}</p>

        <p v-if="data.committee_has_decided" class="hint">{{ t('lifecycle.withdrawals.afterDecisionNote') }}</p>

        <!-- "إذا طلب الموظف سحب معاملته" — only the requester files one. -->
        <template v-if="isRequester && !data.has_open_withdrawal">
          <button v-if="openForm !== 'withdrawal'" class="btn btn-sm primary" type="button" @click="openForm = 'withdrawal'">
            {{ t('lifecycle.withdrawals.action') }}
          </button>

          <form v-else class="gate-form" @submit.prevent="submitWithdrawal">
            <label>
              <span>{{ t('lifecycle.withdrawals.fields.reason') }} *</span>
              <textarea v-model="withdrawal.reason" rows="3" required />
            </label>
            <div class="actions">
              <button class="primary" type="submit" :disabled="saving">{{ t('lifecycle.save') }}</button>
              <button class="ghost" type="button" @click="openForm = ''">{{ t('lifecycle.cancel') }}</button>
            </div>
          </form>
        </template>

        <form v-if="openForm === 'determination'" class="gate-form" @submit.prevent="submitDetermination">
          <label>
            <span>{{ t('lifecycle.withdrawals.fields.outcome') }} *</span>
            <select v-model="determination.outcome">
              <option v-for="outcome in availableOutcomes" :key="outcome" :value="outcome">
                {{ t(`lifecycle.withdrawals.outcomes.${outcome}`) }}
              </option>
            </select>
          </label>
          <label>
            <span>{{ t('lifecycle.withdrawals.fields.determination_note') }} *</span>
            <textarea v-model="determination.determination_note" rows="3" required />
          </label>
          <div class="actions">
            <button class="primary" type="submit" :disabled="saving">{{ t('lifecycle.save') }}</button>
            <button class="ghost" type="button" @click="openForm = ''">{{ t('lifecycle.cancel') }}</button>
          </div>
        </form>
      </section>
    </template>
  </div>
</template>

<style scoped>
/* The register list, the form shell and every button/alert are the shared
   .rounds/.gate-form/.btn primitives; only this card's own section rhythm
   and the per-document validity verdict colours are genuinely local. */
.lifecycle {
  display: flex;
  flex-direction: column;
  gap: var(--space-5);
}
.lifecycle section {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}
.lifecycle h3 {
  margin: 0;
  font-size: var(--text-lg);
}
.verdict.sound {
  color: var(--color-success-fg);
}
.verdict.doubtful {
  color: var(--color-danger-fg);
}
.verdict.unchecked {
  color: var(--color-black-500);
}
</style>
