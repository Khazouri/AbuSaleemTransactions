<script setup>
/**
 * Decisions & recommendations (القرارات والتوصيات) — Stage 25.
 *
 * Two views of the same Stage 21 data. The register is every binding decision
 * the committees have recorded, filterable and exportable, away from the
 * meeting that produced it. The worklist is the other direction: the agenda
 * items *this* user still owes a vote on, across every committee they sit on.
 *
 * Recording a decision is deliberately NOT here — it needs the signature pad
 * and the full agenda context, so it stays on the meeting screen. Casting a
 * vote posts back to the same Stage 21 endpoint the meeting screen uses.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import { downloadExport } from '../lib/download'
import { useAuthStore } from '../stores/auth'

const { t, locale } = useI18n()
const auth = useAuthStore()

const tab = ref('register')

const rows = ref([])
const options = ref({ committees: [], outcomes: [], formats: [] })
const page = ref({ current_page: 1, last_page: 1, total: 0 })
const filters = ref(blankFilters())
const loading = ref(false)
const loadError = ref(null)
const exporting = ref(null)
const exportError = ref(null)

const pending = ref([])
const pendingLoading = ref(false)
const pendingError = ref(null)
/** Keyed by agenda-item id so one busy row never disables the others. */
const votingBusy = ref({})
const votingError = ref({})

function blankFilters() {
  return { outcome: '', committee_id: '', date_from: '', date_to: '', search: '' }
}

const isBusy = computed(() => loading.value || exporting.value !== null)

function localName(row) {
  if (!row) return t('common.none')
  return locale.value === 'ar' ? row.name_ar || row.name_en : row.name_en || row.name_ar
}

function date(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', {
    year: 'numeric', month: 'short', day: 'numeric',
  }).format(new Date(value))
}

function dateTime(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', {
    year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  }).format(new Date(value))
}

/** Only the filters that are actually set — an empty string is not a filter. */
function activeFilters() {
  return Object.fromEntries(
    Object.entries(filters.value).filter(([, value]) => value !== '' && value !== null),
  )
}

async function load(requestedPage = 1) {
  loading.value = true
  loadError.value = null
  try {
    const { data } = await api.get('/decisions', {
      params: { page: requestedPage, ...activeFilters() },
    })
    rows.value = data.data ?? []
    page.value = data.meta ?? page.value
  } catch (error) {
    loadError.value = error
  } finally {
    loading.value = false
  }
}

async function loadOptions() {
  try {
    const { data } = await api.get('/decisions/filters')
    options.value = data.data ?? options.value
  } catch (error) {
    loadError.value = error
  }
}

async function loadPending() {
  pendingLoading.value = true
  pendingError.value = null
  try {
    const { data } = await api.get('/decisions/pending')
    pending.value = data.data ?? []
  } catch (error) {
    pendingError.value = error
  } finally {
    pendingLoading.value = false
  }
}

/** The file is generated for the language on screen and covers the whole
 *  filtered set, not just the visible page. */
async function exportAs(format) {
  exporting.value = format
  exportError.value = null
  try {
    await downloadExport(
      '/decisions/export',
      { ...activeFilters(), format, locale: locale.value },
      `decisions-register.${format}`,
    )
  } catch (error) {
    exportError.value = error?.response?.data?.message ?? t('decisions.exportFailed')
  } finally {
    exporting.value = null
  }
}

// --- Voting from the worklist ------------------------------------------------
// Same helpers the meeting screen uses; the tally is computed client-side from
// the votes already in the payload so the counts move the moment you vote.

function tally(item) {
  const counts = { approve: 0, reject: 0, defer: 0 }
  for (const vote of item.votes ?? []) counts[vote.vote] = (counts[vote.vote] ?? 0) + 1
  return counts
}

function myVote(item) {
  return (item.votes ?? []).find((vote) => vote.user.id === auth.user?.id)?.vote ?? null
}

async function castVote(item, voteValue) {
  votingError.value[item.id] = ''
  votingBusy.value[item.id] = true
  try {
    await api.post(`/meetings/${item.meeting.id}/agenda/${item.id}/votes`, { vote: voteValue })
    await loadPending()
  } catch (error) {
    votingError.value[item.id] = error.response?.data?.message ?? t('decisions.voteFailed')
  } finally {
    votingBusy.value[item.id] = false
  }
}

function applyFilters() { load(1) }
function clearFilters() { filters.value = blankFilters(); load(1) }

/** `window` isn't in template scope, so the print grant needs a real handler. */
function printPage() { window.print() }

function showTab(name) {
  tab.value = name
  if (name === 'pending' && pending.value.length === 0) loadPending()
}

onMounted(async () => {
  await Promise.all([load(), loadOptions(), loadPending()])
})
</script>

<template>
  <section class="decisions">
    <div class="heading no-print">
      <div>
        <h2>{{ t('decisions.title') }}</h2>
        <p class="subtitle">{{ t('decisions.subtitle') }}</p>
      </div>
      <div class="heading-actions">
        <button v-can="'decisions.print'" class="ghost" type="button" @click="printPage">
          {{ t('decisions.print') }}
        </button>
        <template v-if="tab === 'register'">
          <button v-can="'decisions.export'" class="primary" type="button" :disabled="isBusy" @click="exportAs('xlsx')">
            {{ exporting === 'xlsx' ? t('decisions.exporting') : t('decisions.exportExcel') }}
          </button>
          <button v-can="'decisions.export'" class="ghost" type="button" :disabled="isBusy" @click="exportAs('pdf')">
            {{ exporting === 'pdf' ? t('decisions.exporting') : t('decisions.exportPdf') }}
          </button>
        </template>
      </div>
    </div>

    <nav class="tabs no-print" :aria-label="t('decisions.title')">
      <button
        v-for="name in ['register', 'pending']"
        :key="name"
        class="tab"
        :class="{ active: tab === name }"
        type="button"
        @click="showTab(name)"
      >
        {{ t(`decisions.tabs.${name}`) }}
        <span v-if="name === 'pending' && pending.length" class="count">{{ pending.length }}</span>
      </button>
    </nav>

    <!-- === Register ==================================================== -->
    <template v-if="tab === 'register'">
      <form class="card filters no-print" @submit.prevent="applyFilters">
        <h3>{{ t('decisions.filters') }}</h3>
        <div class="filter-grid">
          <label>
            {{ t('decisions.outcomeLabel') }}
            <select v-model="filters.outcome">
              <option value="">{{ t('decisions.allOutcomes') }}</option>
              <option v-for="option in options.outcomes" :key="option" :value="option">
                {{ t(`decisions.outcome.${option}`) }}
              </option>
            </select>
          </label>
          <label>
            {{ t('decisions.committee') }}
            <select v-model="filters.committee_id">
              <option value="">{{ t('decisions.allCommittees') }}</option>
              <option v-for="committee in options.committees" :key="committee.id" :value="committee.id">
                {{ localName(committee) }}
              </option>
            </select>
          </label>
          <label>
            {{ t('decisions.dateFrom') }}
            <input v-model="filters.date_from" type="date" />
          </label>
          <label>
            {{ t('decisions.dateTo') }}
            <input v-model="filters.date_to" type="date" />
          </label>
          <label>
            {{ t('decisions.search') }}
            <input v-model="filters.search" type="search" :placeholder="t('decisions.searchPlaceholder')" />
          </label>
        </div>
        <div class="actions">
          <button class="primary" type="submit" :disabled="isBusy">{{ t('decisions.apply') }}</button>
          <button class="ghost" type="button" :disabled="isBusy" @click="clearFilters">{{ t('decisions.clear') }}</button>
        </div>
      </form>

      <p v-if="loadError" class="alert no-print">
        {{ t('nav.error') }}
        <button class="ghost" type="button" @click="load(page.current_page)">{{ t('common.retry') }}</button>
      </p>
      <p v-if="exportError" class="alert no-print">{{ exportError }}</p>

      <div class="card list">
        <p v-if="loading" class="state">{{ t('common.loading') }}</p>
        <p v-else-if="!loadError && rows.length === 0" class="state">{{ t('decisions.empty') }}</p>
        <div v-else-if="!loadError" class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{{ t('decisions.columns.reference') }}</th>
                <th>{{ t('decisions.columns.subject') }}</th>
                <th>{{ t('decisions.columns.committee') }}</th>
                <th>{{ t('decisions.columns.meeting') }}</th>
                <th>{{ t('decisions.columns.outcome') }}</th>
                <th>{{ t('decisions.columns.tally') }}</th>
                <th>{{ t('decisions.columns.decidedBy') }}</th>
                <th>{{ t('decisions.columns.decidedAt') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="row.id">
                <td>
                  <RouterLink
                    v-if="row.context?.transaction"
                    class="reference ltr"
                    :to="{ name: 'transaction_details', params: { id: row.context.transaction.id } }"
                  >{{ row.context.transaction.reference_number ?? `#${row.context.transaction.id}` }}</RouterLink>
                  <span v-else>{{ t('common.none') }}</span>
                </td>
                <td class="subject">{{ row.context?.transaction?.title ?? t('common.none') }}</td>
                <td>{{ localName(row.context?.committee) }}</td>
                <td>
                  <RouterLink
                    v-if="row.context?.meeting"
                    :to="{ name: 'meeting_details', params: { id: row.context.meeting.id } }"
                  >{{ row.context.meeting.title }}</RouterLink>
                  <span v-else>{{ t('common.none') }}</span>
                  <small v-if="row.context?.meeting" class="muted">{{ date(row.context.meeting.scheduled_at) }}</small>
                </td>
                <td>
                  <span class="outcome" :class="row.outcome">{{ t(`decisions.outcome.${row.outcome}`) }}</span>
                </td>
                <td class="ltr nowrap">
                  {{ row.votes_approve_count }} / {{ row.votes_reject_count }} / {{ row.votes_defer_count }}
                </td>
                <td>{{ row.decided_by?.name ?? t('common.none') }}</td>
                <td class="nowrap">{{ dateTime(row.decided_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <nav v-if="!loading && !loadError && page.last_page > 1" class="pagination no-print" :aria-label="t('decisions.title')">
        <button class="ghost" :disabled="page.current_page <= 1" @click="load(page.current_page - 1)">
          {{ t('transactions.previous') }}
        </button>
        <span>{{ t('transactions.page', { current: page.current_page, last: page.last_page }) }}</span>
        <button class="ghost" :disabled="page.current_page >= page.last_page" @click="load(page.current_page + 1)">
          {{ t('transactions.next') }}
        </button>
      </nav>
    </template>

    <!-- === Awaiting my vote ============================================ -->
    <template v-else>
      <p v-if="pendingError" class="alert">
        {{ t('nav.error') }}
        <button class="ghost" type="button" @click="loadPending">{{ t('common.retry') }}</button>
      </p>

      <p v-if="pendingLoading" class="card state">{{ t('common.loading') }}</p>
      <p v-else-if="!pendingError && pending.length === 0" class="card state">{{ t('decisions.noPending') }}</p>

      <ul v-else-if="!pendingError" class="pending-list">
        <li v-for="item in pending" :key="item.id" class="card pending-item">
          <div class="pending-head">
            <div>
              <RouterLink
                v-if="item.transaction"
                class="reference ltr"
                :to="{ name: 'transaction_details', params: { id: item.transaction.id } }"
              >{{ item.transaction.reference_number ?? `#${item.transaction.id}` }}</RouterLink>
              <strong class="pending-title">{{ item.transaction?.title ?? t('common.none') }}</strong>
            </div>
            <div class="pending-meta">
              <RouterLink :to="{ name: 'meeting_details', params: { id: item.meeting.id } }">
                {{ item.meeting.title }}
              </RouterLink>
              <small class="muted">
                {{ localName(item.meeting.committee) }} — {{ date(item.meeting.scheduled_at) }}
              </small>
            </div>
          </div>

          <div class="tally">
            <span>{{ t('decisions.tally.approve') }}: {{ tally(item).approve }}</span>
            <span>{{ t('decisions.tally.reject') }}: {{ tally(item).reject }}</span>
            <span>{{ t('decisions.tally.defer') }}: {{ tally(item).defer }}</span>
          </div>

          <div v-can="'decisions.add'" class="vote-actions no-print">
            <button
              v-for="option in ['approve', 'reject', 'defer']"
              :key="option"
              class="ghost"
              :class="{ active: myVote(item) === option }"
              type="button"
              :disabled="votingBusy[item.id]"
              @click="castVote(item, option)"
            >
              {{ t(`decisions.vote.${option}`) }}
            </button>
            <span v-if="myVote(item)" class="muted">{{ t('decisions.yourVote') }}</span>
          </div>
          <p v-if="votingError[item.id]" class="alert">{{ votingError[item.id] }}</p>
        </li>
      </ul>
    </template>
  </section>
</template>

<style scoped>
.heading { display: flex; align-items: end; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; }
h2 { margin: 0; color: var(--color-nav); font-size: 1.2rem; }
.subtitle { margin: .15rem 0 0; color: var(--color-muted); font-size: .82rem; }
.heading-actions { display: flex; gap: .5rem; flex-wrap: wrap; }

.tabs { display: flex; gap: .35rem; margin-bottom: 1rem; border-bottom: 1px solid var(--color-border); }
.tab { display: inline-flex; align-items: center; gap: .4rem; padding: .5rem .9rem; border: 0; border-bottom: 2px solid transparent; background: none; color: var(--color-muted); font-size: .88rem; cursor: pointer; }
.tab.active { color: var(--color-nav); border-bottom-color: var(--color-nav); }
.count { display: inline-block; min-width: 1.25rem; padding: .05rem .35rem; border-radius: 999px; background: var(--color-nav); color: #fff; font-size: .7rem; }

.filters, .list { padding: 1.25rem; margin-bottom: 1rem; }
.filters h3 { margin: 0 0 1rem; font-size: 1rem; }
.filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 1rem; }
label { display: flex; flex-direction: column; gap: .3rem; color: var(--color-black-700); font-size: .85rem; }
select, input { min-width: 0; padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); }
select:focus, input:focus { outline: 2px solid var(--color-nav); outline-offset: 1px; }
.actions { display: flex; gap: .5rem; margin-top: 1rem; }
button { cursor: pointer; border-radius: var(--radius-lg); font-size: .85rem; }
.primary { padding: .5rem .9rem; border: 0; color: #fff; background: var(--color-nav); }
.ghost { padding: .45rem .7rem; border: 1px solid var(--color-border-hover); background: var(--color-surface); color: var(--color-black-700); }
.ghost:hover:not(:disabled) { background: var(--color-surface-hover); }
.ghost.active { border-color: var(--color-nav); color: var(--color-nav); font-weight: 600; }
button:disabled { cursor: not-allowed; opacity: .55; }
.alert { padding: .65rem .8rem; margin: 0 0 1rem; border: 1px solid #fecaca; border-radius: var(--radius-lg); color: #b91c1c; background: #fef2f2; }
.alert .ghost { margin-inline-start: .5rem; }
.state { padding: 1.25rem; margin: 0; color: var(--color-muted); }

.table-wrap { overflow-x: auto; }
table { width: 100%; min-width: 900px; border-collapse: collapse; }
th, td { padding: .7rem .55rem; text-align: start; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
th { color: var(--color-muted); font-size: .75rem; font-weight: 600; white-space: nowrap; }
td { font-size: .84rem; }
.nowrap { white-space: nowrap; }
.subject { max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.reference { font-family: var(--font-mono); font-size: .78rem; color: var(--color-nav); }
.muted { display: block; color: var(--color-muted); font-size: .72rem; }
.outcome { display: inline-block; padding: .12rem .55rem; border: 1px solid; border-radius: 999px; font-size: .75rem; white-space: nowrap; }
.outcome.approve { color: #15803d; border-color: #86efac; background: #f0fdf4; }
.outcome.reject { color: #b91c1c; border-color: #fecaca; background: #fef2f2; }
.outcome.defer { color: #b45309; border-color: #fed7aa; background: #fffbeb; }

.pending-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .75rem; }
.pending-item { padding: 1rem 1.15rem; }
.pending-head { display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.pending-title { display: block; margin-top: .2rem; font-size: .95rem; }
.pending-meta { text-align: end; font-size: .82rem; }
.tally { display: flex; gap: 1rem; margin: .75rem 0 .5rem; color: var(--color-muted); font-size: .8rem; }
.vote-actions { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; }
.pagination { display: flex; align-items: center; justify-content: center; gap: .75rem; color: var(--color-muted); font-size: .84rem; }
</style>
