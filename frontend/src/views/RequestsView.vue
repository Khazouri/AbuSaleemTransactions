<script setup>
/**
 * Searchable request work queue — Stage 11.
 *
 * Restyled/restructured pass: 11 columns collapsed to 6 (the row itself links
 * to the detail page, so a separate "details" column is redundant), the
 * responsibility pair reads as two stacked lines instead of two columns, and
 * the six filter fields default to a single search box with the rest behind
 * a disclosure. Every request/response shape below is unchanged.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import FileUpload from '../components/FileUpload.vue'
import RequestStageRail from '../components/RequestStageRail.vue'
import api from '../lib/api'

const { t, locale } = useI18n()
const requests = ref([])
const options = ref({ statuses: [], departments: [], types: [] })
const loading = ref(false)
const loadingOptions = ref(false)
const loadError = ref(null)
const page = ref({ current_page: 1, last_page: 1, total: 0 })
const filters = ref(blankFilters())
const uploadRequest = ref(null)
const moreFiltersOpen = ref(false)

function blankFilters() {
  // Stage 89 — `search` has been on this endpoint since Stage 20 with no UI
  // exposing it; the employee tracking screen is the first that does, and
  // leaving the internal queue without one would have closed that gap by half.
  return { search: '', status: '', department_id: '', type_id: '', date_from: '', date_to: '' }
}

// Restyled pass — which of the "more filters" fields are actually narrowing
// the result set right now, shown as removable chips above the table.
const activeChips = computed(() => {
  const chips = []
  if (filters.value.status) {
    const status = options.value.statuses.find((entry) => entry.code === filters.value.status)
    chips.push({ key: 'status', label: status ? name(status) : filters.value.status })
  }
  if (filters.value.department_id) {
    const department = options.value.departments.find((entry) => String(entry.id) === String(filters.value.department_id))
    chips.push({ key: 'department_id', label: department ? name(department) : filters.value.department_id })
  }
  if (filters.value.type_id) {
    const type = options.value.types.find((entry) => String(entry.id) === String(filters.value.type_id))
    chips.push({ key: 'type_id', label: type ? name(type) : filters.value.type_id })
  }
  if (filters.value.date_from) chips.push({ key: 'date_from', label: `${t('requests.dateFrom')}: ${filters.value.date_from}` })
  if (filters.value.date_to) chips.push({ key: 'date_to', label: `${t('requests.dateTo')}: ${filters.value.date_to}` })
  return chips
})

function removeChip(key) {
  filters.value[key] = ''
  load(1)
}

const isBusy = computed(() => loading.value || loadingOptions.value)
const name = (item) => {
  if (!item) return t('common.none')
  return locale.value === 'ar'
    ? item.name_ar || item.name_en
    : item.name_en || item.name_ar
}

function date(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', {
    year: 'numeric', month: 'short', day: 'numeric',
  }).format(new Date(value))
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
  try {
    const { data } = await api.get('/requests', { params: queryFor(requestedPage) })
    requests.value = data.data ?? []
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
    const { data } = await api.get('/requests/filters')
    options.value = data.data ?? options.value
  } catch (error) {
    // The list remains useful if labels cannot be loaded; its own request
    // reports failures separately, while empty selects make the issue obvious.
    loadError.value = error
  } finally {
    loadingOptions.value = false
  }
}

function applyFilters() { load(1) }
function clearFilters() { filters.value = blankFilters(); load(1) }
function openUpload(request) { uploadRequest.value = request }
function closeUpload() { uploadRequest.value = null }

onMounted(async () => {
  await Promise.all([load(), loadOptions()])
})
</script>

<template>
  <section class="page requests">
    <div class="heading">
      <div>
        <h2>{{ t('requests.title') }}</h2>
        <p v-if="!loading && !loadError" class="subtitle">{{ page.total }}</p>
      </div>
      <RouterLink v-can="'request_intake.add'" class="primary new-intake" :to="{ name: 'request_intake' }">{{ t('intake.open') }}</RouterLink>
    </div>

    <form class="card card-flat card-pad filters" @submit.prevent="applyFilters">
      <div class="search-row">
        <label class="search-field">
          <span class="sr-only">{{ t('requests.search') }}</span>
          <input v-model="filters.search" type="search" :placeholder="t('requests.searchPlaceholder')" />
        </label>
        <button class="primary" type="submit" :disabled="isBusy">{{ t('requests.applyFilters') }}</button>
        <button class="ghost" type="button" :disabled="isBusy" @click="moreFiltersOpen = !moreFiltersOpen">
          {{ moreFiltersOpen ? t('requests.filters.less') : t('requests.filters.more') }}
        </button>
      </div>

      <ul v-if="activeChips.length" class="chip-row">
        <li v-for="chip in activeChips" :key="chip.key">
          <button type="button" class="chip active" @click="removeChip(chip.key)">
            {{ chip.label }} ×
          </button>
        </li>
      </ul>

      <div v-show="moreFiltersOpen" class="filter-grid">
        <label>
          {{ t('requests.status') }}
          <select v-model="filters.status" :disabled="loadingOptions">
            <option value="">{{ t('requests.allStatuses') }}</option>
            <option v-for="status in options.statuses" :key="status.code" :value="status.code">{{ name(status) }}</option>
          </select>
        </label>
        <label>
          {{ t('requests.department') }}
          <select v-model="filters.department_id" :disabled="loadingOptions">
            <option value="">{{ t('requests.allDepartments') }}</option>
            <option v-for="department in options.departments" :key="department.id" :value="department.id">{{ name(department) }}</option>
          </select>
        </label>
        <label>
          {{ t('requests.type') }}
          <select v-model="filters.type_id" :disabled="loadingOptions">
            <option value="">{{ t('requests.allTypes') }}</option>
            <option v-for="type in options.types" :key="type.id" :value="type.id">{{ name(type) }}</option>
          </select>
        </label>
        <label>
          {{ t('requests.dateFrom') }}
          <input v-model="filters.date_from" type="date" />
        </label>
        <label>
          {{ t('requests.dateTo') }}
          <input v-model="filters.date_to" type="date" />
        </label>
        <div class="clear-action">
          <button class="ghost" type="button" :disabled="isBusy" @click="clearFilters">{{ t('requests.clearFilters') }}</button>
        </div>
      </div>
    </form>

    <p v-if="loadError" class="alert">
      {{ t('nav.error') }}
      <button class="ghost" type="button" @click="load(page.current_page)">{{ t('common.retry') }}</button>
    </p>

    <div class="card card-flat list">
      <p v-if="loading" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="!loadError && requests.length === 0" class="state">{{ t('requests.empty') }}</p>
      <div v-else-if="!loadError" class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>{{ t('requests.reference') }}</th>
              <th>{{ t('requests.status') }}</th>
              <th>{{ t('requests.stage') }}</th>
              <!-- Stage 83 — [D] Appendices 17/18: the appendix says files get
                   lost between departments, and the list is where that shows. -->
              <th>{{ t('lifecycle.responsibility.label') }}</th>
              <th>{{ t('requests.createdAt') }}</th>
              <th>{{ t('attachments.title') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="request in requests" :key="request.id" class="request-row">
              <td>
                <!-- Stage 70 — before the قيد the receipt is what identifies
                     the row; only a request that has neither shows the placeholder.
                     A real RouterLink, not a row-level click handler, so the row
                     stays reachable by keyboard and to a screen reader. -->
                <RouterLink class="row-link" :to="{ name: 'request_details', params: { id: request.id } }">
                  <span class="reference ltr">{{ request.reference_number || request.intake_receipt_number || t('requests.noReference') }}</span>
                  <span class="title">{{ request.title }}</span>
                </RouterLink>
              </td>
              <td>
                <span v-if="request.status" class="status" :style="{ '--status-color': request.status.color || 'var(--color-muted)' }">
                  {{ name(request.status) }}
                </span>
                <span v-else>{{ t('common.none') }}</span>
              </td>
              <td>
                <span class="stage-name">{{ name(request.current_stage) }}</span>
                <RequestStageRail
                  v-if="request.stage_progress"
                  variant="compact"
                  :stage-progress="request.stage_progress"
                  :stage-timeliness="request.stage_timeliness"
                />
              </td>
              <td>
                <span v-if="request.responsibility" class="responsibility">
                  <strong>{{ t(`lifecycle.responsibility.parties.${request.responsibility.responsible.code}`) }}</strong>
                  <small>{{ t(`lifecycle.responsibility.actions.${request.responsibility.next_action.code}`) }}</small>
                </span>
              </td>
              <td>{{ date(request.created_at) }}</td>
              <td>
                <button v-if="request.can_attach" v-can="'notes_attachments.add'" class="ghost upload-action" type="button" @click="openUpload(request)">
                  {{ t('attachments.upload') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <nav v-if="!loading && !loadError && page.last_page > 1" class="pagination" :aria-label="t('requests.title')">
      <button class="ghost" :disabled="page.current_page <= 1" @click="load(page.current_page - 1)">{{ t('requests.previous') }}</button>
      <span>{{ t('requests.page', { current: page.current_page, last: page.last_page }) }}</span>
      <button class="ghost" :disabled="page.current_page >= page.last_page" @click="load(page.current_page + 1)">{{ t('requests.next') }}</button>
    </nav>

    <div v-if="uploadRequest" class="modal-backdrop" role="presentation" @click.self="closeUpload">
      <section class="modal upload-modal" role="dialog" aria-modal="true" :aria-label="t('attachments.title')">
        <div class="modal-heading">
          <div>
            <h3>{{ t('attachments.title') }}</h3>
            <p class="reference ltr">{{ uploadRequest.reference_number || `#${uploadRequest.id}` }}</p>
          </div>
          <button class="ghost" type="button" :aria-label="t('common.cancel')" @click="closeUpload">×</button>
        </div>
        <FileUpload :request-id="uploadRequest.id" @uploaded="closeUpload" />
      </section>
    </div>
  </section>
</template>

<style scoped>
.stage-progress { display: block; color: var(--color-muted); font-size: var(--text-xs); }

.filters { margin-bottom: var(--space-4); }
.search-row { display: flex; gap: var(--space-2); flex-wrap: wrap; }
.search-field { flex: 1; min-inline-size: min(12rem, 100%); }
.search-field input { width: 100%; min-inline-size: 0; padding: 0.5rem 0.6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); }
.sr-only { position: absolute; inline-size: 1px; block-size: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }

.chip-row { display: flex; flex-wrap: wrap; gap: var(--space-2); padding: 0; margin: var(--space-3) 0 0; list-style: none; }
.chip-row button { border: 0; }

.filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: var(--space-4); margin-top: var(--space-4); padding-top: var(--space-4); border-top: 1px solid var(--color-border); }
.filter-grid label { display: flex; flex-direction: column; gap: 0.3rem; color: var(--color-black-700); font-size: var(--text-sm); }
.filter-grid select, .filter-grid input { min-width: 0; padding: 0.5rem 0.6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); }
.filter-grid select:focus, .filter-grid input:focus { outline: 2px solid var(--color-brand-text); outline-offset: 1px; }
.clear-action { display: flex; align-items: flex-end; }

.request-row:hover { background: var(--color-surface-hover); }
.row-link { display: block; min-width: 12rem; text-decoration: none; }
.row-link:focus-visible { outline: 2px solid var(--color-brand-text); outline-offset: 2px; }
.reference { display: block; font-family: var(--font-mono); font-size: var(--text-xs); white-space: nowrap; }
.title { display: block; margin-top: 0.1rem; font-weight: 600; color: var(--color-black-700); }
.title:hover { text-decoration: underline; }
.stage-name { display: block; }
.responsibility { display: grid; gap: 0.1rem; }
.responsibility strong { color: var(--color-black-700); font-size: var(--text-sm); }
.responsibility small { color: var(--color-muted); font-size: var(--text-xs); }
.upload-action { white-space: nowrap; }
.new-intake { text-decoration: none; white-space: nowrap; }

.upload-modal { inline-size: min(100%, 31rem); }
.modal-heading { display: flex; align-items: start; justify-content: space-between; gap: var(--space-4); margin-bottom: var(--space-4); }
.modal-heading h3 { margin: 0; color: var(--color-brand-text); font-size: var(--text-lg); }
.modal-heading .reference { margin: 0.15rem 0 0; color: var(--color-muted); }

@media (max-width: 720px) {
  .filter-grid { grid-template-columns: 1fr; }
}
</style>
