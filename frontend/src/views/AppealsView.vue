<script setup>
/**
 * Appeals (التظلمات) — Stage 58, Track J.
 *
 * The foundational screen: pick an already-decided request and file an
 * appeal against it. No workflow logic yet — no ownership/decided-status/
 * duplication validation (Stage 59+), so this screen is intentionally thin.
 * The list is scoped server-side to the caller's own appeals unless they're
 * R08 (see AppealController::index).
 */
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'

const { t, locale } = useI18n()

// --- List -------------------------------------------------------------------

const rows = ref([])
const page = ref({ current_page: 1, last_page: 1, total: 0 })
const loading = ref(false)
const loadError = ref(null)

async function load(requestedPage = 1) {
  loading.value = true
  loadError.value = null
  try {
    const { data } = await api.get('/appeals', { params: { page: requestedPage } })
    rows.value = data.data ?? []
    page.value = data.meta ?? page.value
  } catch (error) {
    loadError.value = error
  } finally {
    loading.value = false
  }
}

function dateTime(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', {
    year: 'numeric', month: 'short', day: 'numeric',
  }).format(new Date(value))
}

function statusLabel(status) {
  if (!status) return t('common.none')
  return locale.value === 'ar' ? status.name_ar || status.name_en : status.name_en || status.name_ar
}

// --- Create form --------------------------------------------------------------

const requestSearch = ref('')
const requestResults = ref([])
const requestSearching = ref(false)
const selectedRequest = ref(null)
let searchTimer = null

watch(requestSearch, (value) => {
  clearTimeout(searchTimer)
  if (!value.trim()) {
    requestResults.value = []
    return
  }
  searchTimer = setTimeout(async () => {
    requestSearching.value = true
    try {
      const { data } = await api.get('/requests', { params: { search: value.trim(), per_page: 5 } })
      requestResults.value = data.data ?? []
    } catch {
      requestResults.value = []
    } finally {
      requestSearching.value = false
    }
  }, 300)
})

function pickRequest(request) {
  selectedRequest.value = request
  requestSearch.value = ''
  requestResults.value = []
}

function clearSelection() {
  selectedRequest.value = null
}

const decisionReference = ref('')
const decisionDate = ref('')
const creating = ref(false)
const createError = ref(null)
const createMessage = ref(null)

async function createAppeal() {
  if (!selectedRequest.value) {
    createError.value = t('appeals.create.selectRequestFirst')
    return
  }
  creating.value = true
  createError.value = null
  createMessage.value = null
  try {
    await api.post('/appeals', {
      original_request_id: selectedRequest.value.id,
      original_decision_reference: decisionReference.value || null,
      original_decision_date: decisionDate.value || null,
    })
    createMessage.value = t('appeals.create.success')
    selectedRequest.value = null
    decisionReference.value = ''
    decisionDate.value = ''
    await load(1)
  } catch (error) {
    createError.value = error?.response?.data?.message ?? t('appeals.create.failed')
  } finally {
    creating.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <section class="appeals">
    <div class="heading">
      <div>
        <h2>{{ t('appeals.title') }}</h2>
        <p class="subtitle">{{ t('appeals.subtitle') }}</p>
      </div>
    </div>

    <div v-can="'appeals.add'" class="card create">
      <h3>{{ t('appeals.create.heading') }}</h3>

      <div v-if="!selectedRequest" class="search-block">
        <label>
          {{ t('appeals.create.searchLabel') }}
          <input
            v-model="requestSearch"
            type="text"
            :placeholder="t('appeals.create.searchPlaceholder')"
          />
        </label>
        <p v-if="requestSearching" class="state">{{ t('common.loading') }}</p>
        <ul v-else-if="requestSearch.trim() && requestResults.length === 0" class="state">
          <li>{{ t('appeals.create.noResults') }}</li>
        </ul>
        <ul v-else-if="requestResults.length" class="results">
          <li v-for="result in requestResults" :key="result.id">
            <button type="button" class="ghost" @click="pickRequest(result)">
              <span class="ref ltr">{{ result.reference_number }}</span>
              <span>{{ result.title }}</span>
            </button>
          </li>
        </ul>
      </div>

      <div v-else class="selected">
        <div>
          <span class="ref ltr">{{ selectedRequest.reference_number }}</span>
          <span>{{ selectedRequest.title }}</span>
        </div>
        <button type="button" class="ghost" @click="clearSelection">{{ t('appeals.create.change') }}</button>
      </div>

      <div class="fields">
        <label>
          {{ t('appeals.create.decisionReference') }}
          <input
            v-model="decisionReference"
            type="text"
            :placeholder="t('appeals.create.decisionReferencePlaceholder')"
          />
        </label>
        <label>
          {{ t('appeals.create.decisionDate') }}
          <input v-model="decisionDate" type="date" />
        </label>
      </div>

      <p v-if="createMessage" class="notice success">{{ createMessage }}</p>
      <p v-if="createError" class="alert">{{ createError }}</p>

      <button class="primary" type="button" :disabled="creating" @click="createAppeal">
        {{ creating ? t('appeals.create.submitting') : t('appeals.create.submit') }}
      </button>
    </div>

    <p v-if="loadError" class="alert">
      {{ t('nav.error') }}
      <button class="ghost" type="button" @click="load(page.current_page)">{{ t('common.retry') }}</button>
    </p>

    <div class="card list">
      <p v-if="loading" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="!loadError && rows.length === 0" class="state">{{ t('appeals.empty') }}</p>
      <div v-else-if="!loadError" class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{{ t('appeals.columns.request') }}</th>
              <th>{{ t('appeals.columns.decision') }}</th>
              <th>{{ t('appeals.columns.status') }}</th>
              <th>{{ t('appeals.columns.filedAt') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id">
              <td>
                <span class="ref ltr">{{ row.original_request?.reference_number }}</span>
                <small class="muted">{{ row.original_request?.title }}</small>
              </td>
              <td>{{ row.original_decision_reference || t('common.none') }}</td>
              <td>
                <span v-if="row.status" class="pill" :style="{ borderColor: row.status.color }">
                  {{ statusLabel(row.status) }}
                </span>
                <span v-else>{{ t('common.none') }}</span>
              </td>
              <td class="nowrap">{{ dateTime(row.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <nav v-if="!loading && !loadError && page.last_page > 1" class="pagination" :aria-label="t('appeals.title')">
      <button class="ghost" :disabled="page.current_page <= 1" @click="load(page.current_page - 1)">
        {{ t('requests.previous') }}
      </button>
      <span>{{ t('requests.page', { current: page.current_page, last: page.last_page }) }}</span>
      <button class="ghost" :disabled="page.current_page >= page.last_page" @click="load(page.current_page + 1)">
        {{ t('requests.next') }}
      </button>
    </nav>
  </section>
</template>

<style scoped>
.heading { display: flex; align-items: end; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; }
h2 { margin: 0; color: var(--color-brand-text); font-size: 1.2rem; }
h3 { margin: 0 0 .75rem; color: var(--color-black-700); font-size: 1rem; }
.subtitle { margin: .15rem 0 0; color: var(--color-muted); font-size: .82rem; }

.card { border: 1px solid var(--color-border); border-radius: var(--radius-lg); background: var(--color-surface); padding: 1.25rem; margin-bottom: 1rem; }
.create label { display: flex; flex-direction: column; gap: .3rem; font-size: .82rem; color: var(--color-black-700); margin-bottom: .75rem; }
.create input { padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); color: var(--color-foreground); font-size: .85rem; }
.fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: .75rem 1rem; }

.results, .search-block ul.state { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .35rem; max-height: 12rem; overflow-y: auto; }
.results button { width: 100%; display: flex; gap: .5rem; align-items: center; text-align: start; }
.selected { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .6rem .75rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); margin-bottom: .75rem; }
.selected > div { display: flex; gap: .6rem; align-items: baseline; }

button { cursor: pointer; border-radius: var(--radius-lg); font-size: .85rem; }
.primary { padding: .5rem .9rem; border: 0; color: var(--color-on-brand); background: var(--color-brand); }
.ghost { padding: .35rem .6rem; border: 1px solid var(--color-border-hover); background: var(--color-surface); color: var(--color-black-700); }
.ghost:hover:not(:disabled) { background: var(--color-surface-hover); }
button:disabled { cursor: not-allowed; opacity: .55; }

.alert { padding: .65rem .8rem; margin: 0 0 1rem; border: 1px solid var(--color-danger-border); border-radius: var(--radius-lg); color: var(--color-danger-fg); background: var(--color-danger-bg); }
.alert .ghost { margin-inline-start: .5rem; }
.notice { padding: .65rem .8rem; margin: 0 0 .75rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); color: var(--color-black-700); background: var(--color-surface); font-size: .85rem; }
.notice.success { border-color: var(--color-success-border); color: var(--color-success-fg); background: var(--color-success-bg); }
.state { padding: .5rem; margin: 0; color: var(--color-muted); }

.table-wrap { overflow-x: auto; }
table { width: 100%; min-width: 700px; border-collapse: collapse; }
th, td { padding: .7rem .55rem; text-align: start; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
th { color: var(--color-muted); font-size: .75rem; font-weight: 600; white-space: nowrap; }
td { font-size: .84rem; }
.nowrap { white-space: nowrap; }
.ref { font-family: var(--font-mono); font-size: .78rem; }
.muted { display: block; color: var(--color-muted); font-size: .72rem; }
.pill { display: inline-block; padding: .12rem .5rem; border: 1px solid var(--color-border-hover); border-radius: 999px; font-size: .72rem; white-space: nowrap; }
.pagination { display: flex; align-items: center; justify-content: center; gap: .75rem; color: var(--color-muted); font-size: .84rem; }
</style>
