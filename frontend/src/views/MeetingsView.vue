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
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import AppModal from '../components/AppModal.vue'
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

/** Stage 102 — pending_confirmation (awaiting every member's acceptance) joins scheduled/completed/cancelled. */
function meetingStatusTone(status) {
  if (status === 'completed') return 'good'
  if (status === 'cancelled') return 'danger'
  if (status === 'pending_confirmation') return 'warn'
  return 'info'
}

// pending_confirmation → meetings.statusPendingConfirmation
function meetingStatusLabel(status) {
  return t(`meetings.status${status.replace(/(^|_)(\w)/g, (_, __, c) => c.toUpperCase())}`)
}

// --- Committees ------------------------------------------------------------

const committeeErrors = ref({})
const committeeFormError = ref(null)
const editingCommitteeId = ref(null)
const showCommitteeForm = ref(false)
const savingCommittee = ref(false)

// Stage 73 — [D] Appendix 65's بطاقة تعريف اللجنة. Blank means "not
// transcribed yet", which the readiness screen reports as a missing quorum
// rather than filling in a figure of its own (Appendix 64).
const CARD_FIELDS = [
  'formation_decision_number', 'formation_decision_date', 'term_note', 'legal_basis',
  'minutes_approval_body', 'voting_rights_note', 'minutes_signature_rule', 'recusal_rules',
  'quorum_type', 'quorum_count', 'quorum_numerator', 'quorum_denominator', 'quorum_comparator', 'quorum_text',
  'majority_type', 'majority_basis', 'majority_numerator', 'majority_denominator', 'majority_comparator', 'majority_text',
  'tie_break', 'tie_break_text',
]

const blankCard = () => Object.fromEntries(CARD_FIELDS.map((key) => [key, '']))

const blankCommitteeForm = () => ({ name_ar: '', name_en: '', description: '', is_active: true, rapporteur_votes: false, ...blankCard() })
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
    rapporteur_votes: committee.rapporteur_votes ?? false,
    ...Object.fromEntries(CARD_FIELDS.map((key) => [key, committee[key] ?? ''])),
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

  // An empty card field is sent as null, not '': the backend treats null as
  // "not transcribed", while '' would fail the integer/enum rules.
  const payload = {
    ...committeeForm.value,
    name_en: committeeForm.value.name_en || null,
    description: committeeForm.value.description || null,
    ...Object.fromEntries(CARD_FIELDS.map((key) => [key, committeeForm.value[key] === '' ? null : committeeForm.value[key]])),
  }

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
const memberSeat = ref('')
const memberError = ref(null)

// Stage 45 — [D] Art. 10's fixed 5-seat roster. Stage 102 — every member holds
// one (the server binds each seat to its role and derives the head from the
// chair seat), so there is no seatless option and no separate head checkbox.
const SEAT_CODES = ['chair', 'legal', 'hr_director', 'ministry_delegate', 'rapporteur']

// Looked up by id rather than held as an object, so the modal shows the
// refreshed roster after every add/remove reload.
const membersCommittee = computed(() => committees.value.find((c) => c.id === expandedCommitteeId.value) ?? null)

function toggleMembers(committee) {
  expandedCommitteeId.value = expandedCommitteeId.value === committee.id ? null : committee.id
  memberUserId.value = ''
  memberSeat.value = ''
  memberError.value = null
}

// A seatless row (the committee's creator is seated that way) can still be
// given a seat, so only people who already hold one are excluded.
function availableUsersFor(committee) {
  const seatedIds = new Set((committee.members ?? []).filter((m) => m.seat).map((m) => m.user.id))
  return userOptions.value.filter((u) => !seatedIds.has(u.id))
}

async function addMember(committee) {
  if (!memberUserId.value || !memberSeat.value) return

  memberError.value = null
  try {
    await api.post(`/committees/${committee.id}/members`, {
      user_id: memberUserId.value,
      seat: memberSeat.value,
    })
    memberUserId.value = ''
    memberSeat.value = ''
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
  <section class="page committees-meetings">
    <!-- Committees ------------------------------------------------------- -->
    <div class="heading">
      <div>
        <h2>{{ t('committees.title') }}</h2>
      </div>
      <button v-can="'meetings.add'" class="primary" type="button" @click="startCreateCommittee">
        {{ t('committees.add') }}
      </button>
    </div>

    <p v-if="committeeFormError && !showCommitteeForm" class="alert">{{ committeeFormError }}</p>

    <AppModal
      v-if="showCommitteeForm"
      :title="editingCommitteeId === null ? t('committees.add') : t('committees.edit')"
      wide
      @close="cancelCommitteeForm"
    >
      <form class="form" @submit.prevent="saveCommittee">
        <p v-if="committeeFormError" class="alert">{{ committeeFormError }}</p>
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
        <label class="checkbox">
          <input v-model="committeeForm.rapporteur_votes" type="checkbox" />
          {{ t('committees.rapporteurVotes') }}
        </label>

        <!-- Stage 73 — Appendix 65's بطاقة تعريف اللجنة. Appendix 64 forbids the
             system supplying a quorum or a majority of its own, so these are
             transcribed from the committee's قرار التشكيل and left blank when
             it has not been read yet. -->
        <fieldset class="card-fields">
          <legend>{{ t('committees.card.title') }}</legend>
          <p class="hint">{{ t('committees.card.hint') }}</p>
          <div class="grid">
            <label>
              {{ t('committees.card.formationDecisionNumber') }}
              <input v-model="committeeForm.formation_decision_number" type="text" />
            </label>
            <label>
              {{ t('committees.card.formationDecisionDate') }}
              <input v-model="committeeForm.formation_decision_date" type="date" />
            </label>
            <label>
              {{ t('committees.card.termNote') }}
              <input v-model="committeeForm.term_note" type="text" />
            </label>
            <label>
              {{ t('committees.card.minutesApprovalBody') }}
              <input v-model="committeeForm.minutes_approval_body" type="text" />
            </label>
            <label class="span-2">
              {{ t('committees.card.legalBasis') }}
              <textarea v-model="committeeForm.legal_basis" rows="2" />
            </label>
            <label class="span-2">
              {{ t('committees.card.votingRightsNote') }}
              <textarea v-model="committeeForm.voting_rights_note" rows="2" />
            </label>
            <label class="span-2">
              {{ t('committees.card.minutesSignatureRule') }}
              <textarea v-model="committeeForm.minutes_signature_rule" rows="2" />
            </label>
            <label class="span-2">
              {{ t('committees.card.recusalRules') }}
              <textarea v-model="committeeForm.recusal_rules" rows="2" />
            </label>

            <label>
              {{ t('committees.card.quorumType') }}
              <select v-model="committeeForm.quorum_type">
                <option value="">{{ t('committees.card.notRecorded') }}</option>
                <option value="count">{{ t('committees.card.types.count') }}</option>
                <option value="fraction">{{ t('committees.card.types.fraction') }}</option>
              </select>
            </label>
            <label v-if="committeeForm.quorum_type === 'count'">
              {{ t('committees.card.quorumCount') }}
              <input v-model="committeeForm.quorum_count" type="number" min="1" />
            </label>
            <template v-if="committeeForm.quorum_type === 'fraction'">
              <label>
                {{ t('committees.card.numerator') }}
                <input v-model="committeeForm.quorum_numerator" type="number" min="1" />
              </label>
              <label>
                {{ t('committees.card.denominator') }}
                <input v-model="committeeForm.quorum_denominator" type="number" min="1" />
              </label>
              <label>
                {{ t('committees.card.comparator') }}
                <select v-model="committeeForm.quorum_comparator">
                  <option value="">{{ t('committees.card.notRecorded') }}</option>
                  <option value="at_least">{{ t('committees.card.comparators.at_least') }}</option>
                  <option value="more_than">{{ t('committees.card.comparators.more_than') }}</option>
                </select>
              </label>
            </template>
            <label class="span-2">
              {{ t('committees.card.quorumText') }}
              <input v-model="committeeForm.quorum_text" type="text" />
            </label>

            <label>
              {{ t('committees.card.majorityType') }}
              <select v-model="committeeForm.majority_type">
                <option value="">{{ t('committees.card.notRecorded') }}</option>
                <option value="plurality">{{ t('committees.card.majorityTypes.plurality') }}</option>
                <option value="fraction">{{ t('committees.card.majorityTypes.fraction') }}</option>
              </select>
            </label>
            <template v-if="committeeForm.majority_type === 'fraction'">
              <label>
                {{ t('committees.card.majorityBasis') }}
                <select v-model="committeeForm.majority_basis">
                  <option value="">{{ t('committees.card.notRecorded') }}</option>
                  <option value="votes_cast">{{ t('committees.card.bases.votes_cast') }}</option>
                  <option value="present">{{ t('committees.card.bases.present') }}</option>
                  <option value="members">{{ t('committees.card.bases.members') }}</option>
                </select>
              </label>
              <label>
                {{ t('committees.card.numerator') }}
                <input v-model="committeeForm.majority_numerator" type="number" min="1" />
              </label>
              <label>
                {{ t('committees.card.denominator') }}
                <input v-model="committeeForm.majority_denominator" type="number" min="1" />
              </label>
              <label>
                {{ t('committees.card.comparator') }}
                <select v-model="committeeForm.majority_comparator">
                  <option value="">{{ t('committees.card.notRecorded') }}</option>
                  <option value="at_least">{{ t('committees.card.comparators.at_least') }}</option>
                  <option value="more_than">{{ t('committees.card.comparators.more_than') }}</option>
                </select>
              </label>
            </template>
            <label class="span-2">
              {{ t('committees.card.majorityText') }}
              <input v-model="committeeForm.majority_text" type="text" />
            </label>

            <label>
              {{ t('committees.card.tieBreak') }}
              <select v-model="committeeForm.tie_break">
                <option value="">{{ t('committees.card.notRecorded') }}</option>
                <option value="chair_casting_vote">{{ t('committees.card.tieBreaks.chair_casting_vote') }}</option>
                <option value="no_decision">{{ t('committees.card.tieBreaks.no_decision') }}</option>
              </select>
            </label>
            <label class="span-2">
              {{ t('committees.card.tieBreakText') }}
              <input v-model="committeeForm.tie_break_text" type="text" />
            </label>
          </div>
        </fieldset>

        <div class="modal-actions">
          <button class="ghost" type="button" @click="cancelCommitteeForm">{{ t('common.cancel') }}</button>
          <button class="primary" type="submit" :disabled="savingCommittee">
            {{ savingCommittee ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </form>
    </AppModal>

    <div class="card card-flat card-pad list">
      <p v-if="loadingCommittees" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="committees.length === 0" class="state">{{ t('committees.empty') }}</p>
      <table v-else class="data-table">
        <tbody>
          <template v-for="committee in committees" :key="committee.id">
            <tr :class="{ dimmed: !committee.is_active }">
              <td>
                <strong>{{ name(committee) }}</strong>
                <span v-if="!committee.is_active" class="pill">{{ t('common.inactive') }}</span>
                <!-- Stage 73 — a committee with no transcribed quorum cannot have a
                     meeting convened without an override, so the list flags it. -->
                <span v-if="committee.rules_recorded === false" class="pill warn">{{ t('committees.card.missing') }}</span>
              </td>
              <td class="meta">
                <span>{{ committee.members_count }} {{ t('committees.members') }}</span>
                <span>{{ committee.meetings_count }} {{ t('committees.meetingsHeld') }}</span>
              </td>
              <td>
                <div class="row-actions">
                  <button class="ghost" type="button" @click="toggleMembers(committee)">
                    {{ t('committees.manageMembers') }}
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
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <AppModal
      v-if="membersCommittee"
      :title="`${t('committees.manageMembers')} — ${name(membersCommittee)}`"
      @close="expandedCommitteeId = null"
    >
      <!-- Stage 45 — Art. 10's 5 named seats, each individually
           identifiable regardless of how many other unseated
           members this committee also carries. -->
      <ul class="seat-summary">
        <li v-for="seat in SEAT_CODES" :key="seat" :class="{ filled: membersCommittee.seats?.[seat] }">
          <span class="seat-label">{{ t(`committees.seats.${seat}`) }}</span>
          <span class="seat-occupant">
            {{ membersCommittee.seats?.[seat] ? membersCommittee.seats[seat].user.name : t('committees.seats.empty') }}
          </span>
        </li>
      </ul>
      <p v-if="memberError" class="alert">{{ memberError }}</p>
      <p v-if="!membersCommittee.members?.length" class="state">{{ t('committees.noMembers') }}</p>
      <ul v-else class="members">
        <li v-for="member in membersCommittee.members" :key="member.id">
          <span>{{ member.user.name }}</span>
          <span v-if="member.seat" class="pill">{{ t(`committees.seats.${member.seat}`) }}</span>
          <span v-else-if="member.is_head" class="pill">{{ t('committees.head') }}</span>
          <button v-can="'meetings.edit'" class="ghost danger" type="button" @click="removeMember(membersCommittee, member)">
            {{ t('committees.removeMember') }}
          </button>
        </li>
      </ul>
      <form v-can="'meetings.edit'" class="add-member" @submit.prevent="addMember(membersCommittee)">
        <select v-model="memberUserId" :aria-label="t('committees.chooseUser')">
          <option value="">{{ t('committees.chooseUser') }}</option>
          <option v-for="user in availableUsersFor(membersCommittee)" :key="user.id" :value="user.id">
            {{ user.name }}
          </option>
        </select>
        <select v-model="memberSeat" required :aria-label="t('committees.seats.label')">
          <option value="">{{ t('committees.seats.choose') }}</option>
          <option v-for="seat in SEAT_CODES" :key="seat" :value="seat" :disabled="Boolean(membersCommittee.seats?.[seat])">
            {{ t(`committees.seats.${seat}`) }}
          </option>
        </select>
        <button class="ghost" type="submit" :disabled="!memberUserId || !memberSeat">{{ t('committees.addMember') }}</button>
      </form>
      <div class="modal-actions">
        <button class="ghost" type="button" @click="expandedCommitteeId = null">{{ t('common.close') }}</button>
      </div>
    </AppModal>

    <!-- Meetings ----------------------------------------------------------- -->
    <div class="heading">
      <div>
        <h2>{{ t('meetings.title') }}</h2>
      </div>
      <button v-can="'meetings.add'" class="primary" type="button" @click="startScheduleMeeting">
        {{ t('meetings.schedule') }}
      </button>
    </div>

    <AppModal v-if="showMeetingForm" :title="t('meetings.schedule')" wide @close="cancelMeetingForm">
      <MeetingSchedulingWizard
        :committees="committees"
        @scheduled="onMeetingScheduled"
        @cancel="cancelMeetingForm"
      />
    </AppModal>

    <div class="card card-flat card-pad list">
      <p v-if="loadingMeetings" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="meetings.length === 0" class="state">{{ t('meetings.empty') }}</p>
      <table v-else class="data-table">
        <tbody>
          <tr v-for="meeting in meetings" :key="meeting.id">
            <td>
              <RouterLink :to="{ name: 'meeting_details', params: { id: meeting.id } }">
                {{ meeting.title }}
              </RouterLink>
              <span class="pill" :class="meetingStatusTone(meeting.status)">
                {{ meetingStatusLabel(meeting.status) }}
              </span>
            </td>
            <td class="meta">{{ name(meeting.committee) }}</td>
            <td class="meta nowrap">{{ dateTime(meeting.scheduled_at) }}</td>
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
.heading:not(:first-child) { margin-top: var(--space-6); }
.list { margin-bottom: var(--space-4); overflow-x: auto; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); gap: var(--space-4); }
.span-2 { grid-column: 1 / -1; }
.card-fields { margin: var(--space-5) 0 0; padding: var(--space-4); border: 1px dashed var(--color-border-hover); border-radius: var(--radius-lg); }
.card-fields legend { padding: 0 .4rem; font-size: var(--text-sm); color: var(--color-brand-text); }
.card-fields .hint { margin: 0 0 var(--space-3); line-height: 1.6; }
label { display: flex; flex-direction: column; gap: .3rem; font-size: var(--text-base); color: var(--color-black-700); }
label.checkbox { flex-direction: row; align-items: center; gap: .5rem; margin-top: var(--space-4); }
input[type='text'], input[type='datetime-local'], select, textarea {
  padding: .5rem .6rem;
  border: 1px solid var(--color-border-hover);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
  font: inherit;
}
input:focus, select:focus, textarea:focus { outline: 2px solid var(--color-brand-text); outline-offset: 1px; }
.field-error { color: var(--color-danger-fg); font-size: var(--text-sm); }

.data-table tr.dimmed { opacity: .55; }
.meta { color: var(--color-muted); font-size: var(--text-sm); white-space: nowrap; }
.meta span { margin-inline-end: var(--space-3); }
.nowrap { white-space: nowrap; }
.row-actions { display: flex; flex-wrap: wrap; gap: var(--space-2); justify-content: flex-end; }
.pill { margin-inline-start: var(--space-2); }

.seat-summary { display: flex; flex-wrap: wrap; gap: var(--space-2); padding: 0; margin: 0 0 var(--space-3); list-style: none; }
.seat-summary li { display: flex; flex-direction: column; gap: .15rem; padding: .35rem .6rem; border: 1px dashed var(--color-border-hover); border-radius: var(--radius-lg); font-size: var(--text-xs); min-inline-size: 7rem; }
.seat-summary li.filled { border-style: solid; border-color: var(--color-success-border); background: var(--color-success-bg); }
.seat-summary .seat-label { color: var(--color-muted); }
.seat-summary li.filled .seat-occupant { color: var(--color-success-fg); font-weight: 600; }
.members { display: grid; gap: .4rem; padding: 0; margin: 0 0 var(--space-3); list-style: none; }
.members li { display: flex; align-items: center; gap: var(--space-2); font-size: var(--text-base); }
.add-member { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }

a { color: var(--color-brand-text); text-decoration: none; font-weight: 600; }
a:hover { text-decoration: underline; }
</style>
