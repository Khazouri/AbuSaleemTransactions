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

// Stage 44 — [C] §2's "مدة الانتظار" column: no stored field for this, just
// the elapsed time since submission, computed client-side from a value the
// row already carries.
function waitingDays(row) {
  if (!row.submitted_at) return t('common.none')
  const days = Math.max(0, Math.floor((Date.now() - new Date(row.submitted_at).getTime()) / 86400000))
  return t('meetingsUnit.candidates.table.waitingDays', { count: days })
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

// Stage 102 — no `nominate`: picking a request for a meeting is the nomination.
const ACTION_ENDPOINTS = {
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
  <section class="page candidates">
    <div class="heading">
      <div>
        <h2>{{ t('meetingsUnit.candidates.title') }}</h2>
        <p class="subtitle">{{ t('meetingsUnit.candidates.subtitle') }}</p>
      </div>
    </div>

    <div class="card card-flat card-pad filters">
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

    <div v-else class="card card-flat card-pad list">
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>{{ t('meetingsUnit.candidates.table.reference') }}</th>
              <th>{{ t('meetingsUnit.candidates.table.title') }}</th>
              <th>{{ t('meetingsUnit.candidates.table.employee') }}</th>
              <th>{{ t('meetingsUnit.candidates.table.requestType') }}</th>
              <th>{{ t('meetingsUnit.candidates.table.department') }}</th>
              <th>{{ t('meetingsUnit.candidates.table.status') }}</th>
              <th>{{ t('meetingsUnit.candidates.table.submitted') }}</th>
              <th>{{ t('meetingsUnit.candidates.table.waiting') }}</th>
              <th>{{ t('meetingsUnit.candidates.table.fileCompleteness') }}</th>
              <th>{{ t('meetingsUnit.candidates.table.priority') }}</th>
              <th>{{ t('meetingsUnit.candidates.table.proposedMeeting') }}</th>
              <th>{{ t('meetingsUnit.candidates.table.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id">
              <td class="ltr">{{ row.reference_number || `#${row.id}` }}</td>
              <td>{{ row.title }}</td>
              <td>{{ row.created_by?.name ?? t('common.none') }}</td>
              <td>{{ row.request_type ? name(row.request_type) : t('common.none') }}</td>
              <td>{{ row.department ? name(row.department) : t('common.none') }}</td>
              <td>
                <span class="status" :style="{ '--status-color': row.status?.color || 'var(--color-muted)' }">
                  {{ locale === 'ar' ? row.status?.name_ar : row.status?.name_en }}
                </span>
                <span v-if="row.is_overdue" class="pill danger">{{ t('meetingsUnit.candidates.table.overdue') }}</span>
              </td>
              <td class="nowrap">{{ date(row.submitted_at) }}</td>
              <td class="nowrap">{{ waitingDays(row) }}</td>
              <td>
                <span class="pill" :class="row.attachments_count ? 'good' : 'warn'">
                  {{ row.attachments_count ?? 0 }}
                </span>
              </td>
              <td>
                <span v-if="row.proposed_meeting?.priority" class="pill">
                  {{ t(`meetings.agenda.priority.${row.proposed_meeting.priority}`) }}
                </span>
                <span v-else>{{ t('common.none') }}</span>
              </td>
              <td>
                <template v-if="row.proposed_meeting">
                  {{ row.proposed_meeting.title }} — {{ date(row.proposed_meeting.scheduled_at) }}
                </template>
                <template v-else>{{ t('meetingsUnit.candidates.table.noProposedMeeting') }}</template>
              </td>
              <td>
                <div class="row-actions">
                  <RouterLink class="ghost" :to="{ name: 'request_details', params: { id: row.id } }">
                    {{ t('meetingsUnit.candidates.actions.openFile') }}
                  </RouterLink>
                  <button v-can="'committee_candidates.edit'" class="ghost" type="button" @click="openPrompt('defer', row)">
                    {{ t('meetingsUnit.candidates.actions.defer') }}
                  </button>
                  <button v-can="'committee_candidates.edit'" class="ghost" type="button" @click="openPrompt('returnToStudy', row)">
                    {{ t('meetingsUnit.candidates.actions.returnToStudy') }}
                  </button>
                  <button v-can="'committee_candidates.edit'" class="ghost" type="button" @click="openPrompt('requestCompletion', row)">
                    {{ t('meetingsUnit.candidates.actions.requestCompletion') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="promptAction" class="modal-backdrop" @click.self="closePrompt">
      <div class="modal candidates-modal">
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
.filters { display: flex; flex-wrap: wrap; gap: var(--space-4); align-items: end; margin-bottom: var(--space-4); }
.filters label { display: flex; flex-direction: column; gap: .3rem; font-size: var(--text-base); color: var(--color-black-700); }
.filters .grow { flex: 1; min-inline-size: min(220px, 100%); }
select, input[type='text'], textarea {
  padding: .5rem .6rem;
  border: 1px solid var(--color-border-hover);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
  color: var(--color-foreground);
  font: inherit;
}

.list { margin-bottom: var(--space-4); }
.ltr { direction: ltr; unicode-bidi: isolate; }
.nowrap { white-space: nowrap; }

.pill.danger { margin-inline-start: .35rem; }
.row-actions { display: flex; flex-wrap: wrap; gap: var(--space-2); justify-content: flex-end; }

.candidates-modal { inline-size: min(28rem, 100%); }
.modal label { display: flex; flex-direction: column; gap: .3rem; font-size: var(--text-base); color: var(--color-black-700); }
.modal textarea { resize: vertical; }
</style>
