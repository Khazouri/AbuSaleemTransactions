<script setup>
/** Stage 13 notes panel; Stage 15 mounts it in the transaction detail screen. */
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'

const props = defineProps({ transactionId: { type: [Number, String], required: true } })
const { t, locale } = useI18n()
const notes = ref([])
const body = ref('')
const loading = ref(false)
const posting = ref(false)
const error = ref('')

function formatDate(value) {
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/transactions/${props.transactionId}/notes`)
    notes.value = data.data ?? []
  } catch (requestError) {
    error.value = requestError.response?.data?.message ?? t('notes.loadFailed')
  } finally {
    loading.value = false
  }
}

async function add() {
  if (!body.value.trim()) return
  posting.value = true
  error.value = ''
  try {
    const { data } = await api.post(`/transactions/${props.transactionId}/notes`, { body: body.value.trim(), is_internal: true })
    notes.value.push(data.data)
    body.value = ''
  } catch (requestError) {
    error.value = requestError.response?.data?.errors?.body?.[0] ?? requestError.response?.data?.message ?? t('notes.submitFailed')
  } finally {
    posting.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="notes">
    <h3>{{ t('notes.title') }}</h3>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-if="loading" class="state">{{ t('common.loading') }}</p>
    <p v-else-if="!notes.length" class="state">{{ t('notes.empty') }}</p>
    <ol v-else class="note-list">
      <li v-for="note in notes" :key="note.id">
        <p>{{ note.body }}</p>
        <small>{{ note.created_by?.name || t('common.none') }} · {{ formatDate(note.created_at) }}</small>
      </li>
    </ol>
    <form v-can="'notes_attachments.add'" class="composer" @submit.prevent="add">
      <label>
        {{ t('notes.add') }}
        <textarea v-model="body" rows="3" maxlength="5000" :disabled="posting" />
      </label>
      <button class="primary" type="submit" :disabled="posting || !body.trim()">{{ posting ? t('notes.posting') : t('notes.post') }}</button>
    </form>
  </section>
</template>

<style scoped>
.notes h3 { margin: 0 0 .8rem; color: var(--color-nav); font-size: 1rem; }.note-list { display: grid; gap: .65rem; padding: 0; margin: 0; list-style: none; }.note-list li { padding: .7rem; border: 1px solid var(--color-border); border-radius: var(--radius-lg); }.note-list p { margin: 0 0 .35rem; white-space: pre-wrap; }.note-list small, .state { color: var(--color-muted); font-size: .78rem; }.error { color: #b91c1c; }.composer { display: grid; gap: .65rem; margin-top: 1rem; }.composer label { display: grid; gap: .3rem; font-size: .85rem; }.composer textarea { resize: vertical; padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); font: inherit; }.primary { justify-self: start; padding: .5rem .9rem; border: 0; border-radius: var(--radius-lg); color: #fff; background: var(--color-nav); cursor: pointer; }.primary:disabled { cursor: not-allowed; opacity: .6; }
</style>
