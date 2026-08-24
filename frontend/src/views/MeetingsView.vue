<script setup>
/**
 * Committees & Meetings screen (اللجان والاجتماعات) — Stage 20.
 *
 * Committees have no screen of their own on the 22/23-screen sheet, so their
 * administration lives here alongside the meeting schedule: managing who
 * sits on a committee is a prerequisite of scheduling that committee's
 * meetings, not a separate capability (the backend gates both the same way,
 * see routes/api.php).
 */
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import MeetingSchedulingWizard from '../components/MeetingSchedulingWizard.vue'
import api from '../lib/api'

const { t, locale } = useI18n()

const committees = ref([])
const userOptions = ref([])
const meetings = ref([])
const loadingCommittees = ref(false)
const loadingMeetings = ref(false)
const loadError = ref(null)

const name = (item) => {
  if (!item) return t('common.none')
  return locale.value === 'ar' ? item.name_ar || item.name_en : item.name_en || item.name_ar
}

function dateTime(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', {
    dateStyle: 'medium', timeStyle: 'short',
  }).format(new Date(value))
}

// --- Committees ------------------------------------------------------------

const committeeErrors = ref({})
const committeeFormError = ref(null)
const editingCommitteeId = ref(null)
const showCommitteeForm = ref(false)
const savingCommittee = ref(false)

const blankCommitteeForm = () => ({ name_ar: '', name_en: '', description: '', is_active: true })
const committeeForm = ref(blankCommitteeForm())

async function loadCommittees() {
  loadingCommittees.value = true
  loadError.value = null
  try {
    const [committeesRes, usersRes] = await Promise.all([
      api.get('/committees'),
      api.get('/committees/user-options'),
    ])
    committees.value = committeesRes.data.data ?? []
    userOptions.value = usersRes.data.data ?? []
  } catch (e) {
    loadError.value = e
  } finally {
    loadingCommittees.value = false
  }
}

function startCreateCommittee() {
  editingCommitteeId.value = null
  committeeForm.value = blankCommitteeForm()
  committeeErrors.value = {}
  committeeFormError.value = null
  showCommitteeForm.value = true
}

function startEditCommittee(committee) {
  editingCommitteeId.value = committee.id
  committeeForm.value = {
    name_ar: committee.name_ar,
    name_en: committee.name_en ?? '',
    description: committee.description ?? '',
    is_active: committee.is_active,
  }
  committeeErrors.value = {}
  committeeFormError.value = null
  showCommitteeForm.value = true
}

function cancelCommitteeForm() {
  showCommitteeForm.value = false
  editingCommitteeId.value = null
  committeeErrors.value = {}
  committeeFormError.value = null
}

async function saveCommittee() {
  savingCommittee.value = true
  committeeErrors.value = {}
  committeeFormError.value = null

  const payload = { ...committeeForm.value, name_en: committeeForm.value.name_en || null, description: committeeForm.value.description || null }

  try {
    if (editingCommitteeId.value === null) {
      await api.post('/committees', payload)
    } else {
      await api.put(`/committees/${editingCommitteeId.value}`, payload)
    }
    cancelCommitteeForm()
    await loadCommittees()
  } catch (e) {
    if (e?.response?.status === 422) {
      committeeErrors.value = e.response.data.errors ?? {}
      committeeFormError.value = e.response.data.message ?? null
    } else {
      committeeFormError.value = t('common.none')
    }
  } finally {
    savingCommittee.value = false
  }
}

async function toggleCommitteeActive(committee) {
  committeeFormError.value = null
  try {
    await api.patch(`/committees/${committee.id}/toggle-active`)
    await loadCommittees()
  } catch {
    committeeFormError.value = t('common.none')
  }
}

async function removeCommittee(committee) {
  if (!window.confirm(t('common.confirmDelete'))) return

  committeeFormError.value = null
  try {
    await api.delete(`/committees/${committee.id}`)
    await loadCommittees()
  } catch (e) {
    committeeFormError.value = e?.response?.data?.message ?? t('common.none')
  }
}

// --- Committee membership ---------------------------------------------------

const expandedCommitteeId = ref(null)
const memberUserId = ref('')
const memberIsHead = ref(false)
const memberError = ref(null)

function toggleMembers(committee) {
  expandedCommitteeId.value = expandedCommitteeId.value === committee.id ? null : committee.id
  memberUserId.value = ''
  memberIsHead.value = false
  memberError.value = null
}

function availableUsersFor(committee) {
  const memberIds = new Set((committee.members ?? []).map((m) => m.user.id))
  return userOptions.value.filter((u) => !memberIds.has(u.id))
}

async function addMember(committee) {
  if (!memberUserId.value) return

  memberError.value = null
  try {
    await api.post(`/committees/${committee.id}/members`, {
      user_id: memberUserId.value,
      is_head: memberIsHead.value,
    })
    memberUserId.value = ''
    memberIsHead.value = false
    await loadCommittees()
  } catch (e) {
    memberError.value = e?.response?.data?.message ?? t('common.none')
  }
}

async function removeMember(committee, member) {
  memberError.value = null
  try {
    await api.delete(`/committees/${committee.id}/members/${member.id}`)
    await loadCommittees()
  } catch (e) {
    memberError.value = e?.response?.data?.message ?? t('common.none')
  }
}

// --- Meetings ----------------------------------------------------------------
// Stage 30 — scheduling goes through the 5-step MeetingSchedulingWizard rather
// than a single inline form.

const showMeetingForm = ref(false)

async function loadMeetings() {
  loadingMeetings.value = true
  try {
    const { data } = await api.get('/meetings')
    meetings.value = data.data ?? []
  } catch (e) {
    loadError.value = e
  } finally {
    loadingMeetings.value = false
  }
}

function startScheduleMeeting() {
  showMeetingForm.value = true
}

function cancelMeetingForm() {
  showMeetingForm.value = false
}

async function onMeetingScheduled() {
  showMeetingForm.value = false
  await loadMeetings()
}

onMounted(async () => {
  await Promise.all([loadCommittees(), loadMeetings()])
})
</script>

<template>
  <section>
    <!-- Committees ------------------------------------------------------- -->
    <div class="toolbar">
      <h2>{{ t('committees.title') }}</h2>
      <button v-can="'meetings.add'" class="primary" type="button" @click="startCreateCommittee">
        + {{ t('committees.add') }}
      </button>
    </div>

    <p v-if="committeeFormError" class="alert">{{ committeeFormError }}</p>

    <form v-if="showCommitteeForm" class="card form" @submit.prevent="saveCommittee">
      <h3>{{ editingCommitteeId === null ? t('committees.add') : t('committees.edit') }}</h3>
      <div class="grid">
        <label>
          {{ t('committees.nameAr') }} *
          <input v-model="committeeForm.name_ar" type="text" required />
          <small v-if="committeeErrors.name_ar" class="field-error">{{ committeeErrors.name_ar[0] }}</small>
        </label>
        <label>
          {{ t('committees.nameEn') }}
          <input v-model="committeeForm.name_en" type="text" dir="ltr" />
        </label>
        <label class="span-2">
          {{ t('committees.description') }}
          <textarea v-model="committeeForm.description" rows="2" />
        </label>
      </div>
      <label class="checkbox">
        <input v-model="committeeForm.is_active" type="checkbox" />
        {{ t('common.active') }}
      </label>
      <div class="actions">
        <button class="primary" type="submit" :disabled="savingCommittee">
          {{ savingCommittee ? t('common.saving') : t('common.save') }}
        </button>
        <button class="ghost" type="button" @click="cancelCommitteeForm">{{ t('common.cancel') }}</button>
      </div>
    </form>

    <div class="card">
      <p v-if="loadingCommittees" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="committees.length === 0" class="state">{{ t('committees.empty') }}</p>
      <table v-else>
        <tbody>
          <template v-for="committee in committees" :key="committee.id">
            <tr :class="{ dimmed: !committee.is_active }">
              <td>
                <strong>{{ name(committee) }}</strong>
                <span v-if="!committee.is_active" class="pill">{{ t('common.inactive') }}</span>
              </td>
              <td class="meta">
                <span>{{ committee.members_count }} {{ t('committees.members') }}</span>
                <span>{{ committee.meetings_count }} {{ t('committees.meetingsHeld') }}</span>
              </td>
              <td class="row-actions">
                <button class="ghost" type="button" @click="toggleMembers(committee)">
                  {{ expandedCommitteeId === committee.id ? t('committees.hideMembers') : t('committees.manageMembers') }}
                </button>
                <button v-can="'meetings.edit'" class="ghost" type="button" @click="startEditCommittee(committee)">
                  {{ t('common.edit') }}
                </button>
                <button v-can="'meetings.edit'" class="ghost" type="button" @click="toggleCommitteeActive(committee)">
                  {{ committee.is_active ? t('common.deactivate') : t('common.activate') }}
                </button>
                <button v-can="'meetings.delete'" class="ghost danger" type="button" @click="removeCommittee(committee)">
                  {{ t('common.delete') }}
                </button>
              </td>
            </tr>
            <tr v-if="expandedCommitteeId === committee.id" class="members-row">
              <td colspan="3">
                <p v-if="memberError" class="alert">{{ memberError }}</p>
                <p v-if="!committee.members?.length" class="state">{{ t('committees.noMembers') }}</p>
                <ul v-else class="members">
                  <li v-for="member in committee.members" :key="member.id">
                    <span>{{ member.user.name }}</span>
                    <span v-if="member.is_head" class="pill">{{ t('committees.head') }}</span>
                    <button v-can="'meetings.edit'" class="ghost danger" type="button" @click="removeMember(committee, member)">
                      {{ t('committees.removeMember') }}
                    </button>
                  </li>
                </ul>
                <form v-can="'meetings.edit'" class="add-member" @submit.prevent="addMember(committee)">
                  <select v-model="memberUserId" :aria-label="t('committees.chooseUser')">
                    <option value="">{{ t('committees.chooseUser') }}</option>
                    <option v-for="user in availableUsersFor(committee)" :key="user.id" :value="user.id">
                      {{ user.name }}
                    </option>
                  </select>
                  <label class="checkbox">
                    <input v-model="memberIsHead" type="checkbox" />
                    {{ t('committees.head') }}
                  </label>
                  <button class="ghost" type="submit" :disabled="!memberUserId">{{ t('committees.addMember') }}</button>
                </form>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <!-- Meetings ----------------------------------------------------------- -->
    <div class="toolbar">
      <h2>{{ t('meetings.title') }}</h2>
      <button v-can="'meetings.add'" class="primary" type="button" @click="startScheduleMeeting">
        + {{ t('meetings.schedule') }}
      </button>
    </div>

    <MeetingSchedulingWizard
      v-if="showMeetingForm"
      :committees="committees"
      :user-options="userOptions"
      @scheduled="onMeetingScheduled"
      @cancel="cancelMeetingForm"
    />

    <div class="card">
      <p v-if="loadingMeetings" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="meetings.length === 0" class="state">{{ t('meetings.empty') }}</p>
      <table v-else>
        <tbody>
          <tr v-for="meeting in meetings" :key="meeting.id">
            <td>
              <RouterLink :to="{ name: 'meeting_details', params: { id: meeting.id } }">
                {{ meeting.title }}
              </RouterLink>
              <span class="pill status">{{ t(`meetings.status${meeting.status.charAt(0).toUpperCase()}${meeting.status.slice(1)}`) }}</span>
            </td>
            <td class="meta">{{ name(meeting.committee) }}</td>
            <td class="meta">{{ dateTime(meeting.scheduled_at) }}</td>
            <td class="meta">
              <span>{{ meeting.attendees_count }} {{ t('meetings.attendees') }}</span>
              <span>{{ meeting.agenda_items_count }} {{ t('meetings.agendaItems') }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.toolbar { display: flex; align-items: center; justify-content: space-between; margin: 1.5rem 0 1rem; }
.toolbar:first-child { margin-top: 0; }
.toolbar h2 { margin: 0; font-size: 1.05rem; color: var(--color-brand-text); }
.card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 12px; padding: 1.25rem; margin-bottom: 1rem; }
.form h3 { margin: 0 0 1rem; font-size: 1rem; color: var(--color-brand-text); }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; }
.span-2 { grid-column: 1 / -1; }
label { display: flex; flex-direction: column; gap: .3rem; font-size: .875rem; color: var(--color-black-700); }
label.checkbox { flex-direction: row; align-items: center; gap: .5rem; margin-top: 1rem; }
input[type='text'], input[type='datetime-local'], select, textarea {
  padding: .5rem .6rem;
  border: 1px solid var(--color-border-hover);
  border-radius: 8px;
  background: var(--color-surface);
  font: inherit;
}
input:focus, select:focus, textarea:focus { outline: 2px solid var(--color-brand-text); outline-offset: 1px; }
.field-error { color: var(--color-danger-fg); font-size: .78rem; }
.actions { display: flex; gap: .5rem; margin-top: 1.25rem; }

table { width: 100%; border-collapse: collapse; }
td { padding: .55rem .5rem; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
tr:last-child td { border-bottom: 0; }
tr.dimmed { opacity: .55; }
.meta { color: var(--color-muted); font-size: .78rem; white-space: nowrap; }
.meta span { margin-inline-end: .75rem; }
.row-actions { text-align: end; white-space: nowrap; }
.pill { margin-inline-start: .5rem; padding: .1rem .5rem; background: var(--color-surface-hover); color: var(--color-muted); border-radius: 999px; font-size: .72rem; }
.pill.status { background: var(--color-success-bg); color: var(--color-success-fg); }
.state { padding: .5rem; color: var(--color-muted); font-size: .9rem; margin: 0; }
.alert { padding: .65rem .8rem; background: var(--color-danger-bg); color: var(--color-danger-fg); border: 1px solid var(--color-danger-border); border-radius: 8px; font-size: .875rem; margin: 0 0 1rem; }
button { cursor: pointer; border-radius: 8px; font-size: .85rem; }
button:disabled { cursor: not-allowed; opacity: .6; }
.primary { padding: .5rem .9rem; border: 0; background: var(--color-brand); color: var(--color-on-brand); }
.ghost { padding: .35rem .6rem; border: 1px solid var(--color-border-hover); background: var(--color-surface); color: var(--color-foreground); margin-inline-start: .3rem; }
.ghost:hover { background: var(--color-surface-hover); }
.ghost.danger { color: var(--color-danger-fg); border-color: var(--color-danger-border); }

.members-row td { background: var(--color-surface-hover); }
.members { display: grid; gap: .4rem; padding: 0; margin: 0 0 .75rem; list-style: none; }
.members li { display: flex; align-items: center; gap: .5rem; font-size: .85rem; }
.add-member { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }

a { color: var(--color-brand-text); text-decoration: none; font-weight: 600; }
a:hover { text-decoration: underline; }
</style>
