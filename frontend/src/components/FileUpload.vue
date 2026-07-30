<script setup>
/** Reusable transaction attachment picker and uploader — Stage 12. */
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'

const props = defineProps({
  transactionId: { type: [Number, String], required: true },
})

const emit = defineEmits(['uploaded'])
const { t } = useI18n()
const input = ref(null)
const file = ref(null)
const label = ref('')
const error = ref('')
const uploading = ref(false)
const progress = ref(0)

const maxBytes = 20 * 1024 * 1024
const acceptedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png']

function validate(candidate) {
  if (!candidate) return t('attachments.chooseFile')
  const extension = candidate.name.split('.').pop()?.toLowerCase()
  if (!acceptedExtensions.includes(extension)) return t('attachments.invalidType')
  if (candidate.size > maxBytes) return t('attachments.fileTooLarge')
  return ''
}

function choose(event) {
  const candidate = event.target.files?.[0] ?? null
  const validationError = validate(candidate)
  error.value = validationError
  file.value = validationError ? null : candidate
}

function reset() {
  file.value = null
  label.value = ''
  progress.value = 0
  if (input.value) input.value.value = ''
}

async function upload() {
  error.value = validate(file.value)
  if (error.value) return

  uploading.value = true
  progress.value = 0
  const form = new FormData()
  form.append('file', file.value)
  if (label.value.trim()) form.append('label', label.value.trim())

  try {
    const { data } = await api.post(`/transactions/${props.transactionId}/attachments`, form, {
      onUploadProgress: (event) => {
        if (event.total) progress.value = Math.round((event.loaded / event.total) * 100)
      },
    })
    emit('uploaded', data.data)
    reset()
  } catch (requestError) {
    error.value = requestError.response?.data?.errors?.file?.[0]
      ?? requestError.response?.data?.message
      ?? t('attachments.uploadFailed')
  } finally {
    uploading.value = false
  }
}
</script>

<template>
  <form class="file-upload" @submit.prevent="upload">
    <label>
      {{ t('attachments.file') }}
      <input ref="input" type="file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" :disabled="uploading" @change="choose" />
    </label>
    <p class="hint">{{ t('attachments.acceptedHint') }}</p>
    <p v-if="file" class="selected ltr">{{ file.name }}</p>

    <label>
      {{ t('attachments.label') }}
      <input v-model="label" type="text" maxlength="255" :disabled="uploading" />
    </label>

    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <div v-if="uploading" class="progress" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
      <span :style="{ inlineSize: `${progress}%` }" />
    </div>
    <button class="primary" type="submit" :disabled="uploading || !file">
      {{ uploading ? t('attachments.uploading') : t('attachments.upload') }}
    </button>
  </form>
</template>

<style scoped>
.file-upload { display: grid; gap: .75rem; }.file-upload label { display: grid; gap: .3rem; color: var(--color-black-700); font-size: .85rem; }.file-upload input { min-inline-size: 0; padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); }.hint, .selected { margin: -.4rem 0 0; color: var(--color-muted); font-size: .78rem; }.selected { overflow-wrap: anywhere; }.error { margin: 0; color: #b91c1c; font-size: .82rem; }.primary { justify-self: start; padding: .5rem .9rem; border: 0; border-radius: var(--radius-lg); color: #fff; background: var(--color-nav); cursor: pointer; }.primary:disabled { cursor: not-allowed; opacity: .55; }.progress { block-size: .4rem; overflow: hidden; border-radius: var(--radius-full); background: var(--color-border); }.progress span { display: block; block-size: 100%; background: var(--color-primary); transition: inline-size .15s ease; }
</style>
