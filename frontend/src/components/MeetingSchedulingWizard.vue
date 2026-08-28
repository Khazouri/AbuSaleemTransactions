<script setup>
// Stage 30 — meeting scheduling wizard: requests → details → members/invitations
// → review/agenda → approve/schedule. Everything before the final step lives
// client-side only; the API calls only fire once the user confirms on step 5
// (create → add agenda items → invite extras → send invitations), the same
// one-call-per-REST-action shape the rest of this screen already uses.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'

const props = defineProps({
  committees: { type: Array, required: true },
  userOptions: { type: Array, required: true },
})
const emit = defineEmits(['scheduled', 'cancel'])

const { t, locale } = useI18n()

const name = (item) => {
  if (!item) return t('common.none')
  return locale.value === 'ar' ? item.name_ar || item.name_en : item.name_en || item.name_ar
}

const activeCommittees = computed(() => props.committees.filter((c) => c.is_active))

const step = ref(1)
const TOTAL_STEPS = 5

// --- Step 1: candidate requests ---------------------------------------------

const requestSearch = ref('')
const requestResults = ref([])
const requestSearching = ref(false)
let searchTimer = null

const selectedRequests = ref([]) // [{id, reference_number, title}]
const selectedRequestIds = computed(() => new Set(selectedRequests.value.map((r) => r.id)))

function onRequestSearchInput() {
  clearTimeout(searchTimer)
  if (!requestSearch.value.trim()) {
    requestResults.value = []
    return
  }
  searchTimer = setTimeout(async () => {
    requestSearching.value = true
    try {
      const { data } = await api.get('/requests', { params: { search: requestSearch.value.trim(), per_page: 5 } })
      requestResults.value = data.data ?? []
    } catch {
      requestResults.value = []
    } finally {
      requestSearching.value = false
    }
  }, 300)
}

function addRequest(request) {
  if (selectedRequestIds.value.has(request.id)) return
  selectedRequests.value.push(request)
}

function removeRequest(request) {
  selectedRequests.value = selectedRequests.value.filter((r) => r.id !== request.id)
}

// --- Step 2: meeting details -------------------------------------------------

const details = ref({
  committee_id: '',
  meeting_number: '',
  title: '',
  meeting_type: 'regular',
  scheduled_at: '',
  location: '',
  chairman_user_id: '',
  rapporteur_user_id: '',
  expected_duration_minutes: '',
  agenda_deadline: '',
  description: '',
})
const detailErrors = ref({})

const selectedCommittee = computed(
  () => props.committees.find((c) => c.id === details.value.committee_id) ?? null,
)

const step2Valid = computed(() =>
  Boolean(details.value.committee_id && details.value.title && details.value.meeting_type && details.value.scheduled_at))

// --- Step 3: members & invitations -------------------------------------------

const committeeMembers = computed(() => selectedCommittee.value?.members ?? [])
const extraInviteeId = ref('')
const extraInvitees = ref([]) // [{id, name}]
const extraInviteeIds = computed(() => new Set(extraInvitees.value.map((u) => u.id)))

const availableExtraInvitees = computed(() => {
  const memberIds = new Set(committeeMembers.value.map((m) => m.user.id))
  return props.userOptions.filter((u) => !memberIds.has(u.id) && !extraInviteeIds.value.has(u.id))
})

function addExtraInvitee() {
  if (!extraInviteeId.value) return
  const user = props.userOptions.find((u) => u.id === Number(extraInviteeId.value))
  if (user) extraInvitees.value.push(user)
  extraInviteeId.value = ''
}

function removeExtraInvitee(user) {
  extraInvitees.value = extraInvitees.value.filter((u) => u.id !== user.id)
}

// --- Step 4: review & agenda order -------------------------------------------

function moveRequest(index, direction) {
  const target = index + direction
  if (target < 0 || target >= selectedRequests.value.length) return
  const items = [...selectedRequests.value]
  ;[items[index], items[target]] = [items[target], items[index]]
  selectedRequests.value = items
}

// --- Step 5: approve & schedule -----------------------------------------------

const submitting = ref(false)
const submitPhase = ref('')
const submitError = ref('')

async function submit() {
  submitting.value = true
  submitError.value = ''

  try {
    submitPhase.value = t('meetings.wizard.creatingMeeting')
    const payload = {
      committee_id: details.value.committee_id,
      meeting_number: details.value.meeting_number || null,
      title: details.value.title,
      meeting_type: details.value.meeting_type,
      scheduled_at: details.value.scheduled_at,
      location: details.value.location || null,
      chairman_user_id: details.value.chairman_user_id || null,
      rapporteur_user_id: details.value.rapporteur_user_id || null,
      expected_duration_minutes: details.value.expected_duration_minutes || null,
      agenda_deadline: details.value.agenda_deadline || null,
      description: details.value.description || null,
    }
    const { data: created } = await api.post('/meetings', payload)
    const meetingId = created.data.id

    if (selectedRequests.value.length) {
      submitPhase.value = t('meetings.wizard.addingAgenda')
      for (const request of selectedRequests.value) {
        await api.post(`/meetings/${meetingId}/agenda`, { request_id: request.id })
      }
    }

    if (extraInvitees.value.length) {
      submitPhase.value = t('meetings.wizard.invitingAttendees')
      for (const user of extraInvitees.value) {
        await api.post(`/meetings/${meetingId}/attendees`, { user_id: user.id })
      }
    }

    submitPhase.value = t('meetings.wizard.notifying')
    await api.post(`/meetings/${meetingId}/send-invitations`)

    emit('scheduled', meetingId)
  } catch (e) {
    submitError.value = e?.response?.data?.message ?? t('common.none')
  } finally {
    submitting.value = false
    submitPhase.value = ''
  }
}

function goNext() {
  if (step.value === 2 && !step2Valid.value) {
    detailErrors.value = {
      committee_id: !details.value.committee_id,
      title: !details.value.title,
      scheduled_at: !details.value.scheduled_at,
    }
    return
  }
  detailErrors.value = {}
  step.value = Math.min(TOTAL_STEPS, step.value + 1)
}

function goBack() {
  step.value = Math.max(1, step.value - 1)
}
</script>

<template>
  <div class="wizard card">
    <div class="steps">
      <span
        v-for="n in TOTAL_STEPS"
        :key="n"
        class="step-pill"
        :class="{ active: step === n, done: step > n }"
      >
        {{ n }}. {{ t(`meetings.wizard.step${n}`) }}
      </span>
    </div>
    <p class="step-label">{{ t('meetings.wizard.stepLabel') }} {{ step }} {{ t('meetings.wizard.of') }} {{ TOTAL_STEPS }}</p>

    <!-- Step 1: candidate requests ------------------------------------------ -->
    <section v-if="step === 1" class="step-body">
      <label>
        {{ t('meetings.wizard.searchRequests') }}
        <input
          v-model="requestSearch"
          type="text"
          :placeholder="t('meetings.wizard.searchRequests')"
          @input="onRequestSearchInput"
        />
      </label>
      <ul v-if="requestSearch.trim()" class="results">
        <li v-if="requestSearching" class="state">{{ t('common.loading') }}</li>
        <template v-else>
          <li v-if="!requestResults.length" class="state">{{ t('meetings.wizard.noResults') }}</li>
          <li v-for="result in requestResults" :key="result.id" class="result">
            <span class="ref ltr">{{ result.reference_number || `#${result.id}` }}</span>
            <span>{{ result.title }}</span>
            <button class="ghost" type="button" :disabled="selectedRequestIds.has(result.id)" @click="addRequest(result)">
              {{ t('meetings.wizard.addRequest') }}
            </button>
          </li>
        </template>
      </ul>

      <h4>{{ t('meetings.wizard.selectedRequests') }}</h4>
      <p v-if="!selectedRequests.length" class="state">{{ t('meetings.wizard.noRequestsSelected') }}</p>
      <ul v-else class="selected">
        <li v-for="r in selectedRequests" :key="r.id">
          <span class="ref ltr">{{ r.reference_number || `#${r.id}` }}</span>
          <span>{{ r.title }}</span>
          <button class="ghost danger" type="button" @click="removeRequest(r)">{{ t('meetings.wizard.removeRequest') }}</button>
        </li>
      </ul>
    </section>

    <!-- Step 2: meeting details ----------------------------------------------- -->
    <section v-else-if="step === 2" class="step-body">
      <div class="grid">
        <label>
          {{ t('meetings.committee') }} *
          <select v-model="details.committee_id" required>
            <option value="">{{ t('meetings.chooseCommittee') }}</option>
            <option v-for="committee in activeCommittees" :key="committee.id" :value="committee.id">
              {{ name(committee) }}
            </option>
          </select>
          <small v-if="detailErrors.committee_id" class="field-error">{{ t('meetings.chooseCommittee') }}</small>
        </label>
        <label>
          {{ t('meetings.meetingNumber') }}
          <input v-model="details.meeting_number" type="text" />
        </label>
        <label>
          {{ t('meetings.meetingTitle') }} *
          <input v-model="details.title" type="text" required />
          <small v-if="detailErrors.title" class="field-error">{{ t('meetings.meetingTitle') }}</small>
        </label>
        <label>
          {{ t('meetings.meetingType') }} *
          <select v-model="details.meeting_type" required>
            <option value="regular">{{ t('meetings.typeRegular') }}</option>
            <option value="extraordinary">{{ t('meetings.typeExtraordinary') }}</option>
            <option value="emergency">{{ t('meetings.typeEmergency') }}</option>
          </select>
        </label>
        <label>
          {{ t('meetings.scheduledAt') }} *
          <input v-model="details.scheduled_at" type="datetime-local" required />
          <small v-if="detailErrors.scheduled_at" class="field-error">{{ t('meetings.scheduledAt') }}</small>
        </label>
        <label>
          {{ t('meetings.location') }}
          <input v-model="details.location" type="text" />
        </label>
        <label>
          {{ t('meetings.chairman') }}
          <select v-model="details.chairman_user_id">
            <option value="">{{ t('meetings.chooseUserOptional') }}</option>
            <option v-for="user in userOptions" :key="user.id" :value="user.id">{{ user.name }}</option>
          </select>
        </label>
        <label>
          {{ t('meetings.rapporteur') }}
          <select v-model="details.rapporteur_user_id">
            <option value="">{{ t('meetings.chooseUserOptional') }}</option>
            <option v-for="user in userOptions" :key="user.id" :value="user.id">{{ user.name }}</option>
          </select>
        </label>
        <label>
          {{ t('meetings.expectedDuration') }}
          <input v-model="details.expected_duration_minutes" type="number" min="1" />
        </label>
        <label>
          {{ t('meetings.agendaDeadline') }}
          <input v-model="details.agenda_deadline" type="datetime-local" />
        </label>
        <label class="span-2">
          {{ t('meetings.description') }}
          <textarea v-model="details.description" rows="2" />
        </label>
      </div>
    </section>

    <!-- Step 3: members & invitations ------------------------------------------ -->
    <section v-else-if="step === 3" class="step-body">
      <h4>{{ t('meetings.wizard.committeeMembers') }}</h4>
      <p v-if="!selectedCommittee" class="state">{{ t('meetings.wizard.noCommitteeSelected') }}</p>
      <p v-else-if="!committeeMembers.length" class="state">{{ t('committees.noMembers') }}</p>
      <ul v-else class="selected">
        <li v-for="member in committeeMembers" :key="member.id">
          <span>{{ member.user.name }}</span>
          <span v-if="member.is_head" class="pill">{{ t('committees.head') }}</span>
        </li>
      </ul>

      <h4>{{ t('meetings.wizard.extraInvitees') }}</h4>
      <p v-if="!extraInvitees.length" class="state">{{ t('meetings.wizard.noExtraInvitees') }}</p>
      <ul v-else class="selected">
        <li v-for="user in extraInvitees" :key="user.id">
          <span>{{ user.name }}</span>
          <button class="ghost danger" type="button" @click="removeExtraInvitee(user)">{{ t('meetings.wizard.removeInvitee') }}</button>
        </li>
      </ul>
      <form class="add-member" @submit.prevent="addExtraInvitee">
        <select v-model="extraInviteeId" :aria-label="t('meetings.wizard.chooseInvitee')">
          <option value="">{{ t('meetings.wizard.chooseInvitee') }}</option>
          <option v-for="user in availableExtraInvitees" :key="user.id" :value="user.id">{{ user.name }}</option>
        </select>
        <button class="ghost" type="submit" :disabled="!extraInviteeId">{{ t('meetings.wizard.addInvitee') }}</button>
      </form>
    </section>

    <!-- Step 4: review & agenda order -------------------------------------------- -->
    <section v-else-if="step === 4" class="step-body">
      <h4>{{ t('meetings.wizard.reviewDetails') }}</h4>
      <dl class="summary">
        <div><dt>{{ t('meetings.committee') }}</dt><dd>{{ name(selectedCommittee) }}</dd></div>
        <div><dt>{{ t('meetings.meetingTitle') }}</dt><dd>{{ details.title }}</dd></div>
        <div><dt>{{ t('meetings.meetingType') }}</dt><dd>{{ t(`meetings.type${details.meeting_type.charAt(0).toUpperCase()}${details.meeting_type.slice(1)}`) }}</dd></div>
        <div><dt>{{ t('meetings.scheduledAt') }}</dt><dd class="ltr">{{ details.scheduled_at || t('common.none') }}</dd></div>
        <div v-if="details.location"><dt>{{ t('meetings.location') }}</dt><dd>{{ details.location }}</dd></div>
      </dl>

      <h4>{{ t('meetings.wizard.reviewAgenda') }}</h4>
      <p v-if="!selectedRequests.length" class="state">{{ t('meetings.wizard.noRequestsSelected') }}</p>
      <ol v-else class="selected ordered">
        <li v-for="(r, index) in selectedRequests" :key="r.id">
          <span class="ref ltr">{{ r.reference_number || `#${r.id}` }}</span>
          <span>{{ r.title }}</span>
          <span class="item-actions">
            <button class="ghost" type="button" :disabled="index === 0" @click="moveRequest(index, -1)">↑</button>
            <button class="ghost" type="button" :disabled="index === selectedRequests.length - 1" @click="moveRequest(index, 1)">↓</button>
          </span>
        </li>
      </ol>
    </section>

    <!-- Step 5: approve & schedule ---------------------------------------------- -->
    <section v-else class="step-body">
      <h4>{{ t('meetings.wizard.reviewDetails') }}</h4>
      <dl class="summary">
        <div><dt>{{ t('meetings.committee') }}</dt><dd>{{ name(selectedCommittee) }}</dd></div>
        <div><dt>{{ t('meetings.meetingTitle') }}</dt><dd>{{ details.title }}</dd></div>
        <div><dt>{{ t('meetings.scheduledAt') }}</dt><dd class="ltr">{{ details.scheduled_at || t('common.none') }}</dd></div>
      </dl>
      <p class="counts">
        {{ t('meetings.wizard.selectedRequests') }}: {{ selectedRequests.length }}
        · {{ t('meetings.wizard.extraInvitees') }}: {{ extraInvitees.length }}
      </p>
      <p v-if="submitError" class="alert">{{ submitError }}</p>
      <p v-if="submitting" class="state">{{ submitPhase }}</p>
    </section>

    <div class="actions">
      <button v-if="step > 1" class="ghost" type="button" :disabled="submitting" @click="goBack">
        {{ t('meetings.wizard.back') }}
      </button>
      <button v-if="step < TOTAL_STEPS" class="primary" type="button" @click="goNext">
        {{ t('meetings.wizard.next') }}
      </button>
      <button v-else class="primary" type="button" :disabled="submitting" @click="submit">
        {{ submitting ? t('meetings.wizard.scheduling') : t('meetings.wizard.schedule') }}
      </button>
      <button class="ghost" type="button" :disabled="submitting" @click="emit('cancel')">
        {{ t('meetings.wizard.cancel') }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.wizard { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 12px; padding: 1.25rem; margin-bottom: 1rem; }
.steps { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: .25rem; }
.step-pill { padding: .25rem .6rem; border-radius: 999px; background: var(--color-surface-hover); color: var(--color-muted); font-size: .74rem; }
.step-pill.active { background: var(--color-brand); color: var(--color-on-brand); }
.step-pill.done { background: var(--color-success-bg); color: var(--color-success-fg); }
.step-label { margin: 0 0 1rem; color: var(--color-muted); font-size: .78rem; }
.step-body h4 { margin: 1rem 0 .5rem; color: var(--color-brand-text); font-size: .92rem; }
.step-body h4:first-child { margin-top: 0; }

.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; }
.span-2 { grid-column: 1 / -1; }
label { display: flex; flex-direction: column; gap: .3rem; font-size: .875rem; color: var(--color-black-700); }
input[type='text'], input[type='number'], input[type='datetime-local'], select, textarea {
  padding: .5rem .6rem;
  border: 1px solid var(--color-border-hover);
  border-radius: 8px;
  background: var(--color-surface);
  color: var(--color-foreground);
  font: inherit;
}
input:focus, select:focus, textarea:focus { outline: 2px solid var(--color-brand-text); outline-offset: 1px; }
.field-error { color: var(--color-danger-fg); font-size: .78rem; }

.results, .selected { display: grid; gap: .4rem; padding: 0; margin: .5rem 0 1rem; list-style: none; }
.selected.ordered { list-style: none; }
.results li, .selected li { display: flex; align-items: center; justify-content: space-between; gap: .5rem; font-size: .84rem; }
.ref { color: var(--color-muted); font-size: .78rem; margin-inline-end: .5rem; }
.item-actions { white-space: nowrap; }
.pill { padding: .1rem .5rem; background: var(--color-surface-hover); color: var(--color-muted); border-radius: 999px; font-size: .72rem; }
.state { color: var(--color-muted); font-size: .85rem; margin: 0; }
.alert { padding: .65rem .8rem; background: var(--color-danger-bg); color: var(--color-danger-fg); border: 1px solid var(--color-danger-border); border-radius: 8px; font-size: .875rem; margin: 0 0 .75rem; }

.summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: .75rem; margin: 0 0 1rem; }
.summary dt { color: var(--color-muted); font-size: .76rem; margin: 0; }
.summary dd { margin: .1rem 0 0; color: var(--color-black-700); font-size: .88rem; }
.counts { color: var(--color-muted); font-size: .85rem; }

.add-member { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
.ltr { direction: ltr; unicode-bidi: embed; }

.actions { display: flex; gap: .5rem; margin-top: 1.25rem; }
button { cursor: pointer; border-radius: 8px; font-size: .85rem; }
button:disabled { cursor: not-allowed; opacity: .6; }
.primary { padding: .5rem .9rem; border: 0; background: var(--color-brand); color: var(--color-on-brand); }
.ghost { padding: .35rem .6rem; border: 1px solid var(--color-border-hover); background: var(--color-surface); color: var(--color-foreground); }
.ghost:hover { background: var(--color-surface-hover); }
.ghost.danger { color: var(--color-danger-fg); border-color: var(--color-danger-border); }
</style>
