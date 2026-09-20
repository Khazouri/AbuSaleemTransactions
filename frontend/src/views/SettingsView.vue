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
  <section class="page">
    <div class="heading">
      <div>
        <h2>{{ t('settings.title') }}</h2>
        <p v-if="!loading && !loadError" class="subtitle">{{ settings.length }}</p>
      </div>
      <button v-can="'settings.add'" class="primary" type="button" @click="startCreate">{{ t('settings.add') }}</button>
    </div>

    <p v-if="formError" class="alert">{{ formError }}</p>

    <form v-if="showForm" class="card card-flat card-pad form" @submit.prevent="save">
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

    <div class="card card-flat card-pad list">
      <p v-if="loading" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="loadError" class="alert">{{ t('nav.error') }} <button class="ghost" @click="load">{{ t('common.retry') }}</button></p>
      <p v-else-if="settings.length === 0" class="state">{{ t('settings.empty') }}</p>
      <table v-else class="data-table">
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
.form { margin-bottom: var(--space-4); }
.form h3 { margin: 0 0 var(--space-4); font-size: var(--text-lg); color: var(--color-brand-text); }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--space-4); }
label { display: flex; flex-direction: column; gap: .3rem; font-size: var(--text-base); color: var(--color-black-700); }
input, textarea { padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); color: var(--color-foreground); }
textarea { resize: vertical; }
.field-error { color: var(--color-danger-fg); }
.actions { margin-top: var(--space-5); }
.list { overflow-x: auto; }
.value { white-space: pre-wrap; }
.row-actions { display: flex; justify-content: flex-end; gap: var(--space-2); white-space: nowrap; }
</style>
