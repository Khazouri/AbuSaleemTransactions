<script setup>
/**
 * 2026-10-31 — the مقرر confirms جهة الاعتماد's decision on several of this
 * meeting's files at once, the way the authority decided them. Each row puts
 * the committee's توصية beside the authority's decision; a row where the two
 * differ starts unticked and is flagged, since that is what the مقرر is
 * checking. A return needs a note, so it stays on the request page.
 */
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AppModal from './AppModal.vue'
import api, { firstError } from '../lib/api'
import { confirmAction } from '../lib/confirm'

const props = defineProps({
  meetingId: { type: [Number, String], required: true },
  // The agenda items at the مقرر's review, as the meeting payload has them.
  items: { type: Array, required: true },
})
const emit = defineEmits(['confirmed', 'close'])
const { t } = useI18n()

const RECOMMENDATION = { approve: 'approve', reject: 'decline' }
const authorityDecision = (item) => (item.request.status?.code === 'authority_rejected' ? 'reject' : 'approve')
const differs = (item) => authorityDecision(item) !== item.decision.outcome

const checked = reactive(Object.fromEntries(props.items.map((item) => [item.id, !differs(item)])))
const ticked = computed(() => props.items.filter((item) => checked[item.id]))
const submitting = ref(false)
const error = ref('')

async function submit() {
  if (!ticked.value.length || submitting.value) return
  const count = ticked.value.length
  if (!(await confirmAction({
    title: t('confirmDecisions.confirmTitle', { count }),
    message: t('confirmDecisions.consequence', { count }),
    confirmLabel: t('confirmDecisions.submit', { count }),
  }))) return
  submitting.value = true
  error.value = ''
  try {
    const { data } = await api.post(`/meetings/${props.meetingId}/decision-confirmations`, {
      items: ticked.value.map((item) => item.id),
    })
    emit('confirmed', data.message)
    emit('close')
  } catch (requestError) {
    error.value = firstError(requestError, t('requestDetail.actionFailed'))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <AppModal :title="t('confirmDecisions.title')" wide @close="!submitting && emit('close')">
    <form class="confirm-decisions" @submit.prevent="submit">
      <p class="intro">{{ t('confirmDecisions.intro') }}</p>

      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th scope="col">{{ t('authorityBulk.select') }}</th>
              <th scope="col">{{ t('paperMeeting.file') }}</th>
              <th scope="col">{{ t('paperMeeting.recommendation') }}</th>
              <th scope="col">{{ t('paperMeeting.authorityDecision') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in items" :key="item.id" :class="{ chosen: checked[item.id], differs: differs(item) }">
              <td>
                <input
                  v-model="checked[item.id]"
                  type="checkbox"
                  :disabled="submitting"
                  :aria-label="t('authorityBulk.selectRow', { ref: item.request.reference_number })"
                />
              </td>
              <td>
                <RouterLink :to="{ name: 'request_details', params: { id: item.request.id } }">
                  <span class="ltr">{{ item.request.reference_number }}</span> {{ item.request.title }}
                </RouterLink>
              </td>
              <td>{{ t(`paperMeeting.recommendations.${RECOMMENDATION[item.decision.outcome]}`) }}</td>
              <td>
                <span :class="['verdict', authorityDecision(item)]">{{ t(`paperMeeting.authorityDecisions.${authorityDecision(item)}`) }}</span>
                <p v-if="differs(item)" class="mismatch">{{ t('confirmDecisions.mismatch') }}</p>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-if="error" class="alert" role="alert">{{ error }}</p>
      <div class="modal-actions">
        <button class="ghost" type="button" :disabled="submitting" @click="emit('close')">{{ t('common.cancel') }}</button>
        <button class="primary" type="submit" :disabled="!ticked.length || submitting">
          {{ submitting ? t('requestDetail.processing') : t('confirmDecisions.submit', { count: ticked.length }) }}
        </button>
      </div>
    </form>
  </AppModal>
</template>

<style scoped>
.confirm-decisions { display: grid; gap: var(--space-3); min-inline-size: 0; }
.intro { margin: 0; color: var(--color-black-700); }
tr.chosen td { background: var(--color-surface-hover); }
/* The one thing the مقرر is checking: an authority that went against the committee. */
tr.differs td { background: var(--color-warning-bg); }
.verdict { display: inline-block; padding: 0.1rem var(--space-2); border: 1px solid; border-radius: var(--radius-full); font-size: var(--text-sm); white-space: nowrap; }
.verdict.approve { border-color: var(--color-success-border); background: var(--color-success-bg); color: var(--color-success-fg); }
.verdict.reject { border-color: var(--color-danger-border); background: var(--color-danger-bg); color: var(--color-danger-fg); }
.mismatch { margin: var(--space-1) 0 0; color: var(--color-warning-fg); font-size: var(--text-sm); }
</style>
