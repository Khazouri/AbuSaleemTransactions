<script setup>
/**
 * 2026-10-18 — جهة الاعتماد decides one file its meeting recommended on. The
 * committee only recommends, so this is the file's final result: العميد
 * decides a dean file, and مكتب العميد records the minister's paper قرار —
 * attached — on a minister file. Whether the act is open, and why not, is the
 * endpoint's own refusal (GET …/authority-duties).
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AppModal from './AppModal.vue'
import FileDropZone from './FileDropZone.vue'
import api, { firstError } from '../lib/api'

const props = defineProps({
  meetingId: { type: [Number, String], required: true },
  item: { type: Object, required: true },
})
const emit = defineEmits(['decided', 'close'])
const { t } = useI18n()

const ministerFile = computed(() => props.item.request?.stage_code === 'local_governance_ministry')
const recommendation = computed(() => ({ approve: 'approve', reject: 'decline', defer: 'defer' })[props.item.decision?.outcome] ?? '')

const refusal = ref('')
const loaded = ref(false)
const decision = ref('')
const note = ref('')
const file = ref(null)
const submitting = ref(false)
const error = ref('')

const canSubmit = computed(() => decision.value
  && (decision.value === 'approve' || note.value.trim())
  && (!ministerFile.value || file.value))

onMounted(async () => {
  try {
    const { data } = await api.get(`/meetings/${props.meetingId}/agenda/${props.item.id}/authority-duties`)
    refusal.value = data.data.blocked?.[0]?.reason ?? (data.data.available?.length ? '' : t('paperMeeting.authorityNotYours'))
  } catch (requestError) {
    refusal.value = firstError(requestError, t('requestDetail.loadFailed'))
  } finally {
    loaded.value = true
  }
})

async function submit() {
  if (!canSubmit.value || submitting.value) return
  submitting.value = true
  error.value = ''
  const form = new FormData()
  form.append('decision', decision.value)
  if (note.value.trim()) form.append('note', note.value.trim())
  if (file.value) form.append('decision_file', file.value)
  try {
    await api.post(`/meetings/${props.meetingId}/agenda/${props.item.id}/authority-decision`, form)
    emit('decided')
    emit('close')
  } catch (requestError) {
    error.value = firstError(requestError, t('requestDetail.actionFailed'))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <AppModal :title="t(ministerFile ? 'paperMeeting.ministerTitle' : 'paperMeeting.deanTitle')" @close="!submitting && emit('close')">
    <form class="authority" @submit.prevent="submit">
      <dl class="facts">
        <div><dt>{{ t('paperMeeting.file') }}</dt><dd><span class="ltr">{{ item.request?.reference_number }}</span> — {{ item.request?.title }}</dd></div>
        <div v-if="recommendation">
          <dt>{{ t('paperMeeting.recommendation') }}</dt>
          <dd>{{ t(`paperMeeting.recommendations.${recommendation}`) }}</dd>
        </div>
        <div v-if="item.decision?.comment"><dt>{{ t('paperMeeting.committeeNote') }}</dt><dd>{{ item.decision.comment }}</dd></div>
      </dl>

      <p v-if="!loaded" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="refusal" class="alert" role="alert">{{ refusal }}</p>
      <template v-else>
        <fieldset class="choice" :disabled="submitting">
          <legend>{{ t('paperMeeting.authorityDecision') }}<span class="required-mark" aria-hidden="true">*</span></legend>
          <label v-for="option in ['approve', 'reject']" :key="option" class="pill-choice" :class="{ chosen: decision === option }">
            <input v-model="decision" type="radio" name="authority-decision" :value="option" required />
            <span>{{ t(`paperMeeting.authorityDecisions.${option}`) }}</span>
          </label>
        </fieldset>
        <label class="field">
          <span>{{ decision === 'reject' ? t('paperMeeting.noteRequired') : t('paperMeeting.noteOptional') }}<span v-if="decision === 'reject'" class="required-mark" aria-hidden="true">*</span></span>
          <textarea v-model="note" rows="3" maxlength="2000" :required="decision === 'reject'" :disabled="submitting" />
        </label>
        <div v-if="ministerFile" class="field">
          <span>{{ t('paperMeeting.ministerDecisionFile') }}<span class="required-mark" aria-hidden="true">*</span></span>
          <FileDropZone accept=".pdf,.jpg,.jpeg,.png" :disabled="submitting" @files="(files) => (file = files[0] ?? null)" />
          <p v-if="file" class="chosen-file">
            <span dir="auto">{{ file.name }}</span>
            <button class="link" type="button" :disabled="submitting" @click="file = null">{{ t('paperMeeting.removeFile') }}</button>
          </p>
        </div>
      </template>

      <p v-if="error" class="alert" role="alert">{{ error }}</p>
      <div class="modal-actions">
        <button class="ghost" type="button" :disabled="submitting" @click="emit('close')">{{ t('common.cancel') }}</button>
        <button v-if="loaded && !refusal" class="primary" type="submit" :disabled="!canSubmit || submitting">
          {{ submitting ? t('requestDetail.processing') : t('paperMeeting.recordDecision') }}
        </button>
      </div>
    </form>
  </AppModal>
</template>

<style scoped>
.authority { display: grid; gap: var(--space-3); }
.facts { display: grid; gap: var(--space-2); margin: 0; }
.facts div { display: grid; gap: 0.15rem; }
.facts dt { color: var(--color-muted); font-size: var(--text-sm); }
.facts dd { margin: 0; }
.choice { display: flex; flex-wrap: wrap; gap: var(--space-2); margin: 0; padding: 0; border: 0; min-inline-size: 0; }
.choice legend { margin-block-end: var(--space-1); padding: 0; color: var(--color-black-700); font-size: var(--text-sm); }
.pill-choice { display: inline-flex; align-items: center; gap: var(--space-1); padding: var(--space-1) var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-full); cursor: pointer; }
.pill-choice.chosen { border-color: var(--color-brand); color: var(--color-brand-text); }
.pill-choice input { accent-color: var(--color-brand); }
.field { display: grid; gap: var(--space-1); min-inline-size: 0; color: var(--color-black-700); font-size: var(--text-sm); }
.chosen-file { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); margin: 0; }
</style>
