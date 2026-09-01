<script setup>
/**
 * Stage 36 — the minutes lifecycle: generate a compiled draft, the head's
 * review (approve / send back with a reason), then each present attendee's
 * own signature. The last signature (or review itself, if nobody attended)
 * auto-approves — see MeetingMinutesController's docblock. Approved minutes
 * are what MeetingController::update()'s close gate now requires.
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { VOTE_OPTIONS } from '../lib/decisionOutcomes'
import api from '../lib/api'
import { useAuthStore } from '../stores/auth'
import SignaturePad from '../components/SignaturePad.vue'

const route = useRoute()
const { t, locale } = useI18n()
const auth = useAuthStore()

function dateTime(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' })
    .format(new Date(value))
}

// --- Meeting picker ----------------------------------------------------------

const meetings = ref([])
const meetingId = ref(route.query.meeting ? Number(route.query.meeting) : '')

async function loadMeetings() {
  try {
    const { data } = await api.get('/meetings')
    meetings.value = data.data ?? []
  } catch {
    meetings.value = []
  }
}

// --- Minutes doc ---------------------------------------------------------------

const minutes = ref(null)
const loading = ref(false)
const error = ref('')

async function load() {
  if (!meetingId.value) {
    minutes.value = null
    return
  }
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/meetings/${meetingId.value}/minutes`)
    minutes.value = data.data
  } catch (requestError) {
    error.value = requestError.response?.data?.message ?? t('meetingsUnit.minutes.error')
    minutes.value = null
  } finally {
    loading.value = false
  }
}

watch(meetingId, load)

// --- Generate / regenerate -----------------------------------------------------

const generating = ref(false)
const generateError = ref('')

async function generate() {
  generateError.value = ''
  generating.value = true
  try {
    const { data } = await api.post(`/meetings/${meetingId.value}/minutes/generate`)
    minutes.value = data.data
  } catch (requestError) {
    generateError.value = requestError.response?.data?.message ?? t('common.none')
  } finally {
    generating.value = false
  }
}

// --- Review (head) ---------------------------------------------------------------

const reviewBusy = ref(false)
const reviewError = ref('')
const changesComment = ref('')
const showChangesForm = ref(false)

async function review(decision) {
  reviewError.value = ''
  reviewBusy.value = true
  try {
    const payload = { decision }
    if (decision === 'changes_requested') payload.comment = changesComment.value.trim()
    const { data } = await api.post(`/meetings/${meetingId.value}/minutes/review`, payload)
    minutes.value = data.data
    changesComment.value = ''
    showChangesForm.value = false
  } catch (requestError) {
    reviewError.value = requestError.response?.data?.message ?? t('common.none')
  } finally {
    reviewBusy.value = false
  }
}

// --- Sign (each present attendee) -----------------------------------------------

const signing = ref(false)
const signError = ref('')
const signatureReady = ref(false)
let signaturePad = null
function setSignaturePad(instance) { signaturePad = instance }

const mySignature = computed(() => (minutes.value?.signatures ?? [])
  .find((signature) => signature.user.id === auth.user?.id))

async function sign() {
  signError.value = ''
  signing.value = true
  try {
    const file = await signaturePad?.toFile()
    if (!file) return
    const form = new FormData()
    form.append('signature', file)
    const { data } = await api.post(`/meetings/${meetingId.value}/minutes/sign`, form)
    minutes.value = data.data
    signatureReady.value = false
  } catch (requestError) {
    signError.value = requestError.response?.data?.message ?? t('common.none')
  } finally {
    signing.value = false
  }
}

// --- Signature image thumbnails (bearer-authenticated blobs) --------------------

const signatureImages = ref({})
async function loadSignatureImages() {
  const images = {}
  await Promise.all((minutes.value?.signatures ?? []).map(async (signature) => {
    if (!signature.signature_url) return
    try {
      const { data } = await api.get(signature.signature_url, { responseType: 'blob' })
      images[signature.id] = URL.createObjectURL(data)
    } catch {
      // Left unset — the template falls back to a "signed" label with no image.
    }
  }))
  Object.values(signatureImages.value).forEach((url) => URL.revokeObjectURL(url))
  signatureImages.value = images
}
watch(() => (minutes.value?.signatures ?? []).map((s) => `${s.id}:${s.signature_url}`).join('|'), loadSignatureImages)
onUnmounted(() => Object.values(signatureImages.value).forEach((url) => URL.revokeObjectURL(url)))

function tallyFor(item) {
  return VOTE_OPTIONS.map((outcome) => ({ outcome, count: item.votes?.[outcome] ?? 0 })).filter((v) => v.count > 0)
}

// Stage 50 — [D] Art. 28: attendee names are recorded alongside their
// committee capacity when they hold one (Stage 45's fixed 5-seat roster).
function attendeeRole(attendee) {
  if (attendee.seat) return t(`committees.seats.${attendee.seat}`)
  if (attendee.is_head) return t('committees.head')
  return ''
}

onMounted(loadMeetings)
</script>

<template>
  <section class="page">
    <h1>{{ t('meetingsUnit.minutes.title') }}</h1>
    <p class="subtitle">{{ t('meetingsUnit.minutes.subtitle') }}</p>

    <div class="card picker">
      <label>
        {{ t('meetingsUnit.minutes.chooseMeeting') }}
        <select v-model="meetingId">
          <option value="">{{ t('meetingsUnit.minutes.chooseMeetingPlaceholder') }}</option>
          <option v-for="m in meetings" :key="m.id" :value="m.id">{{ m.title }} — {{ dateTime(m.scheduled_at) }}</option>
        </select>
      </label>
    </div>

    <p v-if="!meetingId" class="state">{{ t('meetingsUnit.minutes.noMeetingSelected') }}</p>
    <p v-else-if="loading" class="state">{{ t('common.loading') }}</p>
    <div v-else-if="error" class="alert" role="alert">
      {{ error }} <button class="ghost" type="button" @click="load">{{ t('common.retry') }}</button>
    </div>

    <template v-else-if="meetingId">
      <div v-if="!minutes" class="card">
        <p class="state">{{ t('meetingsUnit.minutes.notGenerated') }}</p>
        <button v-can="'meeting_minutes.add'" class="primary" type="button" :disabled="generating" @click="generate">
          {{ generating ? t('common.saving') : t('meetingsUnit.minutes.generate') }}
        </button>
        <p v-if="generateError" class="alert">{{ generateError }}</p>
      </div>

      <template v-else>
        <div class="card status-card">
          <span class="pill" :class="minutes.status">{{ t(`meetingsUnit.minutes.status.${minutes.status}`) }}</span>
          <button
            v-if="minutes.status === 'draft'"
            v-can="'meeting_minutes.add'"
            class="ghost"
            type="button"
            :disabled="generating"
            @click="generate"
          >
            {{ generating ? t('common.saving') : t('meetingsUnit.minutes.regenerate') }}
          </button>
        </div>
        <p v-if="generateError" class="alert">{{ generateError }}</p>
        <p v-if="minutes.status === 'draft' && minutes.review_comment" class="alert warning">
          {{ t('meetingsUnit.minutes.changesRequestedNote') }}: {{ minutes.review_comment }}
        </p>

        <section class="card content">
          <h3>{{ t('meetingsUnit.minutes.sections.meetingInfo') }}</h3>
          <div class="summary">
            <div><span>{{ t('meetings.meetingTitle') }}</span><strong>{{ minutes.content.meeting.title }}</strong></div>
            <div v-if="minutes.content.meeting.meeting_number"><span>{{ t('meetings.meetingNumber') }}</span><strong>{{ minutes.content.meeting.meeting_number }}</strong></div>
            <div><span>{{ t('meetings.scheduledAt') }}</span><strong>{{ dateTime(minutes.content.meeting.scheduled_at) }}</strong></div>
            <div v-if="minutes.content.meeting.location"><span>{{ t('meetings.location') }}</span><strong>{{ minutes.content.meeting.location }}</strong></div>
            <div v-if="minutes.content.meeting.chairman"><span>{{ t('meetings.chairman') }}</span><strong>{{ minutes.content.meeting.chairman.name }}</strong></div>
            <div v-if="minutes.content.meeting.rapporteur"><span>{{ t('meetings.rapporteur') }}</span><strong>{{ minutes.content.meeting.rapporteur.name }}</strong></div>
          </div>

          <h3>{{ t('meetingsUnit.minutes.sections.attendance') }}</h3>
          <div class="attendance">
            <div>
              <strong>{{ t('meetingsUnit.minutes.sections.present') }} ({{ minutes.content.attendance.present.length }})</strong>
              <p v-if="!minutes.content.attendance.present.length" class="state">{{ t('common.none') }}</p>
              <ul v-else>
                <li v-for="u in minutes.content.attendance.present" :key="u.id">
                  {{ u.name }}<span v-if="attendeeRole(u)" class="role"> — {{ attendeeRole(u) }}</span>
                </li>
              </ul>
            </div>
            <div>
              <strong>{{ t('meetingsUnit.minutes.sections.absent') }} ({{ minutes.content.attendance.absent.length }})</strong>
              <p v-if="!minutes.content.attendance.absent.length" class="state">{{ t('common.none') }}</p>
              <ul v-else>
                <li v-for="u in minutes.content.attendance.absent" :key="u.id">
                  {{ u.name }}<span v-if="attendeeRole(u)" class="role"> — {{ attendeeRole(u) }}</span>
                </li>
              </ul>
            </div>
          </div>
          <p class="quorum" :class="minutes.content.attendance.quorum_met ? 'good' : 'bad'">
            {{ t('meetingsUnit.readiness.quorum.title') }}: {{ minutes.content.attendance.quorum_present }} / {{ minutes.content.attendance.quorum_required }}
            — {{ t(minutes.content.attendance.quorum_met ? 'meetingsUnit.readiness.quorum.met' : 'meetingsUnit.readiness.quorum.notMet') }}
          </p>

          <template v-if="minutes.content.required_signatories?.length">
            <h3>{{ t('meetingsUnit.minutes.sections.requiredSignatories') }}</h3>
            <ul class="signatories">
              <li v-for="u in minutes.content.required_signatories" :key="u.id">
                {{ u.name }}<span v-if="attendeeRole(u)" class="role"> — {{ attendeeRole(u) }}</span>
              </li>
            </ul>
          </template>

          <h3>{{ t('meetingsUnit.minutes.sections.agendaItems') }}</h3>
          <p v-if="!minutes.content.agenda_items.length" class="state">{{ t('meetings.agenda.empty') }}</p>
          <ol v-else class="agenda-items">
            <li v-for="item in minutes.content.agenda_items" :key="item.id">
              <div class="item-heading">
                <span v-if="item.reference_number" class="ref ltr">{{ item.reference_number }}</span>
                <strong>{{ item.subject }}</strong>
                <span class="pill small">{{ t(`meetings.agenda.itemType.${item.item_type}`) }}</span>
              </div>
              <p v-if="item.facts_summary" class="item-field"><strong>{{ t('meetingsUnit.minutes.item.factsSummary') }}:</strong> {{ item.facts_summary }}</p>
              <ul v-if="item.documents_reviewed?.length" class="item-field">
                <strong>{{ t('meetingsUnit.minutes.item.documentsReviewed') }}:</strong>
                <li v-for="doc in item.documents_reviewed" :key="doc.id">{{ doc.label || doc.original_name }}</li>
              </ul>
              <div v-if="tallyFor(item).length" class="tally">
                <span v-for="v in tallyFor(item)" :key="v.outcome">{{ t(`decisions.tally.${v.outcome}`) }}: {{ v.count }}</span>
              </div>
              <p v-if="item.decision" class="decision">
                {{ t(`decisions.outcome.${item.decision.outcome}`) }}
                <template v-if="item.decision.decided_by"> — {{ t('decisions.decidedBy') }} {{ item.decision.decided_by }}</template>
                <template v-if="item.decision.comment">: {{ item.decision.comment }}</template>
              </p>
              <p v-if="item.legal_basis" class="item-field"><strong>{{ t('meetingsUnit.minutes.item.legalBasis') }}:</strong> {{ item.legal_basis }}</p>
              <p v-if="item.decision?.referral_authority" class="item-field"><strong>{{ t('meetingsUnit.minutes.item.referralAuthority') }}:</strong> {{ item.decision.referral_authority }}</p>
              <ul v-if="item.dissenting_opinions?.length" class="notes">
                <strong>{{ t('meetingsUnit.minutes.item.dissentingOpinions') }}:</strong>
                <li v-for="(opinion, index) in item.dissenting_opinions" :key="index">
                  <strong>{{ opinion.user ?? t('common.none') }}</strong> ({{ t(`decisions.vote.${opinion.vote}`) }}): {{ opinion.comment }}
                </li>
              </ul>
              <ul v-if="item.discussion_notes.length" class="notes">
                <li v-for="(note, index) in item.discussion_notes" :key="index">
                  <strong>{{ note.user ?? t('common.none') }}:</strong> {{ note.note }}
                </li>
              </ul>
            </li>
          </ol>
        </section>

        <section v-if="minutes.status === 'draft'" v-can="'meeting_minutes.approve'" class="card review">
          <h3>{{ t('meetingsUnit.minutes.review.title') }}</h3>
          <div class="actions">
            <button class="primary" type="button" :disabled="reviewBusy" @click="review('approve')">
              {{ t('meetingsUnit.minutes.review.approve') }}
            </button>
            <button class="ghost" type="button" :disabled="reviewBusy" @click="showChangesForm = !showChangesForm">
              {{ t('meetingsUnit.minutes.review.requestChanges') }}
            </button>
          </div>
          <div v-if="showChangesForm" class="changes-form">
            <textarea
              v-model="changesComment"
              rows="2"
              :placeholder="t('meetingsUnit.minutes.review.reasonPlaceholder')"
              :aria-label="t('meetingsUnit.minutes.review.reasonPlaceholder')"
            />
            <button class="ghost" type="button" :disabled="reviewBusy || !changesComment.trim()" @click="review('changes_requested')">
              {{ reviewBusy ? t('common.saving') : t('meetingsUnit.minutes.review.submit') }}
            </button>
          </div>
          <p v-if="reviewError" class="alert">{{ reviewError }}</p>
        </section>

        <section v-if="minutes.status !== 'draft'" class="card signatures">
          <h3>{{ t('meetingsUnit.minutes.signatures.title') }}</h3>
          <p v-if="!minutes.signatures.length" class="state">{{ t('meetingsUnit.minutes.signatures.none') }}</p>
          <ul v-else class="signature-list">
            <li v-for="signature in minutes.signatures" :key="signature.id">
              <span>{{ signature.user.name }}</span>
              <template v-if="signature.signed_at">
                <img v-if="signatureImages[signature.id]" :src="signatureImages[signature.id]" :alt="signature.user.name">
                <span class="pill good small">{{ t('meetingsUnit.minutes.signatures.signed') }} — {{ dateTime(signature.signed_at) }}</span>
              </template>
              <span v-else class="pill small">{{ t('meetingsUnit.minutes.signatures.pending') }}</span>
            </li>
          </ul>

          <div v-if="minutes.status === 'pending_signatures' && mySignature && !mySignature.signed_at" v-can="'meeting_minutes.add'" class="sign-panel">
            <SignaturePad :ref="setSignaturePad" :disabled="signing" @change="signatureReady = $event" />
            <button class="primary" type="button" :disabled="signing || !signatureReady" @click="sign">
              {{ signing ? t('common.saving') : t('meetingsUnit.minutes.signatures.sign') }}
            </button>
            <p v-if="signError" class="alert">{{ signError }}</p>
          </div>
        </section>
      </template>
    </template>
  </section>
</template>

<style scoped>
.page { padding: 1.5rem; max-inline-size: 68rem; }
.page h1 { margin: 0 0 .3rem; color: var(--color-brand-text); font-size: clamp(1.25rem, 3vw, 1.7rem); }
.subtitle { margin: 0 0 1rem; color: var(--color-muted); font-size: .85rem; }

.card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 12px; padding: 1.1rem; margin-bottom: 1rem; }
.card h3 { margin: 1rem 0 .5rem; color: var(--color-brand-text); font-size: .95rem; }
.card h3:first-child { margin-top: 0; }
.picker label { display: flex; flex-direction: column; gap: .3rem; font-size: .875rem; color: var(--color-black-700); max-inline-size: 24rem; }
select, textarea { padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: 8px; background: var(--color-surface); color: var(--color-foreground); font: inherit; box-sizing: border-box; }
textarea { width: 100%; resize: vertical; }

.state { color: var(--color-muted); font-size: .85rem; margin: 0; }
.alert { padding: .55rem .75rem; background: var(--color-danger-bg); color: var(--color-danger-fg); border: 1px solid var(--color-danger-border); border-radius: 8px; font-size: .82rem; margin: 0 0 .75rem; }
.alert.warning { background: var(--color-warning-bg); color: var(--color-warning-fg); border-color: var(--color-warning-border); }

.status-card { display: flex; align-items: center; gap: .6rem; }
.pill { display: inline-block; padding: .25rem .7rem; border-radius: 999px; font-size: .8rem; background: var(--color-surface-hover); border: 1px solid var(--color-border); }
.pill.small { font-size: .7rem; padding: .15rem .5rem; }
.pill.draft { background: var(--color-warning-bg); color: var(--color-warning-fg); border-color: var(--color-warning-border); }
.pill.pending_signatures { background: var(--color-info-bg); color: var(--color-info-fg); border-color: var(--color-info-border); }
.pill.approved, .pill.good { background: var(--color-success-bg); color: var(--color-success-fg); border-color: var(--color-success-border); }

.summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: .75rem; margin-bottom: .5rem; }
.summary div { display: grid; gap: .2rem; }
.summary span { color: var(--color-muted); font-size: .76rem; }
.summary strong { color: var(--color-black-700); font-size: .86rem; }

.attendance { display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: 1rem; }
.attendance ul { list-style: none; margin: .25rem 0 0; padding: 0; font-size: .82rem; color: var(--color-black-700); }
.role { color: var(--color-muted); font-size: .76rem; }
.quorum { margin: .5rem 0 0; font-size: .82rem; }
.quorum.good { color: var(--color-success-fg); }
.quorum.bad { color: var(--color-danger-fg); }
.signatories { list-style: none; margin: .25rem 0 0; padding: 0; font-size: .82rem; color: var(--color-black-700); display: grid; gap: .2rem; }

.agenda-items { list-style: none; margin: 0; padding: 0; display: grid; gap: .65rem; }
.agenda-items li { padding-bottom: .65rem; border-bottom: 1px solid var(--color-border); font-size: .85rem; }
.agenda-items li:last-child { border-bottom: 0; padding-bottom: 0; }
.item-heading { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; margin-bottom: .3rem; }
.item-heading .ref { font-size: .76rem; color: var(--color-muted); }
.tally { display: flex; gap: .7rem; flex-wrap: wrap; color: var(--color-muted); font-size: .76rem; margin-bottom: .2rem; }
.decision { margin: .2rem 0; color: var(--color-brand-text); font-size: .82rem; }
.item-field { margin: .2rem 0; font-size: .8rem; color: var(--color-black-700); list-style: none; padding: 0; }
.item-field li { margin-inline-start: 1.1rem; list-style: disc; }
.notes { list-style: none; margin: .3rem 0 0; padding: 0; display: grid; gap: .2rem; font-size: .78rem; color: var(--color-black-700); }

.review .actions { display: flex; gap: .5rem; margin-bottom: .5rem; }
.changes-form { display: grid; gap: .4rem; }

.signature-list { list-style: none; margin: 0 0 .75rem; padding: 0; display: grid; gap: .5rem; }
.signature-list li { display: flex; align-items: center; gap: .6rem; font-size: .85rem; }
.signature-list img { display: block; inline-size: 6rem; block-size: 2.5rem; border: 1px solid var(--color-border); border-radius: 6px; background: #fff; object-fit: contain; }
.sign-panel { display: grid; gap: .5rem; padding-top: .5rem; border-top: 1px dashed var(--color-border-hover); }

button { cursor: pointer; border-radius: 8px; font-size: .85rem; }
button:disabled { cursor: not-allowed; opacity: .6; }
.primary { padding: .5rem .9rem; border: 0; background: var(--color-brand); color: var(--color-on-brand); }
.ghost { padding: .4rem .65rem; border: 1px solid var(--color-border-hover); background: var(--color-surface); color: var(--color-foreground); }
.ghost:hover { background: var(--color-surface-hover); }
</style>
