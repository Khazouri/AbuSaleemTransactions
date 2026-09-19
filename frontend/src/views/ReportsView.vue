<script setup>
/**
 * Reports & statistics (التقارير والإحصائيات) — Stage 24.
 *
 * A filtered request listing with the KPI summary that describes exactly
 * the rows below it, plus .xlsx / PDF export of the same filtered set. The
 * export buttons carry v-can="'reports.export'": viewing the numbers and
 * carrying them out of the system as a file are separate grants.
 *
 * Stage 81 added two more tabs over the SAME filter bar — [D] Art. 106's
 * twelve indicators and the three periodic reports (Art. 107, Appendices 39
 * and 40). One filter bar because all three describe one population: a
 * screen where the indicators and the listing narrowed differently under
 * one date range would be showing two things and calling them one.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import { downloadExport } from '../lib/download'
import { formatIndicatorValue } from '../lib/performance'
import { stageProgressLabel } from '../lib/stageProgress'

const { t, locale } = useI18n()

const rows = ref([])
const summary = ref(null)
const options = ref({ statuses: [], departments: [], types: [], formats: [] })
const page = ref({ current_page: 1, last_page: 1, total: 0 })
const filters = ref(blankFilters())
const loading = ref(false)
const loadingOptions = ref(false)
const loadError = ref(null)
/** Which format is mid-download, so only that button shows a busy state. */
const exporting = ref(null)
const exportError = ref(null)

// Stage 81 — the three tabs, and the state behind the two new ones.
const tab = ref('requests')
const indicators = ref([])
const warningSummary = ref([])
const periodicKey = ref('periodic')
const periodicReport = ref(null)
const loadingPerformance = ref(false)

function blankFilters() {
  return { department_id: '', type_id: '', status: '', date_from: '', date_to: '' }
}

const isBusy = computed(() => loading.value || loadingOptions.value || loadingPerformance.value || exporting.value !== null)

const PERIODIC_REPORTS = ['periodic', 'monthly', 'annual']

/** Only the warnings actually firing — a tally of ten zeroes is noise. */
const firingWarnings = computed(() => warningSummary.value.filter((row) => row.total > 0))

function localName(row) {
  if (!row) return t('common.none')
  return locale.value === 'ar'
    ? row.name_ar || row.name_en
    : row.name_en || row.name_ar
}

function number(value) {
  if (value === null || value === undefined) return t('common.none')
  return new Intl.NumberFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB').format(value)
}

function date(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', {
    year: 'numeric', month: 'short', day: 'numeric',
  }).format(new Date(value))
}

/** Only the filters that are actually set — an empty string is not a filter. */
function activeFilters() {
  return Object.fromEntries(
    Object.entries(filters.value).filter(([, value]) => value !== '' && value !== null),
  )
}

const summaryTiles = computed(() => {
  if (!summary.value) return []
  const s = summary.value

  return [
    { key: 'total', value: number(s.total) },
    { key: 'pending', value: number(s.pending) },
    { key: 'completed', value: number(s.completed) },
    { key: 'completionRate', value: `${s.completion_rate}%` },
    { key: 'overdue', value: number(s.overdue) },
    {
      key: 'cycleTime',
      value: s.average_cycle_days === null ? t('common.none') : number(s.average_cycle_days),
    },
  ]
})

async function load(requestedPage = 1) {
  loading.value = true
  loadError.value = null
  try {
    const { data } = await api.get('/reports/requests', {
      params: { page: requestedPage, ...activeFilters() },
    })
    rows.value = data.data ?? []
    summary.value = data.summary ?? null
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
    const { data } = await api.get('/reports/filters')
    options.value = data.data ?? options.value
  } catch (error) {
    loadError.value = error
  } finally {
    loadingOptions.value = false
  }
}

/**
 * The file is generated for the language on screen, and covers the whole
 * filtered set rather than the visible page — an export that stopped at page
 * one would quietly mislead.
 */
async function exportAs(format) {
  exporting.value = format
  exportError.value = null
  try {
    await downloadExport(
      '/reports/requests/export',
      { ...activeFilters(), format, locale: locale.value },
      `requests-report.${format}`,
    )
  } catch (error) {
    exportError.value = error?.response?.data?.message ?? t('reports.exportFailed')
  } finally {
    exporting.value = null
  }
}

/**
 * Art. 106's indicators, plus Appendix 10's tally over the same period.
 *
 * The locale travels with the request because every label and purpose is
 * rendered server-side, so switching language re-fetches rather than
 * re-formatting — see lib/performance.js.
 */
async function loadIndicators() {
  loadingPerformance.value = true
  loadError.value = null
  try {
    const { data } = await api.get('/reports/performance/indicators', {
      params: { ...activeFilters(), locale: locale.value },
    })
    indicators.value = data.data ?? []
    warningSummary.value = data.warnings ?? []
  } catch (error) {
    loadError.value = error
  } finally {
    loadingPerformance.value = false
  }
}

async function loadPeriodic() {
  loadingPerformance.value = true
  loadError.value = null
  try {
    const { data } = await api.get(`/reports/performance/periodic/${periodicKey.value}`, {
      params: { ...activeFilters(), locale: locale.value },
    })
    periodicReport.value = data.data ?? null
  } catch (error) {
    loadError.value = error
  } finally {
    loadingPerformance.value = false
  }
}

/** Whatever tab is open reloads; the filter bar is shared by all three. */
function reloadActiveTab() {
  if (tab.value === 'indicators') return loadIndicators()
  if (tab.value === 'periodic') return loadPeriodic()
  return load(1)
}

function selectTab(next) {
  tab.value = next
  // Lazily: the indicators scan every request's history, so they are not
  // computed for a reader who only wanted the listing.
  if (next === 'indicators' && indicators.value.length === 0) loadIndicators()
  if (next === 'periodic' && periodicReport.value === null) loadPeriodic()
}

function selectPeriodicReport(key) {
  periodicKey.value = key
  loadPeriodic()
}

async function exportPeriodic(format) {
  exporting.value = format
  exportError.value = null
  try {
    await downloadExport(
      `/reports/performance/periodic/${periodicKey.value}/export`,
      { ...activeFilters(), format, locale: locale.value },
      `${periodicKey.value}-report.${format}`,
    )
  } catch (error) {
    exportError.value = error?.response?.data?.message ?? t('reports.exportFailed')
  } finally {
    exporting.value = null
  }
}

function indicatorValue(row) {
  return formatIndicatorValue(row.value, row.unit, locale.value, t('common.none'))
}

function applyFilters() { reloadActiveTab() }
function clearFilters() { filters.value = blankFilters(); reloadActiveTab() }

onMounted(async () => {
  await Promise.all([load(), loadOptions()])
})
</script>

<template>
  <section class="reports">
    <div class="heading">
      <div>
        <h2>{{ t('reports.title') }}</h2>
        <p class="subtitle">{{ t('reports.subtitle') }}</p>
      </div>
      <div v-can="'reports.export'" v-if="tab !== 'indicators'" class="export-actions">
        <button
          class="primary"
          type="button"
          :disabled="isBusy"
          @click="tab === 'periodic' ? exportPeriodic('xlsx') : exportAs('xlsx')"
        >{{ exporting === 'xlsx' ? t('reports.exporting') : t('reports.exportExcel') }}</button>
        <button
          class="ghost"
          type="button"
          :disabled="isBusy"
          @click="tab === 'periodic' ? exportPeriodic('pdf') : exportAs('pdf')"
        >{{ exporting === 'pdf' ? t('reports.exporting') : t('reports.exportPdf') }}</button>
      </div>
    </div>

    <!-- Stage 81 — one filter bar, three views of the same population. -->
    <nav class="tabs" :aria-label="t('reports.title')">
      <button
        v-for="key in ['requests', 'indicators', 'periodic']"
        :key="key"
        type="button"
        class="tab"
        :class="{ active: tab === key }"
        :aria-pressed="tab === key"
        @click="selectTab(key)"
      >{{ t(`reports.tabs.${key}`) }}</button>
    </nav>

    <form class="card filters" @submit.prevent="applyFilters">
      <h3>{{ t('reports.filters') }}</h3>
      <div class="filter-grid">
        <label>
          {{ t('reports.department') }}
          <select v-model="filters.department_id" :disabled="loadingOptions">
            <option value="">{{ t('reports.allDepartments') }}</option>
            <option v-for="dept in options.departments" :key="dept.id" :value="dept.id">
              {{ localName(dept) }}
            </option>
          </select>
        </label>
        <label>
          {{ t('reports.type') }}
          <select v-model="filters.type_id" :disabled="loadingOptions">
            <option value="">{{ t('reports.allTypes') }}</option>
            <option v-for="type in options.types" :key="type.id" :value="type.id">
              {{ localName(type) }}
            </option>
          </select>
        </label>
        <label>
          {{ t('reports.status') }}
          <select v-model="filters.status" :disabled="loadingOptions">
            <option value="">{{ t('reports.allStatuses') }}</option>
            <option v-for="status in options.statuses" :key="status.code" :value="status.code">
              {{ localName(status) }}
            </option>
          </select>
        </label>
        <label>
          {{ t('reports.dateFrom') }}
          <input v-model="filters.date_from" type="date" />
        </label>
        <label>
          {{ t('reports.dateTo') }}
          <input v-model="filters.date_to" type="date" />
        </label>
      </div>
      <div class="actions">
        <button class="primary" type="submit" :disabled="isBusy">{{ t('reports.apply') }}</button>
        <button class="ghost" type="button" :disabled="isBusy" @click="clearFilters">{{ t('reports.clear') }}</button>
      </div>
    </form>

    <p v-if="loadError" class="alert">
      {{ t('nav.error') }}
      <button class="ghost" type="button" @click="load(page.current_page)">{{ t('common.retry') }}</button>
    </p>
    <p v-if="exportError" class="alert">{{ exportError }}</p>

    <div v-if="tab === 'requests' && summaryTiles.length" class="tiles">
      <article v-for="tile in summaryTiles" :key="tile.key" class="tile">
        <span class="tile-label">{{ t(`dashboard.tiles.${tile.key}`) }}</span>
        <strong class="tile-value">{{ tile.value }}</strong>
      </article>
    </div>

    <div v-if="tab === 'requests'" class="card list">
      <p v-if="loading" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="!loadError && rows.length === 0" class="state">{{ t('reports.empty') }}</p>
      <div v-else-if="!loadError" class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{{ t('reports.reference') }}</th>
              <th>{{ t('reports.subject') }}</th>
              <th>{{ t('reports.department') }}</th>
              <th>{{ t('reports.type') }}</th>
              <th>{{ t('reports.status') }}</th>
              <th>{{ t('reports.stage') }}</th>
              <th>{{ t('reports.dueDate') }}</th>
              <th>{{ t('reports.createdAt') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id" :class="{ overdue: row.is_overdue }">
              <td>
                <RouterLink
                  class="reference ltr"
                  :to="{ name: 'request_details', params: { id: row.id } }"
                >{{ row.reference_number ?? `#${row.id}` }}</RouterLink>
              </td>
              <td class="subject">{{ row.title }}</td>
              <td>{{ localName(row.department) }}</td>
              <td>{{ localName(row.request_type) }}</td>
              <td>
                <span
                  v-if="row.status"
                  class="status"
                  :style="{ color: row.status.color, borderColor: row.status.color }"
                >{{ localName(row.status) }}</span>
                <span v-else>{{ t('common.none') }}</span>
              </td>
              <td>
                <template v-if="row.current_stage">
                  {{ localName(row.current_stage) }}
                  <br v-if="row.stage_progress">
                  <small v-if="row.stage_progress">{{ stageProgressLabel(t, row.stage_progress) }}</small>
                </template>
                <span v-else>{{ t('common.none') }}</span>
              </td>
              <td class="nowrap">
                {{ date(row.due_date) }}
                <span v-if="row.is_overdue" class="flag">{{ t('reports.overdueFlag') }}</span>
              </td>
              <td class="nowrap">{{ date(row.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <nav v-if="tab === 'requests' && !loading && !loadError && page.last_page > 1" class="pagination" :aria-label="t('reports.title')">
      <button class="ghost" :disabled="page.current_page <= 1" @click="load(page.current_page - 1)">
        {{ t('requests.previous') }}
      </button>
      <span>{{ t('requests.page', { current: page.current_page, last: page.last_page }) }}</span>
      <button class="ghost" :disabled="page.current_page >= page.last_page" @click="load(page.current_page + 1)">
        {{ t('requests.next') }}
      </button>
    </nav>

    <!-- Stage 81 — [D] Art. 106's twelve indicators, with each one's own
         stated purpose beside it: the article is a two-column table, and an
         indicator shown without its purpose invites being read as something
         it does not measure. -->
    <div v-if="tab === 'indicators'" class="card list">
      <p v-if="loadingPerformance" class="state">{{ t('common.loading') }}</p>
      <template v-else-if="!loadError">
        <p class="source">{{ t('reports.performance.indicatorsSource') }}</p>
        <div class="indicator-grid">
          <article v-for="row in indicators" :key="row.key" class="indicator">
            <span class="indicator-number">{{ row.number }}</span>
            <div>
              <span class="indicator-label">{{ row.label }}</span>
              <span class="indicator-purpose">{{ row.purpose }}</span>
            </div>
            <strong class="indicator-value">{{ indicatorValue(row) }}</strong>
          </article>
        </div>

        <!-- Appendix 10's tally over the same period; only the conditions
             actually firing, since ten zeroes say nothing. -->
        <section class="warnings">
          <h3>{{ t('reports.performance.warningsTitle') }}</h3>
          <p class="source">{{ t('reports.performance.warningsSource') }}</p>
          <p v-if="firingWarnings.length === 0" class="state">{{ t('reports.performance.noWarnings') }}</p>
          <ul v-else class="warning-list">
            <li v-for="row in firingWarnings" :key="row.key">
              <span>{{ row.label }}</span>
              <strong>{{ number(row.total) }}</strong>
            </li>
          </ul>
        </section>
      </template>
    </div>

    <!-- Stage 81 — Art. 107's periodic report, Appendix 39's monthly and
         Appendix 40's annual. All three are counts and averages by design:
         Art. 107 excludes personal data outright. -->
    <div v-if="tab === 'periodic'" class="card list">
      <nav class="tabs inner" :aria-label="t('reports.tabs.periodic')">
        <button
          v-for="key in PERIODIC_REPORTS"
          :key="key"
          type="button"
          class="tab"
          :class="{ active: periodicKey === key }"
          :aria-pressed="periodicKey === key"
          @click="selectPeriodicReport(key)"
        >{{ t(`reports.performance.reports.${key}`) }}</button>
      </nav>

      <p v-if="loadingPerformance" class="state">{{ t('common.loading') }}</p>
      <template v-else-if="!loadError && periodicReport">
        <p class="source">{{ periodicReport.source }} — {{ t('reports.performance.noPersonalData') }}</p>

        <section v-for="section in periodicReport.sections" :key="section.title" class="report-section">
          <h3>{{ section.title }}</h3>
          <ul class="report-items">
            <li v-for="item in section.items" :key="item.key ?? item.label">
              <span>{{ item.label }}</span>
              <strong>{{ formatIndicatorValue(item.value, item.unit, locale, t('common.none')) }}</strong>
            </li>
          </ul>
        </section>

        <!-- Named rather than omitted: a reader must be able to see which
             sections the rapporteur still has to write, instead of assuming
             the report is whole. -->
        <section v-if="periodicReport.narrative.length" class="report-section narrative">
          <h3>{{ t('reports.performance.narrativeTitle') }}</h3>
          <p class="source">{{ t('reports.performance.narrativeNote') }}</p>
          <ul class="report-items">
            <li v-for="heading in periodicReport.narrative" :key="heading">
              <span>{{ heading }}</span>
              <strong>—</strong>
            </li>
          </ul>
        </section>
      </template>
    </div>
  </section>
</template>

<style scoped>
.heading { display: flex; align-items: end; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; }
h2 { margin: 0; color: var(--color-brand-text); font-size: 1.2rem; }
.subtitle { margin: .15rem 0 0; color: var(--color-muted); font-size: .82rem; }
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
.ghost { padding: .45rem .7rem; border: 1px solid var(--color-border-hover); background: var(--color-surface); color: var(--color-black-700); }
.ghost:hover:not(:disabled) { background: var(--color-surface-hover); }
button:disabled { cursor: not-allowed; opacity: .55; }
.alert { padding: .65rem .8rem; margin: 0 0 1rem; border: 1px solid var(--color-danger-border); border-radius: var(--radius-lg); color: var(--color-danger-fg); background: var(--color-danger-bg); }
.alert .ghost { margin-inline-start: .5rem; }
.state { padding: .5rem; margin: 0; color: var(--color-muted); }

.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: .75rem; margin-bottom: 1rem; }
.tile { padding: .8rem .9rem; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-xl); }
.tile-label { display: block; color: var(--color-muted); font-size: .75rem; }
.tile-value { display: block; margin-top: .2rem; color: var(--color-brand-text); font-size: 1.3rem; }

.table-wrap { overflow-x: auto; }
table { width: 100%; min-width: 900px; border-collapse: collapse; }
th, td { padding: .7rem .55rem; text-align: start; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
th { color: var(--color-muted); font-size: .75rem; font-weight: 600; white-space: nowrap; }
td { font-size: .84rem; }
td small { display: block; margin-top: .1rem; color: var(--color-muted); }
tr.overdue td { background: var(--color-warning-bg); }
.nowrap { white-space: nowrap; }
.subject { max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.reference { font-family: var(--font-mono); font-size: .78rem; color: var(--color-brand-text); }
.status { display: inline-block; padding: .12rem .5rem; border: 1px solid; border-radius: 999px; font-size: .75rem; white-space: nowrap; }
.flag { display: inline-block; margin-inline-start: .35rem; padding: .05rem .4rem; border-radius: 999px; background: var(--color-danger-bg); color: var(--color-danger-fg); font-size: .68rem; }
.tabs { display: flex; gap: .4rem; margin-bottom: 1rem; flex-wrap: wrap; }
.tabs.inner { margin-bottom: 1rem; }
.tab { padding: .45rem .85rem; border: 1px solid var(--color-border-hover); border-radius: 999px; background: var(--color-surface); color: var(--color-black-700); font-size: .82rem; }
.tab.active { border-color: var(--color-brand); background: var(--color-brand); color: var(--color-on-brand); }
.source { margin: 0 0 1rem; color: var(--color-muted); font-size: .78rem; }
.indicator-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: .75rem; }
.indicator { display: flex; align-items: center; gap: .7rem; padding: .75rem .85rem; border: 1px solid var(--color-border); border-radius: var(--radius-xl); background: var(--color-surface); }
.indicator-number { flex: none; width: 1.6rem; height: 1.6rem; display: grid; place-items: center; border-radius: 999px; background: var(--color-surface-hover); color: var(--color-muted); font-size: .72rem; }
.indicator > div { flex: 1; min-width: 0; }
.indicator-label { display: block; font-size: .84rem; }
.indicator-purpose { display: block; margin-top: .1rem; color: var(--color-muted); font-size: .72rem; }
.indicator-value { flex: none; color: var(--color-brand-text); font-size: 1.1rem; }
.warnings { margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid var(--color-border); }
.warnings h3, .report-section h3 { margin: 0 0 .4rem; font-size: .95rem; }
.warning-list, .report-items { list-style: none; margin: 0; padding: 0; display: grid; gap: .35rem; }
.warning-list li, .report-items li { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; padding: .4rem .6rem; border-radius: var(--radius-lg); background: var(--color-surface-hover); font-size: .82rem; }
.warning-list strong { color: var(--color-warning-fg); }
.report-section { margin-bottom: 1.25rem; }
.report-section.narrative .report-items li { background: transparent; border: 1px dashed var(--color-border-hover); }
.pagination { display: flex; align-items: center; justify-content: center; gap: .75rem; color: var(--color-muted); font-size: .84rem; }
</style>
