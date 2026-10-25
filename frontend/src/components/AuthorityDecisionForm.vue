<script setup>
/**
 * 2026-10-25 — جهة الاعتماد decides several files its meeting recommended on
 * with one paper قرار. The committee only recommends, so this is each file's
 * result: العميد decides a dean file, and مكتب العميد records the minister's
 * قرار on a minister file. One اشاري, one date and one set of scanned pages
 * cover every ticked file; each file still gets its own اعتماد or عدم اعتماد.
 *
 * Which rows can be ticked, and why not, is the endpoint's own refusal
 * (GET …/authority-duties), so the table and the submit cannot disagree.
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AppModal from './AppModal.vue'
import ScannedPagesInput from './ScannedPagesInput.vue'
import api, { firstError } from '../lib/api'

const props = defineProps({
  meetingId: { type: [Number, String], required: true },
  // The agenda items awaiting this authority, as the meeting payload has them.
  items: { type: Array, required: true },
  // «المهام المعلقة» opens the form on one file, already ticked.
  initialItemId: { type: Number, default: null },
})
const emit = defineEmits(['decided', 'close'])
const { t } = useI18n()

const RECOMMENDATION = { approve: 'approve', reject: 'decline' }
// The input's own date, in the user's day, so «today» is selectable and nothing later is.
const today = new Date().toLocaleDateString('en-CA')

const reasons = ref({})
const loaded = ref(false)
const loadError = ref('')
const rows = reactive(Object.fromEntries(props.items.map((item) => [item.id, {
  checked: item.id === props.initialItemId,
  decision: '',
  note: '',
}])))
const reference = ref('')
const decidedOn = ref(today)
const pages = ref([])
const submitting = ref(false)
const error = ref('')

const blocked = (item) => !loaded.value || Boolean(loadError.value) || Boolean(reasons.value[item.id])
const ticked = computed(() => props.items.filter((item) => rows[item.id].checked && !blocked(item)))
const canSubmit = computed(() => ticked.value.length > 0
  && ticked.value.every((item) => {
    const row = rows[item.id]
    return row.decision === 'approve' || (row.decision === 'reject' && row.note.trim())
  })
  && reference.value.trim()
  && decidedOn.value && decidedOn.value <= today
  && pages.value.length > 0)

onMounted(async () => {
  try {
    const { data } = await api.get(`/meetings/${props.meetingId}/authority-duties`)
    // An item the endpoint does not list is not this user's to decide.
    reasons.value = Object.fromEntries(props.items.map((item) => {
      const duty = data.items.find((entry) => entry.id === item.id)
      return [item.id, duty ? duty.reason : t('authorityBulk.notYours')]
    }))
  } catch (requestError) {
    loadError.value = firstError(requestError, t('requestDetail.loadFailed'))
  } finally {
    loaded.value = true
  }
})

async function submit() {
  if (!canSubmit.value || submitting.value) return
  submitting.value = true
  error.value = ''
  const form = new FormData()
  ticked.value.forEach((item) => {
    form.append(`items[${item.id}][decision]`, rows[item.id].decision)
    if (rows[item.id].note.trim()) form.append(`items[${item.id}][note]`, rows[item.id].note.trim())
  })
  form.append('reference', reference.value.trim())
  form.append('decided_on', decidedOn.value)
  pages.value.forEach((page, index) => form.append(`pages[${index}]`, page))
  try {
    const { data } = await api.post(`/meetings/${props.meetingId}/authority-decisions`, form)
    emit('decided', data.message)
    emit('close')
  } catch (requestError) {
    error.value = firstError(requestError, t('requestDetail.actionFailed'))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <AppModal :title="t('authorityBulk.title')" wide @close="!submitting && emit('close')">
    <form class="authority" @submit.prevent="submit">
      <p class="intro">{{ t('authorityBulk.intro') }}</p>
      <p v-if="!loaded" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="loadError" class="alert" role="alert">{{ loadError }}</p>

      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th scope="col">{{ t('authorityBulk.select') }}</th>
              <th scope="col">{{ t('paperMeeting.file') }}</th>
              <th scope="col">{{ t('paperMeeting.recommendation') }}</th>
              <th scope="col">{{ t('paperMeeting.authorityDecision') }}</th>
              <th scope="col">{{ t('authorityBulk.note') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in items" :key="item.id" :class="{ chosen: rows[item.id].checked && !blocked(item) }">
              <td>
                <input
                  v-model="rows[item.id].checked"
                  type="checkbox"
                  :disabled="submitting || blocked(item)"
                  :aria-label="t('authorityBulk.selectRow', { ref: item.request.reference_number })"
                />
              </td>
              <td>
                <RouterLink :to="{ name: 'request_details', params: { id: item.request.id } }">
                  <span class="ltr">{{ item.request.reference_number }}</span> {{ item.request.title }}
                </RouterLink>
              </td>
              <td>
                {{ t(`paperMeeting.recommendations.${RECOMMENDATION[item.decision.outcome]}`) }}
                <p v-if="item.decision.comment" class="comment">{{ item.decision.comment }}</p>
              </td>
              <td>
                <p v-if="loaded && reasons[item.id]" class="reason">{{ reasons[item.id] }}</p>
                <fieldset
                  v-else
                  class="choice"
                  :disabled="submitting || !rows[item.id].checked"
                  :aria-label="t('authorityBulk.decisionFor', { ref: item.request.reference_number })"
                >
                  <label v-for="option in ['approve', 'reject']" :key="option" class="pill-choice" :class="{ chosen: rows[item.id].decision === option }">
                    <input v-model="rows[item.id].decision" type="radio" :name="`authority-${item.id}`" :value="option" :required="rows[item.id].checked" />
                    <span>{{ t(`paperMeeting.authorityDecisions.${option}`) }}</span>
                  </label>
                </fieldset>
              </td>
              <td>
                <textarea
                  v-if="!reasons[item.id]"
                  v-model="rows[item.id].note"
                  rows="2"
                  maxlength="2000"
                  :placeholder="rows[item.id].decision === 'reject' ? t('paperMeeting.noteRequired') : t('paperMeeting.noteOptional')"
                  :aria-label="t('authorityBulk.noteFor', { ref: item.request.reference_number })"
                  :required="rows[item.id].checked && rows[item.id].decision === 'reject'"
                  :disabled="submitting || !rows[item.id].checked"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <fieldset class="document" :disabled="submitting">
        <legend>{{ t('authorityBulk.document') }}</legend>
        <div class="document-fields">
          <label class="field">
            <span>{{ t('authorityBulk.reference') }}<span class="required-mark" aria-hidden="true">*</span></span>
            <input v-model="reference" type="text" maxlength="100" dir="auto" required />
          </label>
          <label class="field">
            <span>{{ t('authorityBulk.decidedOn') }}<span class="required-mark" aria-hidden="true">*</span></span>
            <input v-model="decidedOn" type="date" :max="today" required />
          </label>
        </div>
        <div class="field">
          <span>{{ t('authorityBulk.pages') }}<span class="required-mark" aria-hidden="true">*</span></span>
          <ScannedPagesInput v-model="pages" :disabled="submitting" />
        </div>
      </fieldset>

      <p v-if="error" class="alert" role="alert">{{ error }}</p>
      <div class="modal-actions">
        <button class="ghost" type="button" :disabled="submitting" @click="emit('close')">{{ t('common.cancel') }}</button>
        <button class="primary" type="submit" :disabled="!canSubmit || submitting">
          {{ submitting ? t('requestDetail.processing') : t('authorityBulk.submit', { count: ticked.length }) }}
        </button>
      </div>
    </form>
  </AppModal>
</template>

<style scoped>
.authority { display: grid; gap: var(--space-3); min-inline-size: 0; }
.intro { margin: 0; color: var(--color-black-700); }
tr.chosen td { background: var(--color-surface-hover); }
.comment, .reason { margin: 0; color: var(--color-muted); font-size: var(--text-sm); }
.comment { margin-block-start: var(--space-1); }
.choice { display: flex; flex-wrap: wrap; gap: var(--space-1); margin: 0; padding: 0; border: 0; min-inline-size: 0; }
.pill-choice { display: inline-flex; align-items: center; gap: var(--space-1); padding: var(--space-1) var(--space-2); border: 1px solid var(--color-border); border-radius: var(--radius-full); cursor: pointer; white-space: nowrap; }
.pill-choice.chosen { border-color: var(--color-brand); color: var(--color-brand-text); }
.pill-choice input { accent-color: var(--color-brand); }
textarea { inline-size: 100%; min-inline-size: 10rem; }
.document { display: grid; gap: var(--space-3); margin: 0; padding: var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-lg); min-inline-size: 0; }
.document legend { padding: 0 var(--space-1); color: var(--color-black-700); font-size: var(--text-sm); }
.document-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: var(--space-3); }
.field { display: grid; gap: var(--space-1); min-inline-size: 0; color: var(--color-black-700); font-size: var(--text-sm); }
</style>
