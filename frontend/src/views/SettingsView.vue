<script setup>
/**
 * General settings screen — Stage 10.
 *
 * Keeps values as plain text on purpose. Type-specific interpretation belongs
 * to the later feature that owns a particular key, not to this generic editor.
 */
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'

const { t } = useI18n()
const settings = ref([])
const loading = ref(false)
const saving = ref(false)
const loadError = ref(null)
const formError = ref(null)
const errors = ref({})
const editingId = ref(null)
const showForm = ref(false)

const blankForm = () => ({ key: '', value: '' })
const form = ref(blankForm())

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const { data } = await api.get('/settings')
    settings.value = data.data ?? data
  } catch (e) {
    loadError.value = e
  } finally {
    loading.value = false
  }
}

function startCreate() {
  editingId.value = null
  form.value = blankForm()
  errors.value = {}
  formError.value = null
  showForm.value = true
}

function startEdit(setting) {
  editingId.value = setting.id
  form.value = { key: setting.key, value: setting.value ?? '' }
  errors.value = {}
  formError.value = null
  showForm.value = true
}

function cancelForm() {
  editingId.value = null
  showForm.value = false
  errors.value = {}
  formError.value = null
}

async function save() {
  saving.value = true
  errors.value = {}
  formError.value = null
  try {
    const payload = { ...form.value, value: form.value.value || null }
    if (editingId.value === null) await api.post('/settings', payload)
    else await api.put(`/settings/${editingId.value}`, payload)
    cancelForm()
    await load()
  } catch (e) {
    if (e?.response?.status === 422) {
      errors.value = e.response.data.errors ?? {}
      formError.value = e.response.data.message ?? null
    } else {
      formError.value = 'تعذّر الحفظ. حاول مرة أخرى.'
    }
  } finally {
    saving.value = false
  }
}

async function remove(setting) {
  if (!window.confirm(t('common.confirmDelete'))) return
  formError.value = null
  try {
    await api.delete(`/settings/${setting.id}`)
    await load()
  } catch (e) {
    formError.value = e?.response?.data?.message ?? 'تعذّر الحذف.'
  }
}

onMounted(load)
</script>

<template>
  <section>
    <div class="toolbar">
      <button v-can="'settings.add'" class="primary" @click="startCreate">+ {{ t('settings.add') }}</button>
    </div>

    <p v-if="formError" class="alert">{{ formError }}</p>

    <form v-if="showForm" class="card form" @submit.prevent="save">
      <h3>{{ editingId === null ? t('settings.add') : t('settings.edit') }}</h3>
      <div class="grid">
        <label>
          {{ t('settings.key') }} *
          <input v-model="form.key" class="ltr" type="text" required />
          <small class="hint">{{ t('settings.keyHint') }}</small>
          <small v-if="errors.key" class="field-error">{{ errors.key[0] }}</small>
        </label>
        <label>
          {{ t('settings.value') }}
          <textarea v-model="form.value" rows="3" />
          <small v-if="errors.value" class="field-error">{{ errors.value[0] }}</small>
        </label>
      </div>
      <div class="actions">
        <button class="primary" type="submit" :disabled="saving">{{ saving ? t('common.saving') : t('common.save') }}</button>
        <button class="ghost" type="button" @click="cancelForm">{{ t('common.cancel') }}</button>
      </div>
    </form>

    <div class="card list">
      <p v-if="loading" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="loadError" class="state error">{{ t('nav.error') }} <button class="ghost" @click="load">{{ t('common.retry') }}</button></p>
      <p v-else-if="settings.length === 0" class="state">{{ t('settings.empty') }}</p>
      <table v-else>
        <thead><tr><th>{{ t('settings.key') }}</th><th>{{ t('settings.value') }}</th><th></th></tr></thead>
        <tbody>
          <tr v-for="setting in settings" :key="setting.id">
            <td><code class="ltr">{{ setting.key }}</code></td>
            <td class="value">{{ setting.value || t('common.none') }}</td>
            <td class="row-actions">
              <button v-can="'settings.edit'" class="ghost" @click="startEdit(setting)">{{ t('common.edit') }}</button>
              <button v-can="'settings.delete'" class="ghost danger" @click="remove(setting)">{{ t('common.delete') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.toolbar { margin-bottom: 1rem; }.card { padding: 1.25rem; margin-bottom: 1rem; }.form h3 { margin: 0 0 1rem; font-size: 1rem; }.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; }label { display: flex; flex-direction: column; gap: .3rem; font-size: .875rem; }input, textarea { padding: .5rem .6rem; border: 1px solid var(--color-border); border-radius: var(--radius-lg); background: var(--color-surface); color: var(--color-foreground); }textarea { resize: vertical; }.hint, .state { color: var(--color-muted); font-size: .8rem; }.field-error, .state.error { color: var(--color-red); }.actions { display: flex; gap: .5rem; margin-top: 1.25rem; }.alert { padding: .65rem .8rem; color: var(--color-red); border: 1px solid var(--color-red); border-radius: var(--radius-lg); margin: 0 0 1rem; }.list { overflow-x: auto; }table { width: 100%; border-collapse: collapse; }th { text-align: start; color: var(--color-muted); font-size: .78rem; }th, td { padding: .6rem .5rem; border-bottom: 1px solid var(--color-border); }tr:last-child td { border-bottom: 0; }.value { white-space: pre-wrap; }.row-actions { text-align: end; white-space: nowrap; }button { cursor: pointer; border-radius: var(--radius-lg); font-size: .85rem; }.primary { padding: .5rem .9rem; border: 0; background: var(--color-primary); color: var(--color-on-primary); }.ghost { padding: .35rem .6rem; border: 1px solid var(--color-border); background: var(--color-surface); color: var(--color-foreground); margin-inline-start: .3rem; }.ghost.danger { color: var(--color-red); border-color: var(--color-red); }
</style>
