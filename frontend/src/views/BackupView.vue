<script setup>
/**
 * Backup (النسخ الاحتياطي) — Stage 26.
 *
 * Take a snapshot now, see what snapshots exist, download one, delete one.
 * Every action is R08-only, which is what the `backup` screen's empty grants
 * entry has always meant.
 *
 * There is no restore button, and the note at the bottom of the screen says so.
 * Reloading the database is done on the server with the system stopped; one
 * click away behind a web session is the wrong place for it.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import { downloadExport } from '../lib/download'

const { t, locale } = useI18n()

const rows = ref([])
const page = ref({ current_page: 1, last_page: 1, total: 0 })
const loading = ref(false)
const loadError = ref(null)

const includeFiles = ref(true)
const creating = ref(false)
const actionError = ref(null)
const actionMessage = ref(null)
/** Keyed by backup id so one busy row doesn't freeze the rest of the table. */
const busy = ref({})

const isBusy = computed(() => loading.value || creating.value)

function dateTime(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', {
    year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  }).format(new Date(value))
}

function size(bytes) {
  if (bytes === null || bytes === undefined) return t('common.none')
  const mb = bytes / (1024 * 1024)
  const formatter = new Intl.NumberFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', {
    maximumFractionDigits: mb < 1 ? 2 : 1,
  })
  return mb < 1
    ? `${formatter.format(bytes / 1024)} ${t('backup.kb')}`
    : `${formatter.format(mb)} ${t('backup.mb')}`
}

async function load(requestedPage = 1) {
  loading.value = true
  loadError.value = null
  try {
    const { data } = await api.get('/backups', { params: { page: requestedPage } })
    rows.value = data.data ?? []
    page.value = data.meta ?? page.value
  } catch (error) {
    loadError.value = error
  } finally {
    loading.value = false
  }
}

async function createBackup() {
  creating.value = true
  actionError.value = null
  actionMessage.value = null
  try {
    const { data } = await api.post('/backups', { include_files: includeFiles.value })
    actionMessage.value = t('backup.created', { name: data.data.filename })
    await load(1)
  } catch (error) {
    // A failed dump still recorded a row, so refresh regardless — the reason
    // is on the table as well as in this banner.
    actionError.value = error?.response?.data?.message ?? t('backup.createFailed')
    await load(1)
  } finally {
    creating.value = false
  }
}

async function download(row) {
  busy.value[row.id] = true
  actionError.value = null
  try {
    await downloadExport(`/backups/${row.id}/download`, {}, row.filename)
  } catch (error) {
    actionError.value = error?.response?.data?.message ?? t('backup.downloadFailed')
  } finally {
    busy.value[row.id] = false
  }
}

async function remove(row) {
  if (!window.confirm(t('common.confirmDelete'))) return
  busy.value[row.id] = true
  actionError.value = null
  try {
    await api.delete(`/backups/${row.id}`)
    await load(page.value.current_page)
  } catch (error) {
    actionError.value = error?.response?.data?.message ?? t('backup.deleteFailed')
  } finally {
    busy.value[row.id] = false
  }
}

onMounted(() => load())
</script>

<template>
  <section class="backup">
    <div class="heading">
      <div>
        <h2>{{ t('backup.title') }}</h2>
        <p class="subtitle">{{ t('backup.subtitle') }}</p>
      </div>
      <div v-can="'backup.add'" class="create">
        <label class="checkbox">
          <input v-model="includeFiles" type="checkbox" :disabled="isBusy" />
          {{ t('backup.includeFiles') }}
        </label>
        <button class="primary" type="button" :disabled="isBusy" @click="createBackup">
          {{ creating ? t('backup.creating') : t('backup.create') }}
        </button>
      </div>
    </div>

    <p v-if="creating" class="notice">{{ t('backup.creatingHint') }}</p>
    <p v-if="actionMessage" class="notice success">{{ actionMessage }}</p>
    <p v-if="actionError" class="alert">{{ actionError }}</p>
    <p v-if="loadError" class="alert">
      {{ t('nav.error') }}
      <button class="ghost" type="button" @click="load(page.current_page)">{{ t('common.retry') }}</button>
    </p>

    <div class="card list">
      <p v-if="loading" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="!loadError && rows.length === 0" class="state">{{ t('backup.empty') }}</p>
      <div v-else-if="!loadError" class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{{ t('backup.columns.filename') }}</th>
              <th>{{ t('backup.columns.size') }}</th>
              <th>{{ t('backup.columns.contents') }}</th>
              <th>{{ t('backup.columns.createdBy') }}</th>
              <th>{{ t('backup.columns.createdAt') }}</th>
              <th>{{ t('backup.columns.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id" :class="{ failed: row.status === 'failed' }">
              <td>
                <span class="filename ltr">{{ row.filename }}</span>
                <small v-if="row.status === 'failed'" class="error">{{ row.error }}</small>
                <small v-else-if="!row.file_exists" class="muted">{{ t('backup.fileMissing') }}</small>
              </td>
              <td class="nowrap">{{ size(row.size_bytes) }}</td>
              <td>
                <span class="pill">
                  {{ row.includes_files ? t('backup.databaseAndFiles') : t('backup.databaseOnly') }}
                </span>
              </td>
              <td>{{ row.created_by?.name ?? t('backup.scheduled') }}</td>
              <td class="nowrap">{{ dateTime(row.created_at) }}</td>
              <td>
                <div class="row-actions">
                  <button
                    v-can="'backup.export'"
                    class="ghost"
                    type="button"
                    :disabled="busy[row.id] || !row.file_exists"
                    @click="download(row)"
                  >
                    {{ t('backup.download') }}
                  </button>
                  <button
                    v-can="'backup.delete'"
                    class="ghost danger"
                    type="button"
                    :disabled="busy[row.id]"
                    @click="remove(row)"
                  >
                    {{ t('common.delete') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <nav v-if="!loading && !loadError && page.last_page > 1" class="pagination" :aria-label="t('backup.title')">
      <button class="ghost" :disabled="page.current_page <= 1" @click="load(page.current_page - 1)">
        {{ t('requests.previous') }}
      </button>
      <span>{{ t('requests.page', { current: page.current_page, last: page.last_page }) }}</span>
      <button class="ghost" :disabled="page.current_page >= page.last_page" @click="load(page.current_page + 1)">
        {{ t('requests.next') }}
      </button>
    </nav>

    <p class="restore-note">{{ t('backup.restoreNote') }}</p>
  </section>
</template>

<style scoped>
.heading { display: flex; align-items: end; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; }
h2 { margin: 0; color: var(--color-brand-text); font-size: 1.2rem; }
.subtitle { margin: .15rem 0 0; color: var(--color-muted); font-size: .82rem; }
.create { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; }
.checkbox { display: inline-flex; align-items: center; gap: .35rem; color: var(--color-black-700); font-size: .85rem; }

.list { padding: 1.25rem; margin-bottom: 1rem; }
button { cursor: pointer; border-radius: var(--radius-lg); font-size: .85rem; }
.primary { padding: .5rem .9rem; border: 0; color: var(--color-on-brand); background: var(--color-brand); }
.ghost { padding: .35rem .6rem; border: 1px solid var(--color-border-hover); background: var(--color-surface); color: var(--color-black-700); }
.ghost:hover:not(:disabled) { background: var(--color-surface-hover); }
.ghost.danger { color: var(--color-danger-fg); border-color: var(--color-danger-border); }
button:disabled { cursor: not-allowed; opacity: .55; }

.alert { padding: .65rem .8rem; margin: 0 0 1rem; border: 1px solid var(--color-danger-border); border-radius: var(--radius-lg); color: var(--color-danger-fg); background: var(--color-danger-bg); }
.alert .ghost { margin-inline-start: .5rem; }
.notice { padding: .65rem .8rem; margin: 0 0 1rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); color: var(--color-black-700); background: var(--color-surface); font-size: .85rem; }
.notice.success { border-color: var(--color-success-border); color: var(--color-success-fg); background: var(--color-success-bg); }
.state { padding: .5rem; margin: 0; color: var(--color-muted); }

.table-wrap { overflow-x: auto; }
table { width: 100%; min-width: 800px; border-collapse: collapse; }
th, td { padding: .7rem .55rem; text-align: start; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
th { color: var(--color-muted); font-size: .75rem; font-weight: 600; white-space: nowrap; }
td { font-size: .84rem; }
tr.failed td { background: var(--color-danger-bg); }
.nowrap { white-space: nowrap; }
.filename { font-family: var(--font-mono); font-size: .78rem; }
.muted { display: block; color: var(--color-muted); font-size: .72rem; }
.error { display: block; color: var(--color-danger-fg); font-size: .72rem; }
.pill { display: inline-block; padding: .12rem .5rem; border: 1px solid var(--color-border-hover); border-radius: 999px; font-size: .72rem; white-space: nowrap; }
.row-actions { display: flex; gap: .35rem; }
.pagination { display: flex; align-items: center; justify-content: center; gap: .75rem; color: var(--color-muted); font-size: .84rem; }
.restore-note { margin-top: 1.25rem; padding: .75rem .9rem; border: 1px dashed var(--color-border-hover); border-radius: var(--radius-lg); color: var(--color-muted); font-size: .8rem; }
</style>
