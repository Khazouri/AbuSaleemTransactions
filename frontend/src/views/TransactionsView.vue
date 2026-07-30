<script setup>
/** Searchable transaction work queue — Stage 11. */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import FileUpload from '../components/FileUpload.vue'
import api from '../lib/api'

const { t, locale } = useI18n()
const transactions = ref([])
const options = ref({ statuses: [], departments: [], types: [] })
const loading = ref(false)
const loadingOptions = ref(false)
const loadError = ref(null)
const page = ref({ current_page: 1, last_page: 1, total: 0 })
const filters = ref(blankFilters())
const uploadTransaction = ref(null)

function blankFilters() {
  return { status: '', department_id: '', type_id: '', date_from: '', date_to: '' }
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
    const { data } = await api.get('/transactions', { params: queryFor(requestedPage) })
    transactions.value = data.data ?? []
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
    const { data } = await api.get('/transactions/filters')
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
function openUpload(transaction) { uploadTransaction.value = transaction }
function closeUpload() { uploadTransaction.value = null }

onMounted(async () => {
  await Promise.all([load(), loadOptions()])
})
</script>

<template>
  <section class="transactions">
    <div class="heading">
      <div>
        <h2>{{ t('transactions.title') }}</h2>
        <p v-if="!loading && !loadError" class="count">{{ page.total }}</p>
      </div>
    </div>

    <form class="card filters" @submit.prevent="applyFilters">
      <h3>{{ t('transactions.filters') }}</h3>
      <div class="filter-grid">
        <label>
          {{ t('transactions.status') }}
          <select v-model="filters.status" :disabled="loadingOptions">
            <option value="">{{ t('transactions.allStatuses') }}</option>
            <option v-for="status in options.statuses" :key="status.code" :value="status.code">{{ name(status) }}</option>
          </select>
        </label>
        <label>
          {{ t('transactions.department') }}
          <select v-model="filters.department_id" :disabled="loadingOptions">
            <option value="">{{ t('transactions.allDepartments') }}</option>
            <option v-for="department in options.departments" :key="department.id" :value="department.id">{{ name(department) }}</option>
          </select>
        </label>
        <label>
          {{ t('transactions.type') }}
          <select v-model="filters.type_id" :disabled="loadingOptions">
            <option value="">{{ t('transactions.allTypes') }}</option>
            <option v-for="type in options.types" :key="type.id" :value="type.id">{{ name(type) }}</option>
          </select>
        </label>
        <label>
          {{ t('transactions.dateFrom') }}
          <input v-model="filters.date_from" type="date" />
        </label>
        <label>
          {{ t('transactions.dateTo') }}
          <input v-model="filters.date_to" type="date" />
        </label>
      </div>
      <div class="actions">
        <button class="primary" type="submit" :disabled="isBusy">{{ t('transactions.applyFilters') }}</button>
        <button class="ghost" type="button" :disabled="isBusy" @click="clearFilters">{{ t('transactions.clearFilters') }}</button>
      </div>
    </form>

    <p v-if="loadError" class="alert">
      {{ t('nav.error') }}
      <button class="ghost" type="button" @click="load(page.current_page)">{{ t('common.retry') }}</button>
    </p>

    <div class="card list">
      <p v-if="loading" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="!loadError && transactions.length === 0" class="state">{{ t('transactions.empty') }}</p>
      <div v-else-if="!loadError" class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{{ t('transactions.reference') }}</th>
              <th>{{ t('transactions.subject') }}</th>
              <th>{{ t('transactions.department') }}</th>
              <th>{{ t('transactions.type') }}</th>
              <th>{{ t('transactions.status') }}</th>
              <th>{{ t('transactions.stage') }}</th>
              <th>{{ t('transactions.createdAt') }}</th>
              <th>{{ t('attachments.title') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="transaction in transactions" :key="transaction.id">
              <td><span class="reference ltr">{{ transaction.reference_number || t('transactions.noReference') }}</span></td>
              <td class="title">{{ transaction.title }}</td>
              <td>{{ name(transaction.department) }}</td>
              <td>{{ name(transaction.transaction_type) }}</td>
              <td>
                <span v-if="transaction.status" class="status" :style="{ '--status-color': transaction.status.color || 'var(--color-muted)' }">
                  {{ name(transaction.status) }}
                </span>
                <span v-else>{{ t('common.none') }}</span>
              </td>
              <td>{{ name(transaction.current_stage) }}</td>
              <td>{{ date(transaction.created_at) }}</td>
              <td>
                <button v-can="'notes_attachments.add'" class="ghost upload-action" type="button" @click="openUpload(transaction)">
                  {{ t('attachments.upload') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <nav v-if="!loading && !loadError && page.last_page > 1" class="pagination" :aria-label="t('transactions.title')">
      <button class="ghost" :disabled="page.current_page <= 1" @click="load(page.current_page - 1)">{{ t('transactions.previous') }}</button>
      <span>{{ t('transactions.page', { current: page.current_page, last: page.last_page }) }}</span>
      <button class="ghost" :disabled="page.current_page >= page.last_page" @click="load(page.current_page + 1)">{{ t('transactions.next') }}</button>
    </nav>

    <div v-if="uploadTransaction" class="modal-backdrop" role="presentation" @click.self="closeUpload">
      <section class="card upload-modal" role="dialog" aria-modal="true" :aria-label="t('attachments.title')">
        <div class="modal-heading">
          <div>
            <h3>{{ t('attachments.title') }}</h3>
            <p class="reference ltr">{{ uploadTransaction.reference_number || `#${uploadTransaction.id}` }}</p>
          </div>
          <button class="ghost" type="button" :aria-label="t('common.cancel')" @click="closeUpload">×</button>
        </div>
        <FileUpload :transaction-id="uploadTransaction.id" @uploaded="closeUpload" />
      </section>
    </div>
  </section>
</template>

<style scoped>
.heading { display: flex; align-items: end; justify-content: space-between; margin-bottom: 1rem; }
h2 { margin: 0; color: var(--color-nav); font-size: 1.2rem; }.count { margin: .15rem 0 0; color: var(--color-muted); font-size: .82rem; }
.filters, .list { padding: 1.25rem; margin-bottom: 1rem; }.filters h3 { margin: 0 0 1rem; font-size: 1rem; }
.filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 1rem; }
label { display: flex; flex-direction: column; gap: .3rem; color: var(--color-black-700); font-size: .85rem; }
select, input { min-width: 0; padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); }
select:focus, input:focus { outline: 2px solid var(--color-nav); outline-offset: 1px; }.actions { display: flex; gap: .5rem; margin-top: 1rem; }
button { cursor: pointer; border-radius: var(--radius-lg); font-size: .85rem; }.primary { padding: .5rem .9rem; border: 0; color: #fff; background: var(--color-nav); }.ghost { padding: .4rem .65rem; border: 1px solid var(--color-border-hover); background: var(--color-surface); color: var(--color-black-700); }.ghost:hover:not(:disabled) { background: var(--color-surface-hover); }button:disabled { cursor: not-allowed; opacity: .55; }
.alert { padding: .65rem .8rem; margin: 0 0 1rem; border: 1px solid #fecaca; border-radius: var(--radius-lg); color: #b91c1c; background: #fef2f2; }.alert .ghost { margin-inline-start: .5rem; }
.state { padding: .5rem; margin: 0; color: var(--color-muted); }.table-wrap { overflow-x: auto; }table { width: 100%; min-width: 780px; border-collapse: collapse; }th, td { padding: .7rem .55rem; text-align: start; border-bottom: 1px solid var(--color-border); vertical-align: middle; }th { color: var(--color-muted); font-size: .75rem; font-weight: 600; white-space: nowrap; }tr:last-child td { border-bottom: 0; }td { font-size: .84rem; }.title { min-width: 12rem; font-weight: 600; }.reference { display: inline-block; font-family: var(--font-mono); font-size: .75rem; white-space: nowrap; }.status { display: inline-flex; align-items: center; gap: .35rem; white-space: nowrap; }.status::before { content: ''; width: .5rem; height: .5rem; border-radius: 50%; background: var(--status-color); }
.pagination { display: flex; align-items: center; justify-content: center; gap: .75rem; color: var(--color-muted); font-size: .84rem; }.upload-action { white-space: nowrap; }.modal-backdrop { position: fixed; inset: 0; z-index: 20; display: grid; place-items: center; padding: 1rem; background: rgba(15, 23, 42, .42); }.upload-modal { inline-size: min(100%, 31rem); padding: 1.25rem; }.modal-heading { display: flex; align-items: start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }.modal-heading h3 { margin: 0; color: var(--color-nav); font-size: 1rem; }.modal-heading .reference { margin: .15rem 0 0; color: var(--color-muted); }
</style>
