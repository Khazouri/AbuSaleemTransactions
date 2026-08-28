<script setup>
// Stage 32 — the candidate-requests worklist: requests sitting with the
// committee, not yet placed on a meeting's agenda. Replaces the ad-hoc
// request search inside MeetingAgendaBuilderView as the way a request
// first becomes linked to the meetings unit.
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'

const { t, locale } = useI18n()

const name = (row) => {
  if (!row) return t('common.none')
  return locale.value === 'ar' ? row.name_ar || row.name_en : row.name_en || row.name_ar
}

function date(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium' })
    .format(new Date(value))
}

// --- Filters + lookups -----------------------------------------------------

const statusFilter = ref('')
const departmentFilter = ref('')
const search = ref('')
const departments = ref([])

// Mirrors RequestStatusSeeder's Arabic/English names for the three
// CommitteeStatusService::CANDIDATE_STATUSES codes — the filter only ever
// needs to offer these three, so a static label map here avoids an extra
// lookup endpoint for three fixed rows.
const STATUS_OPTIONS = [
  { code: 'ready', name_ar: 'جاهزة', name_en: 'Ready' },
  { code: 'in_meeting', name_ar: 'في الاجتماع', name_en: 'In Meeting' },
  { code: 'nominated_for_committee', name_ar: 'مرشح للجنة', name_en: 'Nominated for Committee' },
]

async function loadFilters() {
  try {
    const { data } = await api.get('/requests/filters')
    departments.value = data.data?.departments ?? []
  } catch {
    departments.value = []
  }
}

// --- List --------------------------------------------------------------------

const rows = ref([])
const loading = ref(false)
const loadError = ref('')

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get('/committee-candidates', {
      params: {
        status: statusFilter.value || undefined,
        department_id: departmentFilter.value || undefined,
        search: search.value.trim() || undefined,
        per_page: 50,
      },
    })
    rows.value = data.data ?? []
  } catch (error) {
    loadError.value = error.response?.data?.message ?? t('common.none')
    rows.value = []
  } finally {
    loading.value = false
  }
}

let searchTimer = null
watch([statusFilter, departmentFilter], load)
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(load, 300)
})

// --- Actions -------------------------------------------------------------------

const ACTION_ENDPOINTS = {
  nominate: 'nominate',
  defer: 'defer',
  returnToStudy: 'return-to-study',
  requestCompletion: 'request-completion',
}

const REQUIRES_COMMENT = new Set(['defer', 'returnToStudy', 'requestCompletion'])

const promptAction = ref(null)
const promptRow = ref(null)
const promptComment = ref('')
const promptError = ref('')
const acting = ref(false)

function openPrompt(action, row) {
  promptAction.value = action
  promptRow.value = row
  promptComment.value = ''
  promptError.value = ''
}

function closePrompt() {
  promptAction.value = null
  promptRow.value = null
}

async function submitAction() {
  if (!promptAction.value || !promptRow.value) return
  const requiresComment = REQUIRES_COMMENT.has(promptAction.value)
  if (requiresComment && !promptComment.value.trim()) {
    promptError.value = t('meetingsUnit.candidates.commentRequiredHint')
    return
  }

  acting.value = true
  promptError.value = ''
  try {
    await api.post(
      `/committee-candidates/${promptRow.value.id}/${ACTION_ENDPOINTS[promptAction.value]}`,
      { comment: promptComment.value.trim() || undefined },
    )
    closePrompt()
    await load()
  } catch (error) {
    promptError.value = error.response?.data?.errors?.action?.[0]
      ?? error.response?.data?.message
      ?? t('common.none')
  } finally {
    acting.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadFilters(), load()])
})
</script>

<template>
  <section class="page">
    <h1>{{ t('meetingsUnit.candidates.title') }}</h1>
    <p class="subtitle">{{ t('meetingsUnit.candidates.subtitle') }}</p>

    <div class="card filters">
      <label>
        {{ t('meetingsUnit.candidates.filters.status') }}
        <select v-model="statusFilter">
          <option value="">{{ t('meetingsUnit.candidates.filters.allStatuses') }}</option>
          <option v-for="opt in STATUS_OPTIONS" :key="opt.code" :value="opt.code">{{ name(opt) }}</option>
        </select>
      </label>
      <label>
        {{ t('meetingsUnit.candidates.filters.department') }}
        <select v-model="departmentFilter">
          <option value="">{{ t('meetingsUnit.candidates.filters.allDepartments') }}</option>
          <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ name(dept) }}</option>
        </select>
      </label>
      <label class="grow">
        {{ t('meetingsUnit.candidates.filters.search') }}
        <input v-model="search" type="text" :placeholder="t('meetingsUnit.candidates.filters.searchPlaceholder')" />
      </label>
    </div>

    <p v-if="loading" class="state">{{ t('common.loading') }}</p>
    <div v-else-if="loadError" class="alert" role="alert">
      {{ loadError }} <button class="ghost" type="button" @click="load">{{ t('common.retry') }}</button>
    </div>
    <p v-else-if="!rows.length" class="state">{{ t('meetingsUnit.candidates.empty') }}</p>

    <div v-else class="card table-wrap">
      <table>
        <thead>
          <tr>
            <th>{{ t('meetingsUnit.candidates.table.reference') }}</th>
            <th>{{ t('meetingsUnit.candidates.table.title') }}</th>
            <th>{{ t('meetingsUnit.candidates.table.department') }}</th>
            <th>{{ t('meetingsUnit.candidates.table.status') }}</th>
            <th>{{ t('meetingsUnit.candidates.table.submitted') }}</th>
            <th>{{ t('meetingsUnit.candidates.table.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.id">
            <td class="ltr">{{ row.reference_number || `#${row.id}` }}</td>
            <td>{{ row.title }}</td>
            <td>{{ row.department ? name(row.department) : t('common.none') }}</td>
            <td>
              <span class="pill" :style="{ background: row.status?.color }">
                {{ locale === 'ar' ? row.status?.name_ar : row.status?.name_en }}
              </span>
              <span v-if="row.is_overdue" class="pill danger">{{ t('meetingsUnit.candidates.table.overdue') }}</span>
            </td>
            <td>{{ date(row.submitted_at) }}</td>
            <td class="actions">
              <button v-can="'committee_candidates.add'" class="ghost" type="button" @click="openPrompt('nominate', row)">
                {{ t('meetingsUnit.candidates.actions.nominate') }}
              </button>
              <button v-can="'committee_candidates.edit'" class="ghost" type="button" @click="openPrompt('defer', row)">
                {{ t('meetingsUnit.candidates.actions.defer') }}
              </button>
              <button v-can="'committee_candidates.edit'" class="ghost" type="button" @click="openPrompt('returnToStudy', row)">
                {{ t('meetingsUnit.candidates.actions.returnToStudy') }}
              </button>
              <button v-can="'committee_candidates.edit'" class="ghost" type="button" @click="openPrompt('requestCompletion', row)">
                {{ t('meetingsUnit.candidates.actions.requestCompletion') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="promptAction" class="overlay" @click.self="closePrompt">
      <div class="card modal">
        <h3>{{ t(`meetingsUnit.candidates.actions.${promptAction}`) }}</h3>
        <label>
          {{ t('meetingsUnit.candidates.commentLabel') }}
          <span class="hint">
            {{ REQUIRES_COMMENT.has(promptAction)
              ? t('meetingsUnit.candidates.commentRequiredHint')
              : t('meetingsUnit.candidates.commentOptionalHint') }}
          </span>
          <textarea v-model="promptComment" rows="3"></textarea>
        </label>
        <p v-if="promptError" class="alert">{{ promptError }}</p>
        <div class="modal-actions">
          <button class="ghost" type="button" :disabled="acting" @click="closePrompt">
            {{ t('meetingsUnit.candidates.cancelPrompt') }}
          </button>
          <button class="primary" type="button" :disabled="acting" @click="submitAction">
            {{ acting ? t('meetingsUnit.candidates.processing') : t('meetingsUnit.candidates.confirm') }}
          </button>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.page { padding: 1.5rem; max-inline-size: 78rem; }
.page h1 { margin: 0 0 .3rem; color: var(--color-brand-text); font-size: clamp(1.25rem, 3vw, 1.7rem); }
.subtitle { margin: 0 0 1rem; color: var(--color-muted); font-size: .85rem; }

.card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 12px; padding: 1.1rem; margin-bottom: 1rem; }

.filters { display: flex; flex-wrap: wrap; gap: 1rem; align-items: end; }
.filters label { display: flex; flex-direction: column; gap: .3rem; font-size: .875rem; color: var(--color-black-700); }
.filters .grow { flex: 1; min-inline-size: 220px; }
select, input[type='text'], textarea {
  padding: .5rem .6rem;
  border: 1px solid var(--color-border-hover);
  border-radius: 8px;
  background: var(--color-surface);
  color: var(--color-foreground);
  font: inherit;
}

.state { color: var(--color-muted); font-size: .85rem; margin: 0; }
.alert { padding: .65rem .8rem; background: var(--color-danger-bg); color: var(--color-danger-fg); border: 1px solid var(--color-danger-border); border-radius: 8px; font-size: .875rem; margin: .5rem 0 0; }

.table-wrap { padding: 0; overflow-x: auto; }
table { inline-size: 100%; border-collapse: collapse; font-size: .85rem; }
th, td { padding: .65rem .85rem; text-align: start; border-bottom: 1px solid var(--color-border); white-space: nowrap; }
th { color: var(--color-muted); font-weight: 600; font-size: .76rem; }
.ltr { direction: ltr; unicode-bidi: isolate; }

.pill { display: inline-block; padding: .15rem .55rem; border-radius: 999px; font-size: .74rem; color: #fff; }
.pill.danger { background: var(--color-danger-bg); color: var(--color-danger-fg); border: 1px solid var(--color-danger-border); margin-inline-start: .35rem; }

.actions { white-space: normal; display: flex; flex-wrap: wrap; gap: .35rem; }
button { cursor: pointer; border-radius: 8px; font-size: .8rem; }
button:disabled { cursor: not-allowed; opacity: .6; }
.ghost { padding: .35rem .6rem; border: 1px solid var(--color-border-hover); background: var(--color-surface); color: var(--color-foreground); }
.ghost:hover { background: var(--color-surface-hover); }
.primary { padding: .5rem .9rem; border: 0; background: var(--color-brand); color: var(--color-on-brand); }

.overlay {
  position: fixed; inset: 0; background: var(--color-overlay);
  display: flex; align-items: center; justify-content: center; padding: 1rem; z-index: 50;
}
.modal { inline-size: min(28rem, 100%); }
.modal h3 { margin: 0 0 .75rem; color: var(--color-brand-text); font-size: 1rem; }
.modal label { display: flex; flex-direction: column; gap: .3rem; font-size: .875rem; color: var(--color-black-700); }
.modal .hint { font-size: .74rem; color: var(--color-muted); }
.modal textarea { resize: vertical; }
.modal-actions { display: flex; justify-content: flex-end; gap: .5rem; margin-top: 1rem; }
</style>
