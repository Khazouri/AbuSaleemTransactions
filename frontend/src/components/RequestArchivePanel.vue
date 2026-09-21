<script setup>
/**
 * Stage 100 — [D] Appendix 6 row 15: الأرشفة has two مسؤول.
 *
 * ملف اللجنة is المقرر's (meeting_outputs.edit) and ملف الخدمة is الموارد
 * البشرية's (meeting_outputs.add). Each row records its own file, so each form
 * is gated on its own grant; closure refuses until the owed halves exist.
 */
import { reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'

const props = defineProps({
  requestId: { type: Number, required: true },
  archive: { type: Object, required: true },
  // Only while the file stands on a final path and is not closed.
  editable: { type: Boolean, default: false },
})

const emit = defineEmits(['updated'])
const { t } = useI18n()

const FILES = [
  { key: 'committee_file', path: 'committee-file', grant: 'meeting_outputs.edit' },
  { key: 'service_file', path: 'service-file', grant: 'meeting_outputs.add' },
]

const locations = reactive({ committee_file: '', service_file: '' })
const saving = ref('')
const error = ref('')

async function save(file) {
  saving.value = file.key
  error.value = ''
  try {
    const { data } = await api.patch(`/requests/${props.requestId}/archive/${file.path}`, {
      location: locations[file.key],
    })
    locations[file.key] = ''
    emit('updated', data.data)
  } catch (requestError) {
    const errors = requestError.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat().join(' — ')
      : requestError.response?.data?.message ?? t('requestArchive.error')
  } finally {
    saving.value = ''
  }
}
</script>

<template>
  <div class="gate-panel">
    <p class="hint">{{ t('requestArchive.intro') }}</p>
    <dl>
      <div v-for="file in FILES" :key="file.key">
        <span>{{ t(`requestArchive.files.${file.key}`) }}</span>
        <strong v-if="archive[file.key]">
          {{ archive[file.key].location }}
          <small>— {{ archive[file.key].archived_by?.name ?? '—' }}</small>
        </strong>
        <strong v-else-if="file.key === 'service_file' && !archive.service_file_required">
          {{ t('requestArchive.notOwed') }}
        </strong>
        <strong v-else>{{ t('requestArchive.pending') }}</strong>
        <form
          v-if="editable"
          v-can="file.grant"
          class="gate-form"
          @submit.prevent="save(file)"
        >
          <input
            v-model="locations[file.key]"
            type="text"
            required
            maxlength="255"
            :placeholder="t('requestArchive.locationPlaceholder')"
            :aria-label="t(`requestArchive.files.${file.key}`)"
          >
          <button class="btn btn-sm primary" type="submit" :disabled="saving === file.key || !locations[file.key].trim()">
            {{ t('requestArchive.record') }}
          </button>
        </form>
      </div>
    </dl>
    <p v-if="error" class="alert warning" role="alert">{{ error }}</p>
  </div>
</template>
