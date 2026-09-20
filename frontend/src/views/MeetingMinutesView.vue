<script setup>
/**
 * Stage 36 — the minutes lifecycle: generate a compiled draft, the head's
 * review (approve / send back with a reason), then each present attendee's
 * own confirmation. The last confirmation (or review itself, if nobody
 * attended) auto-approves — see MeetingMinutesController's docblock. Approved
 * minutes are what MeetingController::update()'s close gate now requires.
 *
 * Signatures have been removed from the system — an attendee's sign-off is
 * now a plain confirmation click, not a drawn/uploaded image.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { VOTE_OPTIONS } from '../lib/decisionOutcomes'
import { DEFERRAL_FIELDS } from '../lib/decisionStructure'
import api from '../lib/api'
import { MINUTES_QUALITY_CHECKS, MINUTES_REVIEWER_CHECK } from '../lib/controlGates'
import { useAuthStore } from '../stores/auth'

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

// Stage 78 — approving is also [D] Appendix 63's بوابة 3. Fourteen of
// Appendix 8's sixteen controls are derived server-side and the sixteenth is
// enforced by the signature lifecycle, so only this one is asked here.
const noInternalContradictions = ref(false)
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
    if (decision === 'approve') {
      payload.quality_checks = { [MINUTES_REVIEWER_CHECK]: noInternalContradictions.value }
    }
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
// Signatures have been removed from the system — signing is a plain
// confirmation click, so the button just pauses to ask "are you sure?".
const pendingSign = ref(false)

const mySignature = computed(() => (minutes.value?.signatures ?? [])
  .find((signature) => signature.user.id === auth.user?.id))

function openSignConfirm() {
  pendingSign.value = true
}

function closeSignConfirm() {
  if (signing.value) return
  pendingSign.value = false
}

async function sign() {
  signError.value = ''
  signing.value = true
  try {
    const { data } = await api.post(`/meetings/${meetingId.value}/minutes/sign`)
    minutes.value = data.data
    pendingSign.value = false
  } catch (requestError) {
    signError.value = requestError.response?.data?.message ?? t('common.none')
  } finally {
    signing.value = false
  }
}

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
  <section class="page minutes">
    <div class="heading">
      <div>
        <h2>{{ t('meetingsUnit.minutes.title') }}</h2>
        <p class="subtitle">{{ t('meetingsUnit.minutes.subtitle') }}</p>
      </div>
    </div>

    <div class="card card-flat card-pad picker">
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
      <div v-if="!minutes" class="card card-flat card-pad">
        <p class="state">{{ t('meetingsUnit.minutes.notGenerated') }}</p>
        <button v-can="'meeting_minutes.add'" class="primary" type="button" :disabled="generating" @click="generate">
          {{ generating ? t('common.saving') : t('meetingsUnit.minutes.generate') }}
        </button>
        <p v-if="generateError" class="alert">{{ generateError }}</p>
      </div>

      <template v-else>
        <div class="card card-flat card-pad status-card">
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

        <section class="card card-flat card-pad content">
          <h3>{{ t('meetingsUnit.minutes.sections.meetingInfo') }}</h3>
          <div class="summary">
            <!-- Stage 70 — [D] Appendix 15's PM-MIN code for the محضر
                 itself, which Appendix 8 checks before it may be approved. -->
            <div v-if="minutes.minutes_number"><span>{{ t('meetingsUnit.minutes.minutesNumber') }}</span><strong class="ltr">{{ minutes.minutes_number }}</strong></div>
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
          <!-- Stage 73 — Appendix 8 requires إثبات صحة الانعقاد in the محضر,
               which means naming the rule the sitting was measured against.
               A محضر for a committee with no transcribed rule says so. -->
          <p v-if="minutes.content.attendance.quorum_required !== null" class="quorum" :class="minutes.content.attendance.quorum_met ? 'good' : 'bad'">
            {{ t('meetingsUnit.readiness.quorum.title') }}: {{ minutes.content.attendance.quorum_present }} / {{ minutes.content.attendance.quorum_required }}
            — {{ t(minutes.content.attendance.quorum_met ? 'meetingsUnit.readiness.quorum.met' : 'meetingsUnit.readiness.quorum.notMet') }}
            <span v-if="minutes.content.attendance.quorum_rule?.quorum_text" class="quorum-source">
              ({{ minutes.content.attendance.quorum_rule.quorum_text }})
            </span>
          </p>
          <p v-else class="quorum bad">
            {{ t('meetingsUnit.readiness.quorum.title') }}: {{ t('meetingsUnit.readiness.quorum.notRecorded') }}
          </p>

          <template v-if="minutes.content.committee">
            <h3>{{ t('committees.card.title') }}</h3>
            <dl class="committee-card">
              <template v-if="minutes.content.committee.formation_decision_number">
                <dt>{{ t('committees.card.formationDecisionNumber') }}</dt>
                <dd>
                  {{ minutes.content.committee.formation_decision_number }}
                  <span v-if="minutes.content.committee.formation_decision_date">
                    — {{ minutes.content.committee.formation_decision_date }}
                  </span>
                </dd>
              </template>
              <template v-if="minutes.content.committee.legal_basis">
                <dt>{{ t('committees.card.legalBasis') }}</dt>
                <dd>{{ minutes.content.committee.legal_basis }}</dd>
              </template>
              <template v-if="minutes.content.committee.minutes_approval_body">
                <dt>{{ t('committees.card.minutesApprovalBody') }}</dt>
                <dd>{{ minutes.content.committee.minutes_approval_body }}</dd>
              </template>
              <template v-if="minutes.content.committee.minutes_signature_rule">
                <dt>{{ t('committees.card.minutesSignatureRule') }}</dt>
                <dd>{{ minutes.content.committee.minutes_signature_rule }}</dd>
              </template>
            </dl>
          </template>

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
              <!-- Art. 89 — رقم القرار is the first element every decision
                   inside the محضر must carry. -->
              <p v-if="item.decision?.decision_number" class="item-field"><strong>{{ t('decisions.decisionNumber') }}:</strong> <span class="ltr">{{ item.decision.decision_number }}</span></p>
              <p v-if="item.decision" class="decision">
                {{ t(`decisions.outcome.${item.decision.outcome}`) }}
                <template v-if="item.decision.decided_by"> — {{ t('decisions.decidedBy') }} {{ item.decision.decided_by }}</template>
                <template v-if="item.decision.comment">: {{ item.decision.comment }}</template>
              </p>
              <p v-if="item.legal_basis" class="item-field"><strong>{{ t('meetingsUnit.minutes.item.legalBasis') }}:</strong> {{ item.legal_basis }}</p>
              <!-- Stage 74 — the rest of Art. 89's own six elements, plus
                   Art. 90's instrument: a محضر that recorded only the outcome
                   word would not say what the committee actually issued, nor
                   on what basis. -->
              <p v-if="item.decision?.instrument" class="item-field"><strong>{{ t('decisions.instrument.label') }}:</strong> {{ t(`decisions.instrument.${item.decision.instrument}`) }}</p>
              <p v-if="item.decision?.subject" class="item-field"><strong>{{ t('decisions.parts.subject') }}:</strong> {{ item.decision.subject }}</p>
              <p v-if="item.decision?.facts" class="item-field"><strong>{{ t('decisions.parts.facts') }}:</strong> {{ item.decision.facts }}</p>
              <p v-if="item.decision?.basis" class="item-field"><strong>{{ t('decisions.parts.basis') }}:</strong> {{ item.decision.basis }}</p>
              <p v-if="item.decision?.operative" class="item-field"><strong>{{ t('decisions.parts.operative') }}:</strong> {{ item.decision.operative }}</p>
              <p v-if="item.decision?.refusal_reason_code" class="item-field"><strong>{{ t('decisions.refusal.label') }}:</strong> {{ t(`decisions.refusal.reasons.${item.decision.refusal_reason_code}`) }}</p>
              <template v-if="item.decision?.deferral">
                <p v-for="field in DEFERRAL_FIELDS" :key="field" class="item-field">
                  <template v-if="item.decision.deferral[field.replace('deferral_', '')]">
                    <strong>{{ t(`decisions.deferral.${field}`) }}:</strong>
                    {{ item.decision.deferral[field.replace('deferral_', '')] }}
                  </template>
                </p>
              </template>
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

        <section v-if="minutes.status === 'draft'" v-can="'meeting_minutes.approve'" class="card card-flat card-pad review">
          <h3>{{ t('meetingsUnit.minutes.review.title') }}</h3>
          <!-- Stage 78 — [D] Appendix 8: the محضر is not referred for اعتماد
               until sixteen controls are verified. Only this one is a human
               judgement; the rest are checked server-side against the
               meeting's own data, and the refusal names whichever fails. -->
          <p class="quality-note">{{ t('controlGates.minutesQuality.note') }}</p>
          <label class="quality-check">
            <input v-model="noInternalContradictions" type="checkbox">
            <span>{{ t('controlGates.minutesQuality.reviewerCheck') }}</span>
          </label>
          <div class="actions">
            <button class="primary" type="button" :disabled="reviewBusy || !noInternalContradictions" @click="review('approve')">
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

        <!-- Stage 78 — the sixteen as answered at review time, so a later
             reader sees Appendix 8's own list rather than only that it passed. -->
        <section v-if="minutes.quality_checks" class="card card-flat card-pad review">
          <h3>{{ t('controlGates.minutesQuality.title') }}</h3>
          <ul class="quality-record">
            <li v-for="key in MINUTES_QUALITY_CHECKS" :key="key">
              <span>{{ t(`controlGates.minutesQuality.checks.${key}`) }}</span>
              <span :class="['answer', minutes.quality_checks[key]]">
                {{ t(`controlGates.minutesQuality.answers.${minutes.quality_checks[key]}`) }}
              </span>
            </li>
          </ul>
        </section>

        <section v-if="minutes.status !== 'draft'" class="card card-flat card-pad signatures">
          <h3>{{ t('meetingsUnit.minutes.signatures.title') }}</h3>
          <p v-if="!minutes.signatures.length" class="state">{{ t('meetingsUnit.minutes.signatures.none') }}</p>
          <ul v-else class="signature-list">
            <li v-for="signature in minutes.signatures" :key="signature.id">
              <span>{{ signature.user.name }}</span>
              <template v-if="signature.signed_at">
                <span class="pill good small">{{ t('meetingsUnit.minutes.signatures.signed') }} — {{ dateTime(signature.signed_at) }}</span>
              </template>
              <span v-else class="pill small">{{ t('meetingsUnit.minutes.signatures.pending') }}</span>
            </li>
          </ul>

          <div v-if="minutes.status === 'pending_signatures' && mySignature && !mySignature.signed_at" v-can="'meeting_minutes.add'" class="sign-panel">
            <button class="primary" type="button" :disabled="signing" @click="openSignConfirm">
              {{ signing ? t('common.saving') : t('meetingsUnit.minutes.signatures.sign') }}
            </button>
            <p v-if="signError" class="alert">{{ signError }}</p>
          </div>
        </section>
      </template>
    </template>

    <Teleport to="body">
      <div v-if="pendingSign" class="modal-backdrop" @click.self="closeSignConfirm">
        <section class="modal" role="dialog" aria-modal="true" aria-labelledby="confirm-sign-title">
          <h3 id="confirm-sign-title">{{ t('meetingsUnit.minutes.signatures.confirmSign.title') }}</h3>
          <p>{{ t('meetingsUnit.minutes.signatures.confirmSign.body') }}</p>
          <p v-if="signError" class="alert">{{ signError }}</p>
          <div class="modal-actions">
            <button class="ghost" type="button" :disabled="signing" @click="closeSignConfirm">
              {{ t('common.cancel') }}
            </button>
            <button class="primary" type="button" :disabled="signing" @click="sign">
              {{ signing ? t('common.saving') : t('meetingsUnit.minutes.signatures.confirmSign.confirm') }}
            </button>
          </div>
        </section>
      </div>
    </Teleport>
  </section>
</template>

<style scoped>
.page.minutes { max-inline-size: 68rem; }
.card h3 { margin: var(--space-4) 0 var(--space-2); color: var(--color-brand-text); font-size: var(--text-lg); }
.card h3:first-child { margin-top: 0; }
.picker, .status-card, .content, .review, .signatures { margin-bottom: var(--space-4); }
.picker label { display: flex; flex-direction: column; gap: .3rem; font-size: var(--text-base); color: var(--color-black-700); max-inline-size: 24rem; }
select, textarea { padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); color: var(--color-foreground); font: inherit; box-sizing: border-box; }
textarea { inline-size: 100%; resize: vertical; }

.status-card { display: flex; align-items: center; gap: .6rem; }
.pill.small { font-size: var(--text-xs); padding: .15rem .5rem; }
/* Minutes-lifecycle tones the generic good/bad/warn/info modifiers don't name
   directly — draft/pending_signatures/approved are the server's own codes. */
.pill.draft { background: var(--color-warning-bg); color: var(--color-warning-fg); border-color: var(--color-warning-border); }
.pill.pending_signatures { background: var(--color-info-bg); color: var(--color-info-fg); border-color: var(--color-info-border); }
.pill.approved { background: var(--color-success-bg); color: var(--color-success-fg); border-color: var(--color-success-border); }

.attendance { display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: var(--space-4); }
.attendance ul { list-style: none; margin: .25rem 0 0; padding: 0; font-size: var(--text-sm); color: var(--color-black-700); }
.role { color: var(--color-muted); font-size: var(--text-sm); }
.quorum { margin: var(--space-2) 0 0; font-size: var(--text-sm); }
.quorum-source { color: var(--color-muted); }
.committee-card { display: grid; grid-template-columns: max-content 1fr; gap: .35rem .75rem; margin: 0 0 var(--space-4); font-size: var(--text-base); }
.committee-card dt { color: var(--color-muted); }
.committee-card dd { margin: 0; }
.quorum.good { color: var(--color-success-fg); }
.quorum.bad { color: var(--color-danger-fg); }
.signatories { list-style: none; margin: .25rem 0 0; padding: 0; font-size: var(--text-sm); color: var(--color-black-700); display: grid; gap: .2rem; }

.agenda-items { list-style: none; margin: 0; padding: 0; display: grid; gap: var(--space-3); }
.agenda-items li { padding-bottom: var(--space-3); border-bottom: 1px solid var(--color-border); font-size: var(--text-base); }
.agenda-items li:last-child { border-bottom: 0; padding-bottom: 0; }
.item-heading { display: flex; align-items: center; gap: var(--space-2); flex-wrap: wrap; margin-bottom: .3rem; }
.item-heading .ref { font-size: var(--text-sm); color: var(--color-muted); }
.tally { display: flex; gap: var(--space-3); flex-wrap: wrap; color: var(--color-muted); font-size: var(--text-sm); margin-bottom: .2rem; font-variant-numeric: tabular-nums; }
.decision { margin: .2rem 0; color: var(--color-brand-text); font-size: var(--text-sm); }
.item-field { margin: .2rem 0; font-size: var(--text-sm); color: var(--color-black-700); list-style: none; padding: 0; }
.item-field li { margin-inline-start: 1.1rem; list-style: disc; }
.notes { list-style: none; margin: .3rem 0 0; padding: 0; display: grid; gap: .2rem; font-size: var(--text-sm); color: var(--color-black-700); }

.review .actions { margin-bottom: var(--space-2); }
.changes-form { display: grid; gap: .4rem; }

.signature-list { list-style: none; margin: 0 0 var(--space-3); padding: 0; display: grid; gap: var(--space-2); }
.signature-list li { display: flex; align-items: center; gap: .6rem; font-size: var(--text-base); }
.sign-panel { display: grid; gap: var(--space-2); padding-top: var(--space-2); border-top: 1px dashed var(--color-border-hover); }

.quality-note { margin: 0 0 var(--space-2); color: var(--color-muted); font-size: var(--text-sm); }
.quality-check { display: flex; align-items: center; gap: var(--space-2); margin-bottom: var(--space-3); font-size: var(--text-base); }
.quality-record { display: grid; gap: .3rem; padding: 0; margin: var(--space-2) 0 0; list-style: none; font-size: var(--text-sm); }
.quality-record li { display: flex; justify-content: space-between; gap: var(--space-3); }
.quality-record .answer { font-weight: 600; }
.quality-record .answer.yes { color: var(--color-success-fg); }
.quality-record .answer.no { color: var(--color-danger-fg); }
.quality-record .answer.enforced_by_signature_lifecycle { color: var(--color-muted); }
</style>
