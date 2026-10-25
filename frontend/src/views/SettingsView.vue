<script setup>
/**
 * General settings screen — Stage 10.
 *
 * Keeps values as plain text on purpose. Type-specific interpretation belongs
 * to the later feature that owns a particular key, not to this generic editor.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import AppModal from '../components/AppModal.vue'

const { t } = useI18n()
const settings = ref([])
const loading = ref(false)
const saving = ref(false)
const loadError = ref(null)
const formError = ref(null)
const errors = ref({})
const editingId = ref(null)
const showForm = ref(false)

// Typed settings (user decisions 2026-10-16) get their own controls above the
// table, so they are kept out of the generic one. The server reads them as
// Setting::enabled() and Setting::meetingAcceptancesRequired().
const BYPASS_KEY = 'legal_review_bypass'
const ACCEPTANCES_KEY = 'meeting_acceptances_required'
const AUTO_ACCEPT_KEY = 'meeting_auto_accept'
const bypass = computed(() => settings.value.find((s) => s.key === BYPASS_KEY))
const acceptances = computed(() => settings.value.find((s) => s.key === ACCEPTANCES_KEY))
const autoAccept = computed(() => settings.value.find((s) => s.key === AUTO_ACCEPT_KEY))
// «المهام المعلقة»'s category colours (user decision 2026-10-19), one seeded
// `task_color_<source>` row each, read by the server as Setting::taskColors().
const COLOR_PREFIX = 'task_color_'
const taskColors = computed(() => settings.value.filter((s) => s.key.startsWith(COLOR_PREFIX)))
const rows = computed(() =>
  settings.value.filter((s) => ![BYPASS_KEY, ACCEPTANCES_KEY, AUTO_ACCEPT_KEY].includes(s.key) && !s.key.startsWith(COLOR_PREFIX)),
)
const ownSaving = ref(false)

/** Saves one typed setting; false (with the server's reason shown) when refused. */
async function saveOwn(setting, key, value) {
  ownSaving.value = true
  formError.value = null
  try {
    if (setting) await api.put(`/settings/${setting.id}`, { key, value })
    else await api.post('/settings', { key, value })
    await load()
    return true
  } catch (e) {
    formError.value = e?.response?.data?.errors?.value?.[0] ?? e?.response?.data?.message ?? 'تعذّر الحفظ. حاول مرة أخرى.'
    return false
  } finally {
    ownSaving.value = false
  }
}

async function toggle(setting, key, event) {
  if (!(await saveOwn(setting, key, event.target.checked ? '1' : '0'))) {
    event.target.checked = !event.target.checked
  }
}

async function saveAcceptances(event) {
  if (!(await saveOwn(acceptances.value, ACCEPTANCES_KEY, event.target.value))) {
    event.target.value = acceptances.value?.value ?? '3'
  }
}

async function saveColor(setting, event) {
  if (!(await saveOwn(setting, setting.key, event.target.value))) event.target.value = setting.value
}

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
        <p v-if="!loading && !loadError" class="subtitle">{{ rows.length }}</p>
      </div>
      <button v-can="'settings.add'" class="primary" type="button" @click="startCreate">{{ t('settings.add') }}</button>
    </div>

    <p v-if="formError && !showForm" class="alert">{{ formError }}</p>

    <AppModal v-if="showForm" :title="editingId === null ? t('settings.add') : t('settings.edit')" @close="cancelForm">
      <form class="form" @submit.prevent="save">
        <p v-if="formError" class="alert">{{ formError }}</p>
        <div class="grid">
          <label>
            <span>{{ t('settings.key') }}<span class="required-mark" aria-hidden="true">*</span></span>
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
        <div class="modal-actions">
          <button class="ghost" type="button" @click="cancelForm">{{ t('common.cancel') }}</button>
          <button class="primary" type="submit" :disabled="saving">{{ saving ? t('common.saving') : t('common.save') }}</button>
        </div>
      </form>
    </AppModal>

    <!-- Legal review bypass (user decision 2026-10-16). -->
    <div v-if="!loading && !loadError" v-can="'settings.edit'" class="card card-flat card-pad toggle">
      <label class="checkbox">
        <input type="checkbox" :checked="bypass?.value === '1'" :disabled="ownSaving" @change="toggle(bypass, BYPASS_KEY, $event)" />
        <strong>{{ t('settings.legalReviewBypass') }}</strong>
      </label>
      <small class="hint">{{ t('settings.legalReviewBypassHint') }}</small>
    </div>

    <!-- Meeting date acceptances (user decision 2026-10-16). -->
    <div v-if="!loading && !loadError" v-can="'settings.edit'" class="card card-flat card-pad toggle">
      <label>
        <strong>{{ t('settings.meetingAcceptances') }}<span class="required-mark" aria-hidden="true">*</span></strong>
        <input
          class="count"
          type="number"
          min="2"
          max="5"
          step="1"
          required
          :value="acceptances?.value ?? '3'"
          :disabled="ownSaving"
          @change="saveAcceptances"
        />
      </label>
      <small class="hint">{{ t('settings.meetingAcceptancesHint') }}</small>
    </div>

    <!-- Meeting auto-accept (user decision 2026-10-25). -->
    <div v-if="!loading && !loadError" v-can="'settings.edit'" class="card card-flat card-pad toggle">
      <label class="checkbox">
        <input
          type="checkbox"
          :checked="autoAccept?.value === '1'"
          :disabled="ownSaving"
          @change="toggle(autoAccept, AUTO_ACCEPT_KEY, $event)"
        />
        <strong>{{ t('settings.meetingAutoAccept') }}</strong>
      </label>
      <small class="hint">{{ t('settings.meetingAutoAcceptHint') }}</small>
    </div>

    <!-- My Tasks category colours (user decision 2026-10-19). -->
    <div v-if="!loading && !loadError && taskColors.length" v-can="'settings.edit'" class="card card-flat card-pad toggle">
      <strong>{{ t('settings.taskColors') }}</strong>
      <small class="hint">{{ t('settings.taskColorsHint') }}</small>
      <div class="colors">
        <label v-for="setting in taskColors" :key="setting.id" class="color">
          <input type="color" :value="setting.value" :disabled="ownSaving" @change="saveColor(setting, $event)" />
          {{ t(`myTasks.sources.${setting.key.slice(COLOR_PREFIX.length)}`) }}
        </label>
      </div>
    </div>

    <div class="card card-flat card-pad list">
      <p v-if="loading" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="loadError" class="alert">{{ t('nav.error') }} <button class="ghost" @click="load">{{ t('common.retry') }}</button></p>
      <p v-else-if="rows.length === 0" class="state">{{ t('settings.empty') }}</p>
      <table v-else class="data-table">
        <thead><tr><th>{{ t('settings.key') }}</th><th>{{ t('settings.value') }}</th><th></th></tr></thead>
        <tbody>
          <tr v-for="setting in rows" :key="setting.id">
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
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); gap: var(--space-4); }
label { display: flex; flex-direction: column; gap: .3rem; font-size: var(--text-base); color: var(--color-black-700); }
input, textarea { padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); color: var(--color-foreground); }
textarea { resize: vertical; }
.field-error { color: var(--color-danger-fg); }
.list { overflow-x: auto; }
.toggle { display: flex; flex-direction: column; gap: var(--space-2); margin-block-end: var(--space-4); }
.checkbox { flex-direction: row; align-items: center; gap: var(--space-2); color: var(--color-foreground); }
.checkbox input { padding: 0; }
.count { inline-size: 6rem; }
.colors { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(14rem, 100%), 1fr)); gap: var(--space-2); }
.color { flex-direction: row; align-items: center; gap: var(--space-2); color: var(--color-foreground); }
.color input { inline-size: 2.5rem; block-size: 2rem; padding: .1rem; cursor: pointer; }
.value { white-space: pre-wrap; }
.row-actions { display: flex; justify-content: flex-end; gap: var(--space-2); white-space: nowrap; }
</style>
