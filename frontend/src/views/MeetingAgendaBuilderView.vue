<script setup>
// Stage 31 — the dedicated agenda-builder screen: pick a meeting, then build
// its agenda with priorities, estimated time, and (new this stage) standalone
// administrative/emerging items alongside request items. MeetingDetailView's
// inline agenda list stays as the quick add/remove/reorder view for request
// items; this screen owns the richer authoring the design doc asked for.
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import api from '../lib/api'

const route = useRoute()
const { t, locale } = useI18n()

const name = (item) => {
  if (!item) return t('common.none')
  return locale.value === 'ar' ? item.name_ar || item.name_en : item.name_en || item.name_ar
}

// --- Meeting picker -----------------------------------------------------------

const meetings = ref([])
const meetingId = ref(route.query.meeting ? Number(route.query.meeting) : '')
const loadingMeetings = ref(false)

async function loadMeetings() {
  loadingMeetings.value = true
  try {
    const { data } = await api.get('/meetings')
    meetings.value = data.data ?? []
  } catch {
    meetings.value = []
  } finally {
    loadingMeetings.value = false
  }
}

// --- Selected meeting + agenda --------------------------------------------------

const meeting = ref(null)
const stats = ref(null)
const loading = ref(false)
const error = ref('')
const actionError = ref('')
const showGroups = ref(false)

async function loadMeeting() {
  if (!meetingId.value) {
    meeting.value = null
    stats.value = null
    return
  }
  loading.value = true
  error.value = ''
  try {
    const [{ data: meetingData }, { data: statsData }] = await Promise.all([
      api.get(`/meetings/${meetingId.value}`),
      api.get(`/meetings/${meetingId.value}/agenda/stats`),
    ])
    meeting.value = meetingData.data
    stats.value = statsData.data
  } catch (requestError) {
    error.value = requestError.response?.data?.message ?? t('common.none')
  } finally {
    loading.value = false
  }
}

watch(meetingId, loadMeeting)

// --- Department options (for admin items) ---------------------------------------

const departmentOptions = ref([])

async function loadDepartmentOptions() {
  try {
    const { data } = await api.get('/meetings/department-options')
    departmentOptions.value = data.data ?? []
  } catch {
    departmentOptions.value = []
  }
}

// --- Add item form ----------------------------------------------------------------

const newItemType = ref('employee_request')
const newSubject = ref('')
const newDepartmentId = ref('')
const newPriority = ref('')
const newEstimatedMinutes = ref('')
const addError = ref('')
const adding = ref(false)

const requestSearch = ref('')
const requestResults = ref([])
const requestSearching = ref(false)
let searchTimer = null

const agendaRequestIds = computed(() => new Set(
  (meeting.value?.agenda_items ?? []).filter((i) => i.request).map((i) => i.request.id),
))

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

function resetAddForm() {
  newSubject.value = ''
  newDepartmentId.value = ''
  newPriority.value = ''
  newEstimatedMinutes.value = ''
  requestSearch.value = ''
  requestResults.value = []
}

async function addRequestItem(request) {
  addError.value = ''
  adding.value = true
  try {
    await api.post(`/meetings/${meeting.value.id}/agenda`, {
      item_type: 'employee_request',
      request_id: request.id,
      priority: newPriority.value || null,
      estimated_minutes: newEstimatedMinutes.value || null,
    })
    resetAddForm()
    await loadMeeting()
  } catch (requestError) {
    addError.value = requestError.response?.data?.message
      ?? requestError.response?.data?.errors?.request_id?.[0]
      ?? t('common.none')
  } finally {
    adding.value = false
  }
}

async function addAdminItem() {
  if (!newSubject.value.trim()) return
  addError.value = ''
  adding.value = true
  try {
    await api.post(`/meetings/${meeting.value.id}/agenda`, {
      item_type: newItemType.value,
      subject: newSubject.value.trim(),
      department_id: newDepartmentId.value || null,
      priority: newPriority.value || null,
      estimated_minutes: newEstimatedMinutes.value || null,
    })
    resetAddForm()
    await loadMeeting()
  } catch (requestError) {
    addError.value = requestError.response?.data?.message
      ?? requestError.response?.data?.errors?.subject?.[0]
      ?? t('common.none')
  } finally {
    adding.value = false
  }
}

// --- Existing item editing ---------------------------------------------------------

const itemError = ref({})
const itemSaving = ref({})

async function updateItem(item, patch) {
  itemError.value[item.id] = ''
  itemSaving.value[item.id] = true
  try {
    await api.patch(`/meetings/${meeting.value.id}/agenda/${item.id}`, patch)
    await loadMeeting()
  } catch (requestError) {
    itemError.value[item.id] = requestError.response?.data?.message ?? t('common.none')
  } finally {
    itemSaving.value[item.id] = false
  }
}

async function removeItem(item) {
  actionError.value = ''
  try {
    await api.delete(`/meetings/${meeting.value.id}/agenda/${item.id}`)
    await loadMeeting()
  } catch (requestError) {
    actionError.value = requestError.response?.data?.message ?? t('common.none')
  }
}

async function moveItem(index, direction) {
  const items = meeting.value.agenda_items
  const target = index + direction
  if (target < 0 || target >= items.length) return

  const order = items.map((i) => i.id)
  ;[order[index], order[target]] = [order[target], order[index]]

  actionError.value = ''
  try {
    await api.put(`/meetings/${meeting.value.id}/agenda/reorder`, { order })
    await loadMeeting()
  } catch (requestError) {
    actionError.value = requestError.response?.data?.message ?? t('common.none')
  }
}

onMounted(async () => {
  await Promise.all([loadMeetings(), loadDepartmentOptions(), loadMeeting()])
})
</script>

<template>
  <section class="page">
    <h1>{{ t('meetingsUnit.agenda.title') }}</h1>

    <div class="card picker">
      <label>
        {{ t('meetingsUnit.agenda.chooseMeeting') }}
        <select v-model="meetingId">
          <option value="">{{ t('meetingsUnit.agenda.chooseMeetingPlaceholder') }}</option>
          <option v-for="m in meetings" :key="m.id" :value="m.id">{{ m.title }}</option>
        </select>
      </label>
      <p v-if="loadingMeetings" class="state">{{ t('common.loading') }}</p>
    </div>

    <p v-if="!meetingId" class="state">{{ t('meetingsUnit.agenda.noMeetingSelected') }}</p>
    <p v-else-if="loading" class="state">{{ t('common.loading') }}</p>
    <div v-else-if="error" class="alert" role="alert">
      {{ error }} <button class="ghost" type="button" @click="loadMeeting">{{ t('common.retry') }}</button>
    </div>

    <template v-else-if="meeting">
      <p v-if="actionError" class="alert" role="alert">{{ actionError }}</p>

      <section v-if="stats" class="card stats">
        <div class="stat"><span>{{ t('meetingsUnit.agenda.stats.totalItems') }}</span><strong>{{ stats.total_items }}</strong></div>
        <div class="stat"><span>{{ t('meetingsUnit.agenda.stats.totalMinutes') }}</span><strong>{{ stats.total_estimated_minutes }}</strong></div>
        <div class="stat">
          <span>{{ t('meetingsUnit.agenda.stats.byPriority') }}</span>
          <strong>
            {{ t('meetings.agenda.priority.high') }} {{ stats.by_priority.high }}
            · {{ t('meetings.agenda.priority.medium') }} {{ stats.by_priority.medium }}
            · {{ t('meetings.agenda.priority.low') }} {{ stats.by_priority.low }}
          </strong>
        </div>
        <div class="stat">
          <span>{{ t('meetingsUnit.agenda.stats.byType') }}</span>
          <strong>
            {{ t('meetings.agenda.itemType.employee_request') }} {{ stats.by_type.employee_request }}
            · {{ t('meetings.agenda.itemType.administrative') }} {{ stats.by_type.administrative }}
            · {{ t('meetings.agenda.itemType.emerging') }} {{ stats.by_type.emerging }}
          </strong>
        </div>
        <button class="ghost" type="button" @click="showGroups = !showGroups">
          {{ showGroups ? t('meetingsUnit.agenda.stats.hideGroups') : t('meetingsUnit.agenda.stats.showGroups') }}
        </button>
      </section>

      <section v-if="showGroups && stats" class="card groups">
        <h3>{{ t('meetingsUnit.agenda.stats.groupsTitle') }}</h3>
        <div v-for="group in stats.groups" :key="group.department?.id ?? 'none'" class="group">
          <h4>{{ group.department ? name(group.department) : t('meetingsUnit.agenda.stats.noDepartment') }}</h4>
          <ul>
            <li v-for="row in group.items" :key="row.id">{{ row.label }}</li>
          </ul>
        </div>
      </section>

      <section v-can="'meeting_agenda.edit'" class="card add-form">
        <h3>{{ t('meetingsUnit.agenda.addItem') }}</h3>
        <div class="type-toggle">
          <button
            v-for="type in ['employee_request', 'administrative', 'emerging']"
            :key="type"
            type="button"
            class="ghost"
            :class="{ active: newItemType === type }"
            @click="newItemType = type; resetAddForm()"
          >
            {{ t(`meetings.agenda.itemType.${type}`) }}
          </button>
        </div>

        <div class="grid">
          <label>
            {{ t('meetings.agenda.priority.label') }}
            <select v-model="newPriority">
              <option value="">{{ t('common.none') }}</option>
              <option value="high">{{ t('meetings.agenda.priority.high') }}</option>
              <option value="medium">{{ t('meetings.agenda.priority.medium') }}</option>
              <option value="low">{{ t('meetings.agenda.priority.low') }}</option>
            </select>
          </label>
          <label>
            {{ t('meetings.agenda.estimatedMinutes') }}
            <input v-model="newEstimatedMinutes" type="number" min="1" />
          </label>
        </div>

        <template v-if="newItemType === 'employee_request'">
          <label>
            {{ t('meetings.wizard.searchRequests') }}
            <input v-model="requestSearch" type="text" :placeholder="t('meetings.wizard.searchRequests')" />
          </label>
          <ul v-if="requestSearch.trim()" class="results">
            <li v-if="requestSearching" class="state">{{ t('common.loading') }}</li>
            <template v-else>
              <li v-if="!requestResults.length" class="state">{{ t('meetings.wizard.noResults') }}</li>
              <li v-for="result in requestResults" :key="result.id" class="result">
                <span class="ref ltr">{{ result.reference_number || `#${result.id}` }}</span>
                <span>{{ result.title }}</span>
                <button
                  class="ghost"
                  type="button"
                  :disabled="adding || agendaRequestIds.has(result.id)"
                  @click="addRequestItem(result)"
                >
                  {{ t('meetings.agenda.add') }}
                </button>
              </li>
            </template>
          </ul>
        </template>
        <template v-else>
          <div class="grid">
            <label class="span-2">
              {{ t('meetings.agenda.subject') }}
              <input v-model="newSubject" type="text" />
            </label>
            <label>
              {{ t('meetings.agenda.department') }}
              <select v-model="newDepartmentId">
                <option value="">{{ t('common.none') }}</option>
                <option v-for="dept in departmentOptions" :key="dept.id" :value="dept.id">{{ name(dept) }}</option>
              </select>
            </label>
          </div>
          <div class="actions">
            <button class="primary" type="button" :disabled="adding || !newSubject.trim()" @click="addAdminItem">
              {{ adding ? t('common.saving') : t('meetings.agenda.add') }}
            </button>
          </div>
        </template>
        <p v-if="addError" class="alert">{{ addError }}</p>
      </section>

      <section class="card items">
        <h3>{{ t('meetings.agenda.title') }}</h3>
        <p v-if="!meeting.agenda_items?.length" class="state">{{ t('meetings.agenda.empty') }}</p>
        <ol v-else>
          <li v-for="(item, index) in meeting.agenda_items" :key="item.id">
            <div class="row">
              <div>
                <template v-if="item.request">
                  <span class="ref ltr">{{ item.request.reference_number || `#${item.request.id}` }}</span>
                  <strong>{{ item.request.title }}</strong>
                </template>
                <template v-else>
                  <strong>{{ item.subject }}</strong>
                  <span v-if="item.department" class="pill">{{ name(item.department) }}</span>
                </template>
                <span class="pill">{{ t(`meetings.agenda.itemType.${item.item_type}`) }}</span>
              </div>
              <div v-can="'meeting_agenda.edit'" class="item-actions">
                <button class="ghost" type="button" :disabled="index === 0" @click="moveItem(index, -1)">↑</button>
                <button class="ghost" type="button" :disabled="index === meeting.agenda_items.length - 1" @click="moveItem(index, 1)">↓</button>
                <button class="ghost danger" type="button" @click="removeItem(item)">{{ t('meetings.agenda.remove') }}</button>
              </div>
            </div>

            <div v-can="'meeting_agenda.edit'" class="edit-row">
              <label>
                {{ t('meetings.agenda.priority.label') }}
                <select
                  :value="item.priority ?? ''"
                  :disabled="itemSaving[item.id]"
                  @change="updateItem(item, { priority: $event.target.value || null })"
                >
                  <option value="">{{ t('common.none') }}</option>
                  <option value="high">{{ t('meetings.agenda.priority.high') }}</option>
                  <option value="medium">{{ t('meetings.agenda.priority.medium') }}</option>
                  <option value="low">{{ t('meetings.agenda.priority.low') }}</option>
                </select>
              </label>
              <label>
                {{ t('meetings.agenda.estimatedMinutes') }}
                <input
                  type="number"
                  min="1"
                  :value="item.estimated_minutes ?? ''"
                  :disabled="itemSaving[item.id]"
                  @change="updateItem(item, { estimated_minutes: $event.target.value || null })"
                />
              </label>
            </div>
            <p v-if="itemError[item.id]" class="alert">{{ itemError[item.id] }}</p>
          </li>
        </ol>
      </section>
    </template>
  </section>
</template>

<style scoped>
.page { padding: 1.5rem; max-inline-size: 68rem; }
.page h1 { margin: 0 0 1rem; color: var(--color-brand-text); font-size: clamp(1.25rem, 3vw, 1.7rem); }
.card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 12px; padding: 1.1rem; margin-bottom: 1rem; }
.card h3 { margin: 0 0 .6rem; color: var(--color-brand-text); font-size: 1rem; }
.card h4 { margin: .5rem 0 .3rem; color: var(--color-black-700); font-size: .88rem; }

.picker label { display: flex; flex-direction: column; gap: .3rem; font-size: .875rem; color: var(--color-black-700); }
select, input[type='text'], input[type='number'] {
  padding: .5rem .6rem;
  border: 1px solid var(--color-border-hover);
  border-radius: 8px;
  background: var(--color-surface);
  color: var(--color-foreground);
  font: inherit;
}

.state { color: var(--color-muted); font-size: .85rem; margin: 0; }
.alert { padding: .65rem .8rem; background: var(--color-danger-bg); color: var(--color-danger-fg); border: 1px solid var(--color-danger-border); border-radius: 8px; font-size: .875rem; margin: .5rem 0 0; }

.stats { display: flex; flex-wrap: wrap; align-items: center; gap: 1.25rem; }
.stat { display: grid; gap: .2rem; }
.stat span { color: var(--color-muted); font-size: .76rem; }
.stat strong { color: var(--color-black-700); font-size: .88rem; }

.groups .group { padding: .5rem 0; border-bottom: 1px solid var(--color-border); }
.groups .group:last-child { border-bottom: 0; }
.groups ul { margin: 0; padding-inline-start: 1.2rem; font-size: .84rem; color: var(--color-black-700); }

.type-toggle { display: flex; gap: .4rem; margin-bottom: .85rem; }
.type-toggle button.active { background: var(--color-brand); color: var(--color-on-brand); border-color: var(--color-brand); }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: .85rem; margin-bottom: .85rem; }
.span-2 { grid-column: 1 / -1; }
label { display: flex; flex-direction: column; gap: .3rem; font-size: .875rem; color: var(--color-black-700); margin-bottom: .6rem; }

.results { display: grid; gap: .4rem; padding: 0; margin: .5rem 0 0; list-style: none; }
.result { display: flex; align-items: center; justify-content: space-between; gap: .5rem; font-size: .84rem; }
.ref { color: var(--color-muted); font-size: .78rem; margin-inline-end: .5rem; }

.items ol { display: grid; gap: .6rem; padding: 0; margin: 0; list-style: none; }
.items li { display: grid; gap: .5rem; padding-bottom: .75rem; border-bottom: 1px solid var(--color-border); font-size: .86rem; }
.items li .row { display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
.pill { margin-inline-start: .5rem; padding: .1rem .5rem; background: var(--color-surface-hover); color: var(--color-black-600); border-radius: 999px; font-size: .72rem; }
.item-actions { white-space: nowrap; }
.edit-row { display: flex; gap: 1rem; flex-wrap: wrap; }
.edit-row label { margin-bottom: 0; }

.actions { display: flex; justify-content: flex-end; }
button { cursor: pointer; border-radius: 8px; font-size: .85rem; }
button:disabled { cursor: not-allowed; opacity: .6; }
.primary { padding: .5rem .9rem; border: 0; background: var(--color-brand); color: var(--color-on-brand); }
.ghost { padding: .35rem .6rem; border: 1px solid var(--color-border-hover); background: var(--color-surface); color: var(--color-foreground); }
.ghost:hover { background: var(--color-surface-hover); }
.ghost.danger { color: var(--color-danger-fg); border-color: var(--color-danger-border); }
</style>
