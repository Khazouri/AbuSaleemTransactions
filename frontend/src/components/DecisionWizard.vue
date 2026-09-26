<script setup>
/**
 * Decision wizard — sub-project 1. The one way to act on a request from its
 * page: read the file, clear this stage's own checks, pick an action knowing
 * where it sends the file, then confirm it as a تأشيرة.
 *
 * Everything it offers comes from the server: `available_transitions` (with
 * where each one leads) and `blocked_transitions` (with the refusal the
 * endpoint would give). The wizard decides nothing about permissions; it only
 * lays out what detailResource() already worked out for this actor.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AppModal from './AppModal.vue'
import DocumentValidityPanel from './DocumentValidityPanel.vue'
import IntakeGatePanel from './IntakeGatePanel.vue'
import JurisdictionTestForm from './JurisdictionTestForm.vue'
import RequestStageRail from './RequestStageRail.vue'
import api from '../lib/api'
import { useAuthStore } from '../stores/auth'

const props = defineProps({
  request: { type: Object, required: true },
})
const emit = defineEmits(['updated', 'close'])

const { t, te, locale } = useI18n()
const auth = useAuthStore()

// Mirrors the page's old destructive tint: these end or refuse the file.
const DESTRUCTIVE = ['cancel', 'reject', 'reject_review', 'reject_formally', 'reject_by_committee', 'declare_no_jurisdiction']
// The three requirements_check outcomes Art. 45's test gates (Stage 54).
const CLASSIFYING = ['approve', 'declare_no_jurisdiction', 'reject_formally']

const name = (item) => (locale.value === 'ar' ? item?.name_ar || item?.name_en : item?.name_en || item?.name_ar) ?? ''
const actionLabel = (action) => t(`workflow.actions.${action}`)
const meaning = (action) => (te(`decisionWizard.meaning.${action}`) ? t(`decisionWizard.meaning.${action}`) : '')

const available = computed(() => props.request.available_transitions ?? [])
const blocked = computed(() => props.request.blocked_transitions ?? [])
const offered = computed(() => new Set([...available.value, ...blocked.value].map((item) => item.action)))
const stage = computed(() => props.request.current_stage?.code)
const gates = computed(() => props.request.control_gates ?? {})

// Only the checks whose owner is the person deciding right now: keyed on the
// action they are being offered, so a reader who merely holds a grant is not
// walked through someone else's gate.
const checks = computed(() => {
  const list = []
  if (gates.value.document_validity?.can_record) list.push('document_validity')
  if (stage.value === 'receive_and_register' && offered.value.has('register') && gates.value.employment_file) list.push('employment_file')
  if (stage.value === 'requirements_check' && CLASSIFYING.some((action) => offered.value.has(action))) {
    if (gates.value.intake) list.push('intake')
    list.push('jurisdiction_test')
  }
  return list
})

const steps = computed(() => ['review', ...(checks.value.length ? ['checks'] : []), 'choose', 'confirm'])
const step = ref('review')
const stepIndex = computed(() => steps.value.indexOf(step.value))

const selected = ref('')
const comment = ref('')
const submitting = ref(false)
const error = ref('')

const choice = computed(() => available.value.find((item) => item.action === selected.value) ?? null)
const ordinary = computed(() => available.value.filter((item) => !item.is_exception))
const exceptional = computed(() => available.value.filter((item) => item.is_exception))
const tone = (action) => (DESTRUCTIVE.includes(action) ? 'destructive' : '')
const commentMissing = computed(() => Boolean(choice.value?.requires_comment) && !comment.value.trim())

// A check can clear a gate and turn a blocked action into an available one; a
// selection the refreshed payload no longer offers is dropped, not kept stale.
watch(available, (list) => {
  if (selected.value && !list.some((item) => item.action === selected.value)) selected.value = ''
})

const recentTimeline = computed(() => (props.request.timeline ?? []).slice(-3).reverse())
const roleNames = computed(() => (auth.user?.roles ?? []).map(name).join('، '))
const today = computed(() => new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'long' }).format(new Date()))
const dateTime = (value) => new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium' }).format(new Date(value))

function go(target) {
  error.value = ''
  step.value = target
}
const next = () => go(steps.value[stepIndex.value + 1])
const back = () => go(steps.value[stepIndex.value - 1])

// DocumentValidityPanel answers with nothing, the others with the full detail
// resource; either way the page's copy of the request is replaced, so the
// Choose step reflects what the check just unlocked.
async function onCheckSaved(resource) {
  if (resource) {
    emit('updated', resource)
    return
  }
  const { data } = await api.get(`/requests/${props.request.id}`)
  emit('updated', data.data)
}

async function submit() {
  if (submitting.value || !choice.value || commentMissing.value) return
  submitting.value = true
  error.value = ''
  try {
    const payload = { action: choice.value.action }
    if (comment.value.trim()) payload.comment = comment.value.trim()
    const { data } = await api.post(`/requests/${props.request.id}/transition`, payload)
    emit('updated', data.data)
    emit('close')
  } catch (requestError) {
    error.value = requestError.response?.data?.errors?.action?.[0]
      ?? requestError.response?.data?.errors?.comment?.[0]
      ?? requestError.response?.data?.message
      ?? t('requestDetail.actionFailed')
  } finally {
    submitting.value = false
  }
}

function close() {
  if (!submitting.value) emit('close')
}
</script>

<template>
  <AppModal wide :title="t('decisionWizard.title')" @close="close">
    <div class="wizard">
      <ol class="steps">
        <li
          v-for="(code, index) in steps"
          :key="code"
          class="step-pill"
          :class="{ active: step === code, done: index < stepIndex }"
          :aria-current="step === code ? 'step' : undefined"
        >
          {{ index + 1 }}. {{ t(`decisionWizard.steps.${code}`) }}
        </li>
      </ol>

      <Transition name="step" mode="out-in">
        <!-- Review ---------------------------------------------------------- -->
        <section v-if="step === 'review'" key="review" class="pane">
          <p class="lede">{{ t('decisionWizard.review.lede') }}</p>
          <dl class="facts">
            <div><dt>{{ t('decisionWizard.review.type') }}</dt><dd>{{ name(request.request_type) || '—' }}</dd></div>
            <div><dt>{{ t('requestDetail.subjectUser') }}</dt><dd>{{ request.subject?.name || request.created_by?.name || '—' }}</dd></div>
            <div v-if="request.subject && request.created_by && request.subject.id !== request.created_by.id">
              <dt>{{ t('requestDetail.filedBy') }}</dt><dd>{{ request.created_by.name }}</dd>
            </div>
            <div><dt>{{ t('requestDetail.currentStage') }}</dt><dd>{{ name(request.current_stage) || '—' }}</dd></div>
          </dl>
          <RequestStageRail
            variant="compact"
            :stage-progress="request.stage_progress"
            :stage-timeliness="request.stage_timeliness"
            :current-stage-name="name(request.current_stage)"
          />
          <p v-if="request.description" class="description">{{ request.description }}</p>

          <h4>{{ t('attachments.title') }}</h4>
          <p v-if="!request.attachments?.length" class="state">{{ t('requestDetail.noAttachments') }}</p>
          <ul v-else class="plain-list">
            <li v-for="attachment in request.attachments" :key="attachment.id">
              <span class="ltr file-name">{{ attachment.original_name }}</span>
              <small>{{ attachment.label || '' }}</small>
            </li>
          </ul>

          <h4>{{ t('decisionWizard.review.recent') }}</h4>
          <p v-if="!recentTimeline.length" class="state">{{ t('requestDetail.noTimeline') }}</p>
          <ul v-else class="plain-list">
            <li v-for="entry in recentTimeline" :key="entry.id">
              <strong>{{ actionLabel(entry.action) }}</strong>
              <small>{{ entry.acted_by?.name || '—' }} · {{ dateTime(entry.acted_at) }}</small>
              <p v-if="entry.comment" class="quote">{{ entry.comment }}</p>
            </li>
          </ul>
          <p class="hint">{{ t('decisionWizard.review.more') }}</p>
        </section>

        <!-- Checks ---------------------------------------------------------- -->
        <section v-else-if="step === 'checks'" key="checks" class="pane">
          <p class="lede">{{ t('decisionWizard.checks.lede') }}</p>
          <div v-for="check in checks" :key="check" class="check">
            <h4>{{ t(`decisionWizard.checks.${check}`) }}</h4>
            <DocumentValidityPanel
              v-if="check === 'document_validity'"
              :request-id="request.id"
              :rows="gates.document_validity.rows"
              :refusal="gates.document_validity.refusal"
              @updated="onCheckSaved()"
            />
            <IntakeGatePanel
              v-else-if="check === 'employment_file'"
              :request-id="request.id"
              :required-documents="gates.employment_file.required_documents"
              :record="gates.employment_file.record"
              :refusal="gates.employment_file.refusal"
              :recorded-by="gates.employment_file.prepared_by"
              :recorded-at="gates.employment_file.prepared_at"
              endpoint="employment-file"
              attestation-field="assembled"
              grant="notes_attachments.add"
              copy="employmentFile"
              @updated="onCheckSaved"
            />
            <IntakeGatePanel
              v-else-if="check === 'intake'"
              :request-id="request.id"
              :required-documents="gates.intake.required_documents"
              :record="gates.intake.record"
              :refusal="gates.intake.refusal"
              :recorded-by="gates.intake.recorded_by"
              :recorded-at="gates.intake.recorded_at"
              @updated="onCheckSaved"
            />
            <JurisdictionTestForm v-else :request="request" @updated="onCheckSaved" />
          </div>
        </section>

        <!-- Choose ---------------------------------------------------------- -->
        <section v-else-if="step === 'choose'" key="choose" class="pane">
          <p class="lede">{{ t('decisionWizard.choose.lede') }}</p>
          <fieldset class="options">
            <legend class="sr-only">{{ t('decisionWizard.steps.choose') }}</legend>
            <template v-for="(group, groupIndex) in [ordinary, exceptional]" :key="groupIndex">
              <p v-if="groupIndex === 1 && group.length && ordinary.length" class="group-label">{{ t('decisionWizard.choose.other') }}</p>
              <label v-for="item in group" :key="item.action" class="option" :class="[tone(item.action), { chosen: selected === item.action }]">
                <input v-model="selected" type="radio" name="decision" :value="item.action" />
                <span class="option-text">
                  <strong>{{ actionLabel(item.action) }}</strong>
                  <span v-if="meaning(item.action)">{{ meaning(item.action) }}</span>
                  <small v-if="item.to_stage">{{ t('decisionWizard.choose.destination', { stage: name(item.to_stage), status: name(item.to_status) }) }}</small>
                </span>
              </label>
            </template>
            <template v-if="blocked.length">
              <p class="group-label">{{ t('decisionWizard.choose.blocked') }}</p>
              <div v-for="item in blocked" :key="item.action" class="option blocked">
                <input type="radio" name="decision" disabled :aria-label="actionLabel(item.action)" />
                <span class="option-text">
                  <strong>{{ actionLabel(item.action) }}</strong>
                  <span>{{ item.reason }}</span>
                  <button v-if="steps.includes('checks')" class="link" type="button" @click="go('checks')">
                    {{ t('decisionWizard.choose.toChecks') }}
                  </button>
                </span>
              </div>
            </template>
          </fieldset>
          <p v-if="!available.length" class="state">{{ t('decisionWizard.choose.none') }}</p>
        </section>

        <!-- Confirm: the تأشيرة ---------------------------------------------- -->
        <section v-else key="confirm" class="pane">
          <article class="slip" :class="tone(choice?.action)">
            <header class="slip-head">
              <span>{{ t('decisionWizard.slip.kind') }}</span>
              <span class="ltr-num">{{ request.reference_number || request.intake_receipt_number || `#${request.id}` }}</span>
            </header>
            <p class="slip-action">{{ actionLabel(choice?.action) }}</p>
            <p v-if="choice?.to_stage" class="slip-destination">
              {{ t('decisionWizard.choose.destination', { stage: name(choice.to_stage), status: name(choice.to_status) }) }}
            </p>
            <label class="slip-note">
              {{ choice?.requires_comment ? t('decisionWizard.slip.reasonRequired') : t('decisionWizard.slip.noteOptional') }}
              <textarea v-model="comment" rows="3" maxlength="5000" :required="choice?.requires_comment" :disabled="submitting" />
            </label>
            <footer class="slip-foot">
              <span>
                <strong>{{ auth.user?.name }}</strong>
                <small>{{ roleNames }}</small>
              </span>
              <time>{{ today }}</time>
            </footer>
          </article>
          <p v-if="error" class="alert" role="alert">{{ error }}</p>
        </section>
      </Transition>

      <div class="nav">
        <button v-if="stepIndex > 0" class="ghost" type="button" :disabled="submitting" @click="back">
          {{ t('decisionWizard.back') }}
        </button>
        <span class="spacer" />
        <button class="ghost" type="button" :disabled="submitting" @click="close">{{ t('common.cancel') }}</button>
        <button v-if="step === 'choose'" class="primary" type="button" :disabled="!choice" @click="next">
          {{ t('decisionWizard.next') }}
        </button>
        <button v-else-if="step !== 'confirm'" class="primary" type="button" @click="next">
          {{ t('decisionWizard.next') }}
        </button>
        <button
          v-else
          :class="tone(choice?.action) ? 'btn danger' : 'primary'"
          type="button"
          :disabled="submitting || commentMissing"
          @click="submit"
        >
          {{ submitting ? t('requestDetail.processing') : t('decisionWizard.submit', { action: actionLabel(choice?.action) }) }}
        </button>
      </div>
    </div>
  </AppModal>
</template>

<style scoped>
/* Step strip: the same pills as MeetingSchedulingWizard, so the app's two
   wizards read as one family. */
.steps { display: flex; flex-wrap: wrap; gap: 0.4rem; padding: 0; margin: 0 0 var(--space-4); list-style: none; }
.step-pill { padding: 0.25rem 0.6rem; border-radius: var(--radius-full); background: var(--color-surface-hover); color: var(--color-muted); font-size: var(--text-xs); }
.step-pill.active { background: var(--color-brand); color: var(--color-on-brand); }
.step-pill.done { background: var(--color-success-bg); color: var(--color-success-fg); }

.pane { display: grid; gap: var(--space-3); }
.pane h4 { margin: var(--space-3) 0 0; color: var(--color-brand-text); font-size: var(--text-lg); }
.lede { margin: 0; color: var(--color-black-700); font-size: var(--text-base); max-inline-size: 60ch; }
.facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(12rem, 100%), 1fr)); gap: var(--space-3); margin: 0; }
.facts div { display: grid; gap: 0.15rem; }
.facts dt { color: var(--color-muted); font-size: var(--text-xs); }
.facts dd { margin: 0; color: var(--color-black-700); font-weight: 600; }
.description, .quote { margin: 0; color: var(--color-black-700); white-space: pre-wrap; max-inline-size: 70ch; }
.quote { padding-inline-start: var(--space-3); border-inline-start: 2px solid var(--color-border-hover); font-size: var(--text-sm); }
.plain-list { display: grid; gap: var(--space-2); padding: 0; margin: 0; list-style: none; }
.plain-list li { display: grid; gap: 0.15rem; padding-block-end: var(--space-2); border-block-end: 1px solid var(--color-border); }
.plain-list small { color: var(--color-muted); font-size: var(--text-xs); }
.file-name { overflow-wrap: anywhere; color: var(--color-black-700); font-size: var(--text-sm); }
.ltr { direction: ltr; justify-self: start; }
.check + .check { padding-block-start: var(--space-4); border-block-start: 1px solid var(--color-border); }
.check h4 { margin-block-start: 0; }

/* Choose: rows, not a card grid — one decision reads down one column. */
.options { display: grid; gap: var(--space-2); padding: 0; margin: 0; border: 0; }
.group-label { margin: var(--space-3) 0 0; padding-block-start: var(--space-3); border-block-start: 1px solid var(--color-border); color: var(--color-muted); font-size: var(--text-sm); }
.option { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: var(--space-3); align-items: start; padding: var(--space-3) var(--space-4); border: 1px solid var(--color-border); border-inline-start: 4px solid var(--color-brand-text); border-radius: var(--radius-lg); background: var(--color-surface); cursor: pointer; }
.option.destructive { border-inline-start-color: var(--color-danger-fg); }
.option.chosen { border-color: var(--color-brand-text); background: var(--color-surface-hover); }
.option.destructive.chosen { border-color: var(--color-danger-fg); }
.option:has(input:focus-visible) { outline: 2px solid var(--color-brand-text); outline-offset: 2px; }
.option input { margin-block-start: 0.3rem; accent-color: var(--color-brand); }
.option-text { display: grid; gap: 0.2rem; }
.option-text strong { color: var(--color-black-700); font-size: var(--text-lg); }
.option-text span { color: var(--color-black-700); font-size: var(--text-sm); }
.option-text small { color: var(--color-muted); font-size: var(--text-xs); }
.option.blocked { border-inline-start-color: var(--color-border-hover); background: var(--color-surface-hover); cursor: default; }
.option.blocked strong { color: var(--color-muted); }
.option.blocked span { color: var(--color-warning-fg); }
.link { justify-self: start; padding: 0; border: 0; background: none; color: var(--color-brand-text); font: inherit; font-size: var(--text-sm); text-decoration: underline; cursor: pointer; }

/* The تأشيرة: the one element here with a voice of its own. A minute written
   on the file — who decided, what, where it goes, in their own words. */
.slip { display: grid; gap: var(--space-3); padding: var(--space-5); border: 1px solid var(--color-border-hover); border-inline-start: 8px solid var(--color-brand-text); border-radius: var(--radius-sm); background: var(--color-surface); }
.slip.destructive { border-inline-start-color: var(--color-danger-fg); }
.slip-head { display: flex; justify-content: space-between; gap: var(--space-3); color: var(--color-muted); font-size: var(--text-sm); }
.ltr-num { direction: ltr; font-variant-numeric: tabular-nums; }
.slip-action { margin: 0; color: var(--color-brand-text); font-size: clamp(1.5rem, 4vw, 2.1rem); font-weight: 700; line-height: 1.2; }
.slip.destructive .slip-action { color: var(--color-danger-fg); }
.slip-destination { margin: 0; color: var(--color-black-700); }
.slip-note { display: grid; gap: 0.3rem; color: var(--color-muted); font-size: var(--text-sm); }
.slip-note textarea { padding: 0.6rem 0.7rem; border: 0; border-block-end: 1px dashed var(--color-border-hover); border-radius: 0; background: transparent; color: var(--color-foreground); font: inherit; font-size: var(--text-lg); line-height: 1.7; resize: vertical; }
.slip-note textarea:focus-visible { outline: 2px solid var(--color-brand-text); outline-offset: 2px; }
.slip-foot { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: end; gap: var(--space-3); padding-block-start: var(--space-3); border-block-start: 1px solid var(--color-border); }
.slip-foot span { display: grid; gap: 0.1rem; }
.slip-foot strong { color: var(--color-black-700); }
.slip-foot small, .slip-foot time { color: var(--color-muted); font-size: var(--text-sm); }

.nav { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); margin-block-start: var(--space-5); padding-block-start: var(--space-4); border-block-start: 1px solid var(--color-border); }
.spacer { flex: 1; }
.sr-only { position: absolute; inline-size: 1px; block-size: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }

/* The one motion: the next pane arrives from the reading direction's end. */
.step-enter-active, .step-leave-active { transition: opacity 160ms ease, transform 160ms ease; }
.step-enter-from { opacity: 0; transform: translateX(0.75rem); }
.step-enter-from:dir(rtl) { transform: translateX(-0.75rem); }
.step-leave-to { opacity: 0; }
@media (prefers-reduced-motion: reduce) {
  .step-enter-active, .step-leave-active { transition: none; }
}
</style>
