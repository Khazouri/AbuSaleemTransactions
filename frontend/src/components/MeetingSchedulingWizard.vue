<script setup>
// Stage 30 — meeting scheduling wizard: requests → details → members/invitations
// → review/agenda → approve/schedule. Everything before the final step lives
// client-side only; the API calls only fire once the user confirms on step 5
// (create → add agenda items), the same one-call-per-REST-action shape the rest
// of this screen already uses.
//
// Stage 102 — the مقرر's monthly meeting: requests come only from the
// committee's pending list, the invitees are exactly the five seats (no
// extras), and the type, chair and rapporteur are the server's to derive.
// Creating the meeting already notifies the seats, so there is no separate
// send-invitations call.
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'

const SEAT_CODES = ['chair', 'legal', 'hr_director', 'ministry_delegate', 'rapporteur']

const props = defineProps({
  committees: { type: Array, required: true },
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

async function loadCandidates() {
  requestSearching.value = true
  try {
    const { data } = await api.get('/committee-candidates', {
      params: { search: requestSearch.value.trim() || undefined, per_page: 50 },
    })
    requestResults.value = data.data ?? []
  } catch {
    requestResults.value = []
  } finally {
    requestSearching.value = false
  }
}

function onRequestSearchInput() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(loadCandidates, 300)
}

onMounted(loadCandidates)

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
  title: '',
  scheduled_at: '',
  location: '',
  expected_duration_minutes: '',
  agenda_deadline: '',
  description: '',
})
const detailErrors = ref({})

const selectedCommittee = computed(
  () => props.committees.find((c) => c.id === details.value.committee_id) ?? null,
)

const step2Valid = computed(() =>
  Boolean(details.value.committee_id && details.value.title && details.value.scheduled_at))

// --- Step 3: the five seats ---------------------------------------------------

const seats = computed(() => selectedCommittee.value?.seats ?? {})
const allSeatsFilled = computed(() => SEAT_CODES.every((seat) => seats.value[seat]))

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
      title: details.value.title,
      scheduled_at: details.value.scheduled_at,
      location: details.value.location || null,
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
  <div class="wizard">
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
      <p class="hint">{{ t('meetings.wizard.pendingListHint') }}</p>
      <label>
        {{ t('meetings.wizard.searchRequests') }}
        <input
          v-model="requestSearch"
          type="text"
          :placeholder="t('meetings.wizard.searchRequests')"
          @input="onRequestSearchInput"
        />
      </label>
      <ul class="results">
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
        <!-- Stage 70 — the meeting number is no longer typed here: the
             server mints [D] Appendix 15's PM-MTG code on creation, so the
             wizard shows the rule instead of an input the API would ignore. -->
        <label class="readonly-note">
          {{ t('meetings.meetingNumber') }}
          <small>{{ t('meetings.meetingNumberAuto') }}</small>
        </label>
        <label>
          {{ t('meetings.meetingTitle') }} *
          <input v-model="details.title" type="text" required />
          <small v-if="detailErrors.title" class="field-error">{{ t('meetings.meetingTitle') }}</small>
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
      <template v-else>
        <ul class="selected">
          <li v-for="seat in SEAT_CODES" :key="seat">
            <span class="pill">{{ t(`committees.seats.${seat}`) }}</span>
            <span>{{ seats[seat]?.user?.name ?? t('committees.seats.empty') }}</span>
          </li>
        </ul>
        <p class="hint">{{ t('meetings.wizard.acceptanceHint') }}</p>
        <p v-if="!allSeatsFilled" class="alert warning">{{ t('meetings.wizard.seatsIncomplete') }}</p>
      </template>
    </section>

    <!-- Step 4: review & agenda order -------------------------------------------- -->
    <section v-else-if="step === 4" class="step-body">
      <h4>{{ t('meetings.wizard.reviewDetails') }}</h4>
      <div class="summary">
        <div><span>{{ t('meetings.committee') }}</span><strong>{{ name(selectedCommittee) }}</strong></div>
        <div><span>{{ t('meetings.meetingTitle') }}</span><strong>{{ details.title }}</strong></div>
        <div><span>{{ t('meetings.scheduledAt') }}</span><strong class="ltr">{{ details.scheduled_at || t('common.none') }}</strong></div>
        <div v-if="details.location"><span>{{ t('meetings.location') }}</span><strong>{{ details.location }}</strong></div>
      </div>

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
      <div class="summary">
        <div><span>{{ t('meetings.committee') }}</span><strong>{{ name(selectedCommittee) }}</strong></div>
        <div><span>{{ t('meetings.meetingTitle') }}</span><strong>{{ details.title }}</strong></div>
        <div><span>{{ t('meetings.scheduledAt') }}</span><strong class="ltr">{{ details.scheduled_at || t('common.none') }}</strong></div>
      </div>
      <p class="counts">
        {{ t('meetings.wizard.selectedRequests') }}: {{ selectedRequests.length }}
      </p>
      <p v-if="submitError" class="alert warning">{{ submitError }}</p>
      <p v-if="submitting" class="state">{{ submitPhase }}</p>
    </section>

    <div class="actions">
      <button v-if="step > 1" class="ghost" type="button" :disabled="submitting" @click="goBack">
        {{ t('meetings.wizard.back') }}
      </button>
      <button v-if="step < TOTAL_STEPS" class="primary" type="button" @click="goNext">
        {{ t('meetings.wizard.next') }}
      </button>
      <button v-else class="primary" type="button" :disabled="submitting || !allSeatsFilled" @click="submit">
        {{ submitting ? t('meetings.wizard.scheduling') : t('meetings.wizard.schedule') }}
      </button>
      <button class="ghost" type="button" :disabled="submitting" @click="emit('cancel')">
        {{ t('meetings.wizard.cancel') }}
      </button>
    </div>
  </div>
</template>

<style scoped>
/* The step strip and step-scoped form layout are this wizard's own; the
   buttons/alert/state/pill/summary primitives all come from the global
   sheet. */
.steps {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin-bottom: 0.25rem;
}
.step-pill {
  padding: 0.25rem 0.6rem;
  border-radius: var(--radius-full);
  background: var(--color-surface-hover);
  color: var(--color-muted);
  font-size: var(--text-xs);
}
.step-pill.active {
  background: var(--color-brand);
  color: var(--color-on-brand);
}
.step-pill.done {
  background: var(--color-success-bg);
  color: var(--color-success-fg);
}
.step-label {
  margin: 0 0 var(--space-4);
  color: var(--color-muted);
  font-size: var(--text-xs);
}
.step-body h4 {
  margin: var(--space-4) 0 var(--space-2);
  color: var(--color-brand-text);
  font-size: var(--text-lg);
}
.step-body h4:first-child {
  margin-top: 0;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr));
  gap: var(--space-4);
}
.span-2 {
  grid-column: 1 / -1;
}
label {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  font-size: var(--text-base);
  color: var(--color-black-700);
}
input[type='text'],
input[type='number'],
input[type='datetime-local'],
select,
textarea {
  padding: 0.5rem 0.6rem;
  border: 1px solid var(--color-border-hover);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
  color: var(--color-foreground);
  font: inherit;
}
input:focus,
select:focus,
textarea:focus {
  outline: 2px solid var(--color-brand-text);
  outline-offset: 1px;
}
.readonly-note small {
  color: var(--color-muted);
  font-size: var(--text-xs);
}

.results,
.selected {
  display: grid;
  gap: 0.4rem;
  padding: 0;
  margin: var(--space-2) 0 var(--space-4);
  list-style: none;
}
.results li,
.selected li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  font-size: var(--text-sm);
}
.ref {
  color: var(--color-muted);
  font-size: var(--text-xs);
  margin-inline-end: var(--space-2);
}
.item-actions {
  white-space: nowrap;
}
.counts {
  color: var(--color-muted);
  font-size: var(--text-sm);
}

.hint {
  margin: 0 0 var(--space-2);
  color: var(--color-muted);
  font-size: var(--text-sm);
}
.ltr {
  direction: ltr;
  unicode-bidi: embed;
}

.actions {
  margin-top: var(--space-5);
}
</style>
