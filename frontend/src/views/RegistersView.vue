<script setup>
/**
 * Official registers (السجلات الرسمية) — Stage 80.
 *
 * [D] Art. 98's twelve registers, behind one tab strip. Every register
 * declares its own columns server-side, so this screen renders all twelve with
 * one generic table rather than twelve hand-written ones — a thirteenth
 * register is a new class in the backend catalogue and no change here at all.
 *
 * The export buttons carry v-can="'registers.export'": reading a register and
 * carrying it out of the system as a file are separate grants, exactly as on
 * the reports screen.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import { downloadExport } from '../lib/download'

const { t, locale } = useI18n()

const registers = ref([])
const activeCode = ref(null)
const columns = ref([])
const rows = ref([])
const page = ref({ current_page: 1, last_page: 1, total: 0 })
const filters = ref(blankFilters())
const loadingCatalog = ref(false)
const loading = ref(false)
const loadError = ref(null)
/** Which format is mid-download, so only that button shows a busy state. */
const exporting = ref(null)
const exportError = ref(null)

function blankFilters() {
  return { date_from: '', date_to: '', search: '' }
}

const active = computed(() => registers.value.find((r) => r.code === activeCode.value) ?? null)
const isBusy = computed(() => loading.value || loadingCatalog.value || exporting.value !== null)

function registerName(register) {
  if (!register) return ''
  return locale.value === 'ar'
    ? register.name_ar || register.name_en
    : register.name_en || register.name_ar
}

/** Only the filters that are actually set — an empty string is not a filter. */
function activeFilters() {
  return Object.fromEntries(
    Object.entries(filters.value).filter(([, value]) => value !== '' && value !== null),
  )
}

async function loadCatalog() {
  loadingCatalog.value = true
  loadError.value = null
  try {
    const { data } = await api.get('/registers')
    registers.value = data.data ?? []
    if (!activeCode.value && registers.value.length) {
      activeCode.value = registers.value[0].code
    }
  } catch (error) {
    loadError.value = error
  } finally {
    loadingCatalog.value = false
  }
}

async function load(requestedPage = 1) {
  if (!activeCode.value) return
  loading.value = true
  loadError.value = null
  try {
    const { data } = await api.get(`/registers/${activeCode.value}`, {
      params: { page: requestedPage, locale: locale.value, ...activeFilters() },
    })
    rows.value = data.data ?? []
    columns.value = data.columns ?? []
    page.value = data.meta ?? page.value
  } catch (error) {
    loadError.value = error
  } finally {
    loading.value = false
  }
}

/**
 * The file covers the whole filtered register rather than the visible page —
 * an export that stopped at page one would quietly mislead — and is generated
 * in the language on screen.
 */
async function exportAs(format) {
  exporting.value = format
  exportError.value = null
  try {
    await downloadExport(
      `/registers/${activeCode.value}/export`,
      { ...activeFilters(), format, locale: locale.value },
      `register-${activeCode.value}.${format}`,
    )
  } catch (error) {
    exportError.value = error?.response?.data?.message ?? t('registers.exportFailed')
  } finally {
    exporting.value = null
  }
}

function selectRegister(code) {
  if (code === activeCode.value) return
  activeCode.value = code
  filters.value = blankFilters()
}

function applyFilters() { load(1) }
function clearFilters() { filters.value = blankFilters(); load(1) }

// Both the chosen register and the locale change what the server renders, so
// each re-fetches rather than being reformatted client-side: the row values
// (folder names, outcome labels, نعم/لا) are produced server-side by design.
watch([activeCode, locale], () => load(1))

onMounted(async () => {
  await loadCatalog()
  await load(1)
})
</script>

<template>
  <section class="page registers">
    <div class="heading">
      <div>
        <h2>{{ t('registers.title') }}</h2>
        <p class="subtitle">{{ t('registers.subtitle') }}</p>
      </div>
      <div v-can="'registers.export'" class="export-actions">
        <button class="primary" type="button" :disabled="isBusy || !activeCode" @click="exportAs('xlsx')">
          {{ exporting === 'xlsx' ? t('registers.exporting') : t('registers.exportExcel') }}
        </button>
        <button class="ghost" type="button" :disabled="isBusy || !activeCode" @click="exportAs('pdf')">
          {{ exporting === 'pdf' ? t('registers.exporting') : t('registers.exportPdf') }}
        </button>
      </div>
    </div>

    <div class="tabs" role="tablist" :aria-label="t('registers.title')">
      <button
        v-for="register in registers"
        :key="register.code"
        type="button"
        class="tab"
        role="tab"
        :aria-selected="register.code === activeCode ? 'true' : 'false'"
        :disabled="loading"
        @click="selectRegister(register.code)"
      >
        <span class="tab-number">{{ register.number }}</span>
        {{ registerName(register) }}
      </button>
    </div>

    <form class="card card-flat card-pad filters" @submit.prevent="applyFilters">
      <div class="filter-grid">
        <label>
          {{ t('registers.dateFrom') }}
          <input v-model="filters.date_from" type="date" />
        </label>
        <label>
          {{ t('registers.dateTo') }}
          <input v-model="filters.date_to" type="date" />
        </label>
        <label v-if="active?.searchable">
          {{ t('registers.search') }}
          <input v-model="filters.search" type="search" :placeholder="t('registers.searchPlaceholder')" />
        </label>
      </div>
      <div class="actions">
        <button class="primary" type="submit" :disabled="isBusy">{{ t('registers.apply') }}</button>
        <button class="ghost" type="button" :disabled="isBusy" @click="clearFilters">{{ t('registers.clear') }}</button>
      </div>
    </form>

    <p v-if="loadError" class="alert">
      {{ t('nav.error') }}
      <button class="ghost" type="button" @click="load(page.current_page)">{{ t('common.retry') }}</button>
    </p>
    <p v-if="exportError" class="alert">{{ exportError }}</p>

    <div class="card card-flat card-pad list">
      <p v-if="loading" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="!loadError && rows.length === 0" class="state">{{ t('registers.empty') }}</p>
      <div v-else-if="!loadError" class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th v-for="column in columns" :key="column.key">{{ column.label }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, index) in rows" :key="index">
              <td v-for="column in columns" :key="column.key">
                {{ row[column.key] ?? t('common.none') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="!loading && !loadError && rows.length" class="count">
        {{ t('registers.total', { total: page.total }) }}
      </p>
    </div>

    <nav v-if="!loading && !loadError && page.last_page > 1" class="pagination" :aria-label="t('registers.title')">
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
.export-actions { display: flex; gap: var(--space-2); }

.tab { display: inline-flex; align-items: center; gap: .4rem; }
.tab-number { display: inline-flex; align-items: center; justify-content: center; min-width: 1.25rem; height: 1.25rem; border-radius: var(--radius-full); background: var(--color-surface-hover); color: var(--color-muted); font-size: .68rem; }
.tab[aria-selected='true'] .tab-number { background: var(--color-brand); color: var(--color-on-brand); }

.filters { margin-bottom: var(--space-4); }
.filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: var(--space-4); }
label { display: flex; flex-direction: column; gap: .3rem; color: var(--color-black-700); font-size: var(--text-sm); }
input { min-width: 0; padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); }
input:focus { outline: 2px solid var(--color-brand-text); outline-offset: 1px; }
.actions { margin-top: var(--space-4); }
.count { margin: var(--space-3) 0 0; color: var(--color-muted); font-size: var(--text-sm); font-variant-numeric: tabular-nums; }

.data-table { min-width: 900px; }
.data-table td { max-width: 320px; vertical-align: top; }
</style>
