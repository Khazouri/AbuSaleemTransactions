<script setup>
/** Read-only audit trail viewer — Stage 22. */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import { downloadExport } from '../lib/download'

const { t, te, locale } = useI18n()
const logs = ref([])
const options = ref({ actions: [], models: [], users: [] })
const loading = ref(false)
const loadingOptions = ref(false)
const loadError = ref(null)
const page = ref({ current_page: 1, last_page: 1, total: 0 })
const filters = ref(blankFilters())
// Details are opened one row at a time; the diff table is tall enough that
// several expanded at once turns the log into a wall of values.
const openRow = ref(null)

function blankFilters() {
  return { user_id: '', action: '', model: '', record_id: '', date_from: '', date_to: '' }
}

// Stage 24 — which export format is mid-download, or null when idle.
const exporting = ref(null)
const exportError = ref(null)

const isBusy = computed(() => loading.value || loadingOptions.value || exporting.value !== null)

/** Falls back to the raw key so a model added server-side is still readable. */
function modelLabel(key) {
  const path = `auditLog.models.${key}`
  return te(path) ? t(path) : key
}

function actionLabel(action) {
  const path = `auditLog.actions.${action}`
  return te(path) ? t(path) : action
}

function fieldLabel(field) {
  const path = `auditLog.fields.${field}`
  return te(path) ? t(path) : field
}

function timestamp(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', {
    year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  }).format(new Date(value))
}

/** JSON columns hold whatever the model stored, so objects need flattening. */
function displayValue(value) {
  if (value === null || value === undefined || value === '') return t('common.none')
  if (typeof value === 'boolean') return value ? t('common.active') : t('common.inactive')
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

function queryFor(requestedPage) {
  const query = { page: requestedPage }
  for (const [key, value] of Object.entries(filters.value)) {
    if (value !== '' && value !== null) query[key] = value
  }
  return query
}

async function load(requestedPage = 1) {
  loading.value = true
  loadError.value = null
  openRow.value = null
  try {
    const { data } = await api.get('/audit-logs', { params: queryFor(requestedPage) })
    logs.value = data.data ?? []
    page.value = data.meta ?? page.value
  } catch (error) {
    loadError.value = error
  } finally {
    loading.value = false
  }
}

async function loadOptions() {
  loadingOptions.value = true
  try {
    const { data } = await api.get('/audit-logs/filters')
    options.value = data.data ?? options.value
  } catch (error) {
    loadError.value = error
  } finally {
    loadingOptions.value = false
  }
}

/**
 * Stage 24 — the trail as a file, honouring the filters currently applied
 * rather than only the page on screen.
 */
async function exportAs(format) {
  exporting.value = format
  exportError.value = null
  try {
    // queryFor() carries a page number, which an export must not have — it
    // covers the whole filtered set, not the page being looked at.
    const { page: _page, ...params } = queryFor(1)

    await downloadExport(
      '/audit-logs/export',
      { ...params, format, locale: locale.value },
      `audit-log.${format}`,
    )
  } catch (error) {
    exportError.value = error?.response?.data?.message ?? t('auditLog.exportFailed')
  } finally {
    exporting.value = null
  }
}

function applyFilters() { load(1) }
function clearFilters() { filters.value = blankFilters(); load(1) }
function toggleDetails(id) { openRow.value = openRow.value === id ? null : id }

onMounted(async () => {
  await Promise.all([load(), loadOptions()])
})
</script>

<template>
  <section class="audit">
    <div class="heading">
      <div>
        <h2>{{ t('auditLog.title') }}</h2>
        <p class="subtitle">{{ t('auditLog.subtitle') }}</p>
      </div>
      <div class="heading-end">
        <p v-if="!loading && !loadError" class="count">{{ t('auditLog.entries', { count: page.total }) }}</p>
        <!-- Stage 24 — reading the trail and taking a copy of it are separate
             grants, hence the export-specific v-can. -->
        <div v-can="'audit_log.export'" class="export-actions">
          <button class="ghost" type="button" :disabled="isBusy" @click="exportAs('xlsx')">
            {{ exporting === 'xlsx' ? t('reports.exporting') : t('reports.exportExcel') }}
          </button>
          <button class="ghost" type="button" :disabled="isBusy" @click="exportAs('pdf')">
            {{ exporting === 'pdf' ? t('reports.exporting') : t('reports.exportPdf') }}
          </button>
        </div>
      </div>
    </div>

    <form class="card filters" @submit.prevent="applyFilters">
      <h3>{{ t('auditLog.filters') }}</h3>
      <div class="filter-grid">
        <label>
          {{ t('auditLog.user') }}
          <select v-model="filters.user_id" :disabled="loadingOptions">
            <option value="">{{ t('auditLog.allUsers') }}</option>
            <option v-for="user in options.users" :key="user.id" :value="user.id">{{ user.name }}</option>
          </select>
        </label>
        <label>
          {{ t('auditLog.action') }}
          <select v-model="filters.action" :disabled="loadingOptions">
            <option value="">{{ t('auditLog.allActions') }}</option>
            <option v-for="action in options.actions" :key="action" :value="action">{{ actionLabel(action) }}</option>
          </select>
        </label>
        <label>
          {{ t('auditLog.model') }}
          <select v-model="filters.model" :disabled="loadingOptions">
            <option value="">{{ t('auditLog.allModels') }}</option>
            <option v-for="model in options.models" :key="model" :value="model">{{ modelLabel(model) }}</option>
          </select>
        </label>
        <label>
          {{ t('auditLog.recordId') }}
          <input v-model="filters.record_id" type="number" min="1" :placeholder="t('auditLog.recordIdHint')" />
        </label>
        <label>
          {{ t('auditLog.dateFrom') }}
          <input v-model="filters.date_from" type="date" />
        </label>
        <label>
          {{ t('auditLog.dateTo') }}
          <input v-model="filters.date_to" type="date" />
        </label>
      </div>
      <div class="actions">
        <button class="primary" type="submit" :disabled="isBusy">{{ t('auditLog.apply') }}</button>
        <button class="ghost" type="button" :disabled="isBusy" @click="clearFilters">{{ t('auditLog.clear') }}</button>
      </div>
    </form>

    <p v-if="loadError" class="alert">
      {{ t('nav.error') }}
      <button class="ghost" type="button" @click="load(page.current_page)">{{ t('common.retry') }}</button>
    </p>
    <p v-if="exportError" class="alert">{{ exportError }}</p>

    <div class="card list">
      <p v-if="loading" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="!loadError && logs.length === 0" class="state">{{ t('auditLog.empty') }}</p>
      <div v-else-if="!loadError" class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{{ t('auditLog.when') }}</th>
              <th>{{ t('auditLog.user') }}</th>
              <th>{{ t('auditLog.action') }}</th>
              <th>{{ t('auditLog.record') }}</th>
              <th>{{ t('auditLog.origin') }}</th>
              <th>{{ t('auditLog.changes') }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="log in logs" :key="log.id">
              <tr>
                <td class="when">{{ timestamp(log.created_at) }}</td>
                <td>
                  <span v-if="log.user">{{ log.user.name }}</span>
                  <span v-else class="system">{{ t('auditLog.system') }}</span>
                </td>
                <td>
                  <span class="action" :class="log.action">{{ actionLabel(log.action) }}</span>
                </td>
                <td>
                  <span class="model">{{ modelLabel(log.model) }}</span>
                  <RouterLink
                    v-if="log.model === 'request'"
                    class="record-link ltr"
                    :to="{ name: 'request_details', params: { id: log.record_id } }"
                  >#{{ log.record_id }}</RouterLink>
                  <span v-else class="record-id ltr">#{{ log.record_id }}</span>
                </td>
                <td class="ltr origin">{{ log.ip_address || t('common.none') }}</td>
                <td>
                  <button
                    v-if="log.changed_keys.length"
                    class="ghost"
                    type="button"
                    :aria-expanded="openRow === log.id"
                    @click="toggleDetails(log.id)"
                  >
                    {{ openRow === log.id ? t('auditLog.hideDetails') : t('auditLog.showDetails', { count: log.changed_keys.length }) }}
                  </button>
                  <span v-else class="system">{{ t('common.none') }}</span>
                </td>
              </tr>
              <tr v-if="openRow === log.id" class="details-row">
                <td colspan="6">
                  <table class="diff">
                    <thead>
                      <tr>
                        <th>{{ t('auditLog.field') }}</th>
                        <th>{{ t('auditLog.oldValue') }}</th>
                        <th>{{ t('auditLog.newValue') }}</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="field in log.changed_keys" :key="field">
                        <td class="field">{{ fieldLabel(field) }}</td>
                        <td class="old">{{ displayValue(log.old_values?.[field]) }}</td>
                        <td class="new">{{ displayValue(log.new_values?.[field]) }}</td>
                      </tr>
                    </tbody>
                  </table>
                  <p v-if="log.user_agent" class="agent ltr">{{ log.user_agent }}</p>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    <nav v-if="!loading && !loadError && page.last_page > 1" class="pagination" :aria-label="t('auditLog.title')">
      <button class="ghost" :disabled="page.current_page <= 1" @click="load(page.current_page - 1)">{{ t('requests.previous') }}</button>
      <span>{{ t('requests.page', { current: page.current_page, last: page.last_page }) }}</span>
      <button class="ghost" :disabled="page.current_page >= page.last_page" @click="load(page.current_page + 1)">{{ t('requests.next') }}</button>
    </nav>
  </section>
</template>

<style scoped>
.heading { display: flex; align-items: end; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
h2 { margin: 0; color: var(--color-brand-text); font-size: 1.2rem; }
.subtitle { margin: .15rem 0 0; color: var(--color-muted); font-size: .82rem; }
.count { margin: 0; color: var(--color-muted); font-size: .82rem; }
.heading-end { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; }
.export-actions { display: flex; gap: .5rem; }
.filters, .list { padding: 1.25rem; margin-bottom: 1rem; }
.filters h3 { margin: 0 0 1rem; font-size: 1rem; }
.filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 1rem; }
label { display: flex; flex-direction: column; gap: .3rem; color: var(--color-black-700); font-size: .85rem; }
select, input { min-width: 0; padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); }
select:focus, input:focus { outline: 2px solid var(--color-brand-text); outline-offset: 1px; }
.actions { display: flex; gap: .5rem; margin-top: 1rem; }
button { cursor: pointer; border-radius: var(--radius-lg); font-size: .85rem; }
.primary { padding: .5rem .9rem; border: 0; color: var(--color-on-brand); background: var(--color-brand); }
.ghost { padding: .4rem .65rem; border: 1px solid var(--color-border-hover); background: var(--color-surface); color: var(--color-black-700); }
.ghost:hover:not(:disabled) { background: var(--color-surface-hover); }
button:disabled { cursor: not-allowed; opacity: .55; }
.alert { padding: .65rem .8rem; margin: 0 0 1rem; border: 1px solid var(--color-danger-border); border-radius: var(--radius-lg); color: var(--color-danger-fg); background: var(--color-danger-bg); }
.alert .ghost { margin-inline-start: .5rem; }
.state { padding: .5rem; margin: 0; color: var(--color-muted); }
.table-wrap { overflow-x: auto; }
table { width: 100%; min-width: 760px; border-collapse: collapse; }
th, td { padding: .7rem .55rem; text-align: start; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
th { color: var(--color-muted); font-size: .75rem; font-weight: 600; white-space: nowrap; }
td { font-size: .84rem; }
.when { white-space: nowrap; }
.system { color: var(--color-muted); }
.action { display: inline-block; padding: .12rem .5rem; border-radius: 999px; font-size: .75rem; white-space: nowrap; }
.action.created { color: var(--color-success-fg); background: var(--color-success-bg); }
.action.updated { color: var(--color-warning-fg); background: var(--color-warning-bg); }
.action.deleted { color: var(--color-danger-fg); background: var(--color-danger-bg); }
.action.restored { color: var(--color-info-fg); background: var(--color-info-bg); }
.model { margin-inline-end: .35rem; font-weight: 600; }
.record-id, .record-link { display: inline-block; font-family: var(--font-mono); font-size: .75rem; color: var(--color-muted); }
.record-link { color: var(--color-brand-text); }
.origin { font-family: var(--font-mono); font-size: .75rem; color: var(--color-muted); }
.details-row > td { background: var(--color-surface-hover); }
.diff { min-width: 0; }
.diff th, .diff td { padding: .4rem .5rem; border-bottom: 1px solid var(--color-border); font-size: .8rem; }
.diff .field { font-weight: 600; }
.diff .old { color: var(--color-danger-fg); }
.diff .new { color: var(--color-success-fg); }
.agent { margin: .6rem 0 0; color: var(--color-muted); font-size: .72rem; word-break: break-all; }
.pagination { display: flex; align-items: center; justify-content: center; gap: .75rem; color: var(--color-muted); font-size: .84rem; }
</style>
