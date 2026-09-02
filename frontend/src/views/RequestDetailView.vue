<script setup>
/** Stage 15 — the workflow workspace for a single request. */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import ApprovalTrail from '../components/ApprovalTrail.vue'
import FileUpload from '../components/FileUpload.vue'
import SignaturePad from '../components/SignaturePad.vue'
import RequestNotes from '../components/RequestNotes.vue'
import api from '../lib/api'

const route = useRoute()
const { t, locale } = useI18n()
const request = ref(null)
const loading = ref(false)
const error = ref('')
const actionError = ref('')
const comment = ref('')
const acting = ref(false)
const activeAction = ref('')
const selectedException = ref(null)
const exceptionReason = ref('')
const exceptionError = ref('')
const signaturePad = ref(null)
const signatureReady = ref(false)
const selectedAttachment = ref(null)
const attachmentPreviewUrl = ref('')
const attachmentPreviewing = ref(false)
const attachmentPreviewError = ref('')
const financialImpactSaving = ref(false)
const financialImpactError = ref('')

const name = (item) => {
  if (!item) return t('common.none')
  return locale.value === 'ar' ? item.name_ar || item.name_en : item.name_en || item.name_ar
}
const dateTime = (value) => value
  ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
  : t('common.none')
const date = (value) => value
  ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium' }).format(new Date(value))
  : t('common.none')
const actionLabel = (action) => t(`workflow.actions.${action}`)
// Stage 52 — "3" for a single-day target, "5–10" for a range.
const stageTargetLabel = (st) => st.target_days_min === st.target_days_max
  ? String(st.target_days_max)
  : `${st.target_days_min}–${st.target_days_max}`
const timelineMovement = (entry) => entry.from_stage
  ? `${name(entry.from_stage)} ${locale.value === 'ar' ? '←' : '→'} ${name(entry.to_stage)}`
  : name(entry.to_stage)
const transitions = computed(() => {
  if (request.value?.available_transitions?.length) return request.value.available_transitions
  return (request.value?.available_actions ?? []).map((action) => ({
    action,
    is_exception: false,
    requires_comment: false,
  }))
})
const normalActions = computed(() => transitions.value.filter((item) => !item.is_exception))
const exceptionActions = computed(() => transitions.value.filter((item) => item.is_exception))
const canAct = computed(() => transitions.value.length > 0)
const requiresSignature = computed(() => normalActions.value.some((item) => item.action === 'approve'))

function fileSize(bytes) {
  if (!Number.isFinite(bytes)) return t('common.none')
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 ** 2).toFixed(1)} MB`
}

function isPreviewable(attachment) {
  return attachment?.mime_type === 'application/pdf' || attachment?.mime_type?.startsWith('image/')
}

function isImage(attachment) {
  return attachment?.mime_type?.startsWith('image/')
}

function clearAttachmentPreview() {
  if (attachmentPreviewUrl.value) URL.revokeObjectURL(attachmentPreviewUrl.value)
  attachmentPreviewUrl.value = ''
}

function closeAttachmentPreview() {
  if (attachmentPreviewing.value) return
  clearAttachmentPreview()
  selectedAttachment.value = null
  attachmentPreviewError.value = ''
}

async function openAttachmentPreview(attachment) {
  clearAttachmentPreview()
  selectedAttachment.value = attachment
  attachmentPreviewing.value = true
  attachmentPreviewError.value = ''
  try {
    const { data } = await api.get(attachment.preview_url, { responseType: 'blob' })
    attachmentPreviewUrl.value = URL.createObjectURL(data)
  } catch (requestError) {
    attachmentPreviewError.value = requestError.response?.data?.message ?? t('attachments.previewFailed')
  } finally {
    attachmentPreviewing.value = false
  }
}

async function downloadAttachment(attachment) {
  try {
    const { data } = await api.get(attachment.preview_url, { responseType: 'blob' })
    const url = URL.createObjectURL(data)
    const link = document.createElement('a')
    link.href = url
    link.download = attachment.original_name
    document.body.append(link)
    link.click()
    link.remove()
    window.setTimeout(() => URL.revokeObjectURL(url), 0)
  } catch {
    actionError.value = t('attachments.downloadFailed')
  }
}

/** Stage 47 — flips the auto-derived flag; the PATCH returns the full detail resource. */
async function toggleFinancialImpact() {
  if (financialImpactSaving.value) return
  financialImpactSaving.value = true
  financialImpactError.value = ''
  try {
    const { data } = await api.patch(`/requests/${request.value.id}/financial-impact`, {
      has_financial_impact: !request.value.has_financial_impact,
    })
    request.value = data.data
  } catch (requestError) {
    financialImpactError.value = requestError.response?.data?.message ?? t('requestDetail.financialImpact.updateFailed')
  } finally {
    financialImpactSaving.value = false
  }
}

async function load() {
  loading.value = true
  error.value = ''
  actionError.value = ''
  try {
    const { data } = await api.get(`/requests/${route.params.id}`)
    request.value = data.data
  } catch (requestError) {
    error.value = requestError.response?.data?.message ?? t('requestDetail.loadFailed')
  } finally {
    loading.value = false
  }
}

async function transition(action, suppliedComment = comment.value) {
  if (acting.value) return
  const signature = action === 'approve' ? await signaturePad.value?.toFile() : null
  if (action === 'approve' && !signature) {
    actionError.value = t('signature.required')
    return false
  }

  acting.value = true
  activeAction.value = action
  actionError.value = ''
  try {
    const form = new FormData()
    form.append('action', action)
    if (suppliedComment.trim()) form.append('comment', suppliedComment.trim())
    if (signature) form.append('signature', signature)
    const { data } = await api.post(`/requests/${request.value.id}/transition`, form)
    if (signature) signaturePad.value?.clear()
    request.value = data.data
    comment.value = ''
    selectedException.value = null
    exceptionReason.value = ''
    exceptionError.value = ''
    return true
  } catch (requestError) {
    const message = requestError.response?.data?.errors?.action?.[0]
      ?? requestError.response?.data?.errors?.signature?.[0]
      ?? requestError.response?.data?.message
      ?? t('requestDetail.actionFailed')
    if (selectedException.value) exceptionError.value = message
    else actionError.value = message
    return false
  } finally {
    acting.value = false
    activeAction.value = ''
  }
}

// Stage 16 — exception actions pause for an explicit reason before execution.
function openException(exception) {
  selectedException.value = exception
  exceptionReason.value = ''
  exceptionError.value = ''
}

function closeException() {
  if (acting.value) return
  selectedException.value = null
  exceptionReason.value = ''
  exceptionError.value = ''
}

async function submitException() {
  exceptionError.value = ''
  if (!exceptionReason.value.trim()) {
    exceptionError.value = t('requestDetail.reasonRequired')
    return
  }
  await transition(selectedException.value.action, exceptionReason.value)
}

watch(() => route.params.id, load)
onMounted(load)
onBeforeUnmount(clearAttachmentPreview)
</script>

<template>
  <section class="detail">
    <RouterLink class="back" :to="{ name: 'requests' }">{{ t('requestDetail.back') }}</RouterLink>

    <p v-if="loading" class="state">{{ t('common.loading') }}</p>
    <div v-else-if="error" class="alert" role="alert">
      {{ error }} <button class="ghost" type="button" @click="load">{{ t('common.retry') }}</button>
    </div>

    <template v-else-if="request">
      <header class="heading">
        <div>
          <p class="reference ltr">{{ request.reference_number || `#${request.id}` }}</p>
          <h2>{{ request.title }}</h2>
        </div>
        <span v-if="request.status" class="status" :style="{ '--status-color': request.status.color || 'var(--color-muted)' }">
          {{ name(request.status) }}
        </span>
      </header>

      <!-- Stage 17 — a breach remains visible without replacing workflow status. -->
      <p v-if="request.is_overdue" class="sla-alert" role="status">
        {{ t('requestDetail.overdue', { date: date(request.due_date) }) }}
      </p>

      <section class="card summary">
        <div>
          <span>{{ t('requestDetail.currentStage') }}</span>
          <strong>{{ name(request.current_stage) }}</strong>
        </div>
        <div>
          <span>{{ t('requests.department') }}</span>
          <strong>{{ name(request.department) }}</strong>
        </div>
        <div>
          <span>{{ t('requests.type') }}</span>
          <strong>{{ name(request.request_type) }}</strong>
        </div>
        <div>
          <span>{{ t('requestDetail.submittedAt') }}</span>
          <strong>{{ dateTime(request.submitted_at || request.created_at) }}</strong>
        </div>
        <div v-if="request.due_date">
          <span>{{ t('requestDetail.dueDate') }}</span>
          <strong>{{ date(request.due_date) }}</strong>
        </div>
        <!-- Stage 51 — [A] §7's "المستندات الناقصة" flag, derived from status. -->
        <div>
          <span>{{ t('requestDetail.documentsComplete.label') }}</span>
          <strong>{{ request.documents_complete ? t('requestDetail.documentsComplete.yes') : t('requestDetail.documentsComplete.no') }}</strong>
        </div>
        <!-- Stage 52 — a non-blocking, per-stage soft-SLA indicator; absent
             entirely when the current stage has no sourced target. -->
        <div v-if="request.stage_timeliness">
          <span>{{ t('requestDetail.stageTimeliness.label') }}</span>
          <strong>
            <span class="timeliness" :class="`level-${request.stage_timeliness.level}`">
              {{ t(`requestDetail.stageTimeliness.level.${request.stage_timeliness.level}`) }}
            </span>
            <br>
            <small>{{ t('requestDetail.stageTimeliness.elapsed', { days: request.stage_timeliness.elapsed_days, target: stageTargetLabel(request.stage_timeliness) }) }}</small>
          </strong>
        </div>
        <!-- Stage 47 — derived from request type at intake, correctable by
             whoever studies the file (rides notes_attachments,edit). -->
        <div>
          <span>{{ t('requestDetail.financialImpact.label') }}</span>
          <strong>
            {{ request.has_financial_impact ? t('requestDetail.financialImpact.yes') : t('requestDetail.financialImpact.no') }}
            <button
              v-can="'notes_attachments.edit'"
              class="ghost financial-impact-toggle"
              type="button"
              :disabled="financialImpactSaving"
              @click="toggleFinancialImpact"
            >
              {{ t('requestDetail.financialImpact.toggle') }}
            </button>
          </strong>
        </div>
      </section>
      <p v-if="financialImpactError" class="action-error" role="alert">{{ financialImpactError }}</p>

      <section v-if="canAct" class="card action-panel">
        <h3>{{ t('requestDetail.actions') }}</h3>
        <p>{{ t('requestDetail.actionHint') }}</p>
        <label v-if="normalActions.length">
          {{ t('requestDetail.comment') }}
          <textarea v-model="comment" rows="2" maxlength="5000" :disabled="acting" />
        </label>
        <SignaturePad
          v-if="requiresSignature"
          ref="signaturePad"
          :disabled="acting"
          @change="signatureReady = $event"
        />
        <p v-if="actionError" class="action-error" role="alert">{{ actionError }}</p>
        <div class="action-buttons">
          <button
            v-for="item in normalActions"
            :key="item.action"
            class="primary"
            type="button"
            :disabled="acting || (item.action === 'approve' && !signatureReady)"
            @click="transition(item.action)"
          >
            {{ acting && activeAction === item.action ? t('requestDetail.processing') : actionLabel(item.action) }}
          </button>
        </div>
        <div v-if="exceptionActions.length" class="exception-actions">
          <p>{{ t('requestDetail.exceptionActions') }}</p>
          <div class="action-buttons">
            <button
              v-for="item in exceptionActions"
              :key="item.action"
              class="exception-button"
              :class="{ destructive: ['reject_review', 'cancel'].includes(item.action) }"
              type="button"
              :disabled="acting"
              @click="openException(item)"
            >
              {{ actionLabel(item.action) }}
            </button>
          </div>
        </div>
      </section>

      <div class="columns">
        <div class="main-column">
          <section class="card description">
            <h3>{{ t('requestDetail.description') }}</h3>
            <p>{{ request.description || t('requestDetail.noDescription') }}</p>
          </section>

          <!-- Stage 51 — [A] §7's committee-presentation fields, once the request has ridden an agenda. -->
          <section v-if="request.committee_summary" class="card summary committee-summary">
            <h3>{{ t('requestDetail.committeeSummary.title') }}</h3>
            <div>
              <span>{{ t('requestDetail.committeeSummary.meetingNumber') }}</span>
              <strong>{{ request.committee_summary.meeting_number || t('common.none') }}</strong>
            </div>
            <div>
              <span>{{ t('requestDetail.committeeSummary.meetingDate') }}</span>
              <strong>{{ date(request.committee_summary.meeting_date) }}</strong>
            </div>
            <div>
              <span>{{ t('requestDetail.committeeSummary.agendaItemNumber') }}</span>
              <strong>{{ request.committee_summary.agenda_item_number ?? t('common.none') }}</strong>
            </div>
            <div>
              <span>{{ t('requestDetail.committeeSummary.committeeResult') }}</span>
              <strong>
                {{ request.committee_summary.committee_result
                  ? t(`decisions.outcome.${request.committee_summary.committee_result}`)
                  : t('requestDetail.committeeSummary.resultPending') }}
              </strong>
            </div>
            <div v-if="request.committee_summary.decision_date">
              <span>{{ t('requestDetail.committeeSummary.decisionDate') }}</span>
              <strong>{{ date(request.committee_summary.decision_date) }}</strong>
            </div>
          </section>

          <section class="card timeline">
            <h3>{{ t('requestDetail.timeline') }}</h3>
            <p v-if="!request.timeline?.length" class="state">{{ t('requestDetail.noTimeline') }}</p>
            <ol v-else>
              <li v-for="entry in request.timeline" :key="entry.id">
                <span class="dot" />
                <div>
                  <strong>{{ actionLabel(entry.action) }}</strong>
                  <p v-if="entry.to_stage">{{ timelineMovement(entry) }}</p>
                  <p v-if="entry.comment" class="entry-comment">{{ entry.comment }}</p>
                  <small>{{ entry.acted_by?.name || t('common.none') }} · {{ dateTime(entry.acted_at) }}</small>
                </div>
              </li>
            </ol>
          </section>

          <ApprovalTrail :approvals="request.approvals ?? []" />
        </div>

        <aside class="side-column">
          <section class="card attachments">
            <h3>{{ t('attachments.title') }}</h3>
            <p v-if="!request.attachments?.length" class="state">{{ t('requestDetail.noAttachments') }}</p>
            <ul v-else>
              <li v-for="attachment in request.attachments" :key="attachment.id">
                <strong class="file-name ltr">{{ attachment.original_name }}</strong>
                <small>{{ attachment.label || attachment.mime_type }} · {{ fileSize(attachment.size_bytes) }}</small>
                <div class="attachment-actions">
                  <button
                    v-if="isPreviewable(attachment)"
                    class="ghost"
                    type="button"
                    @click="openAttachmentPreview(attachment)"
                  >{{ t('attachments.preview') }}</button>
                  <button v-else class="ghost" type="button" @click="downloadAttachment(attachment)">{{ t('attachments.download') }}</button>
                </div>
              </li>
            </ul>
            <FileUpload v-can="'notes_attachments.add'" :request-id="request.id" @uploaded="load" />
          </section>

          <section class="card"><RequestNotes :request-id="request.id" /></section>
        </aside>
      </div>

      <Teleport to="body">
        <div v-if="selectedAttachment" class="modal-backdrop" @click.self="closeAttachmentPreview">
          <section class="attachment-modal" role="dialog" aria-modal="true" :aria-label="t('attachments.preview')">
            <div class="modal-actions">
              <strong class="file-name ltr">{{ selectedAttachment.original_name }}</strong>
              <button class="ghost" type="button" :disabled="attachmentPreviewing" @click="closeAttachmentPreview">×</button>
            </div>
            <p v-if="attachmentPreviewing" class="state">{{ t('common.loading') }}</p>
            <p v-else-if="attachmentPreviewError" class="action-error" role="alert">{{ attachmentPreviewError }}</p>
            <img v-else-if="attachmentPreviewUrl && isImage(selectedAttachment)" class="attachment-image" :src="attachmentPreviewUrl" :alt="selectedAttachment.original_name" />
            <iframe v-else-if="attachmentPreviewUrl" class="attachment-pdf" :src="attachmentPreviewUrl" :title="selectedAttachment.original_name" />
          </section>
        </div>

        <div v-if="selectedException" class="modal-backdrop" @click.self="closeException">
          <section
            class="reason-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="exception-title"
          >
            <h3 id="exception-title">
              {{ t('requestDetail.exceptionReasonTitle', { action: actionLabel(selectedException.action) }) }}
            </h3>
            <p>{{ t('requestDetail.exceptionReasonHint') }}</p>
            <form @submit.prevent="submitException">
              <label>
                {{ t('requestDetail.reason') }}
                <textarea
                  v-model="exceptionReason"
                  rows="4"
                  maxlength="5000"
                  required
                  autofocus
                  :disabled="acting"
                />
              </label>
              <p v-if="exceptionError" class="action-error" role="alert">{{ exceptionError }}</p>
              <div class="modal-actions">
                <button class="ghost" type="button" :disabled="acting" @click="closeException">
                  {{ t('common.cancel') }}
                </button>
                <button
                  class="exception-button"
                  :class="{ destructive: ['reject_review', 'cancel'].includes(selectedException.action) }"
                  type="submit"
                  :disabled="acting || !exceptionReason.trim()"
                >
                  {{ acting ? t('requestDetail.processing') : t('requestDetail.confirmException') }}
                </button>
              </div>
            </form>
          </section>
        </div>
      </Teleport>
    </template>
  </section>
</template>

<style scoped>
.detail { max-inline-size: 82rem; }.back { display: inline-block; margin-bottom: .85rem; color: var(--color-brand-text); font-size: .85rem; text-decoration: none; }.back:hover { text-decoration: underline; }.heading { display: flex; align-items: start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }.heading h2 { margin: .15rem 0 0; color: var(--color-brand-text); font-size: clamp(1.25rem, 3vw, 1.7rem); }.reference { margin: 0; color: var(--color-muted); font-family: var(--font-mono); font-size: .8rem; }.status { display: inline-flex; align-items: center; gap: .4rem; flex: none; padding: .35rem .55rem; border-radius: var(--radius-full); color: var(--color-black-700); background: var(--color-surface-hover); font-size: .82rem; }.status::before { content: ''; inline-size: .55rem; block-size: .55rem; border-radius: 50%; background: var(--status-color); }.sla-alert { padding: .75rem .9rem; margin: 0 0 1rem; border: 1px solid var(--color-warning-border); border-radius: var(--radius-lg); color: var(--color-warning-fg); background: var(--color-warning-bg); font-size: .86rem; }.timeliness { display: inline-flex; align-items: center; gap: .4rem; padding: .3rem .5rem; border-radius: var(--radius-full); font-size: .8rem; font-weight: 600; }.timeliness::before { content: ''; inline-size: .5rem; block-size: .5rem; border-radius: 50%; background: currentColor; }.timeliness.level-green { color: var(--color-success-fg); background: var(--color-success-bg); border: 1px solid var(--color-success-border); }.timeliness.level-yellow { color: var(--color-warning-fg); background: var(--color-warning-bg); border: 1px solid var(--color-warning-border); }.timeliness.level-red { color: var(--color-danger-fg); background: var(--color-danger-bg); border: 1px solid var(--color-danger-border); }.timeliness.level-critical { color: var(--color-on-brand); background: var(--color-danger-fg); border: 1px solid var(--color-danger-fg); }.card { padding: 1.1rem; }.summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 1rem; margin-bottom: 1rem; }.summary div { display: grid; gap: .2rem; }.summary span { color: var(--color-muted); font-size: .76rem; }.summary strong { color: var(--color-black-700); font-size: .88rem; }.action-panel { margin-bottom: 1rem; }.action-panel h3, .description h3, .timeline h3, .attachments h3, .committee-summary h3 { margin: 0 0 .45rem; color: var(--color-brand-text); font-size: 1rem; }.committee-summary { margin-bottom: 0; }.action-panel > p { margin: 0 0 .75rem; color: var(--color-muted); font-size: .83rem; }.action-panel label, .reason-modal label { display: grid; gap: .3rem; max-inline-size: 40rem; font-size: .85rem; }.action-panel textarea, .reason-modal textarea { padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); resize: vertical; font: inherit; }.action-buttons, .attachment-actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .75rem; }.primary, .exception-button { padding: .5rem .9rem; border: 0; border-radius: var(--radius-lg); color: var(--color-on-brand); background: var(--color-brand); cursor: pointer; }.primary:disabled, .exception-button:disabled, .ghost:disabled { cursor: not-allowed; opacity: .6; }.exception-actions { padding-top: .85rem; margin-top: .9rem; border-top: 1px solid var(--color-border); }.exception-actions > p { margin: 0; color: var(--color-muted); font-size: .8rem; }.exception-button { color: var(--color-warning-fg); background: var(--color-warning-bg); border: 1px solid var(--color-warning-border); }.exception-button.destructive { color: var(--color-danger-fg); background: var(--color-danger-bg); border-color: var(--color-danger-border); }.action-error, .alert { color: var(--color-danger-fg); }.action-error { margin: .6rem 0 0; font-size: .84rem; }.alert { padding: .75rem; border: 1px solid var(--color-danger-border); border-radius: var(--radius-lg); color: var(--color-danger-fg); background: var(--color-danger-bg); }.ghost { margin-inline-start: .5rem; padding: .35rem .55rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); color: var(--color-black-700); background: var(--color-surface); cursor: pointer; }.financial-impact-toggle { font-weight: normal; font-size: .76rem; }.columns { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(18rem, .85fr); gap: 1rem; align-items: start; }.main-column, .side-column { display: grid; gap: 1rem; }.description p { margin: 0; color: var(--color-black-700); line-height: 1.75; white-space: pre-wrap; }.timeline ol { display: grid; gap: 0; padding: 0; margin: .9rem 0 0; list-style: none; }.timeline li { position: relative; display: grid; grid-template-columns: 1.2rem minmax(0, 1fr); gap: .6rem; padding-bottom: 1rem; }.timeline li:not(:last-child)::before { content: ''; position: absolute; inset-inline-start: .45rem; inset-block-start: .85rem; inline-size: 1px; block-size: calc(100% - .25rem); background: var(--color-border); }.dot { position: relative; z-index: 1; inline-size: .9rem; block-size: .9rem; margin-top: .15rem; border: 3px solid var(--color-surface); border-radius: 50%; background: var(--color-primary); box-shadow: 0 0 0 1px var(--color-border-hover); }.timeline p { margin: .2rem 0; color: var(--color-black-700); font-size: .85rem; }.timeline small, .attachments small, .state { color: var(--color-muted); font-size: .78rem; }.entry-comment { white-space: pre-wrap; }.attachments ul { display: grid; gap: .65rem; padding: 0; margin: .85rem 0; list-style: none; }.attachments li { display: grid; gap: .15rem; padding-bottom: .65rem; border-bottom: 1px solid var(--color-border); }.file-name { overflow-wrap: anywhere; color: var(--color-black-700); font-size: .83rem; }.modal-backdrop { position: fixed; z-index: 1000; inset: 0; display: grid; place-items: center; padding: 1rem; background: var(--color-overlay); }.reason-modal, .attachment-modal { inline-size: min(32rem, 100%); padding: 1.2rem; border: 1px solid var(--color-border); border-radius: var(--radius-xl); background: var(--color-surface); box-shadow: var(--shadow-2xl); }.attachment-modal { inline-size: min(64rem, 100%); max-block-size: calc(100vh - 2rem); overflow: auto; }.attachment-image, .attachment-pdf { display: block; inline-size: 100%; max-block-size: 72vh; border: 0; object-fit: contain; }.attachment-pdf { block-size: 72vh; }.reason-modal h3 { margin: 0; color: var(--color-brand-text); }.reason-modal > p { margin: .35rem 0 1rem; color: var(--color-muted); font-size: .84rem; }.reason-modal label { max-inline-size: none; }.modal-actions { display: flex; justify-content: flex-end; gap: .5rem; margin-top: 1rem; }.modal-actions .ghost { margin: 0; }@media (max-width: 720px) { .columns { grid-template-columns: 1fr; }.heading { flex-direction: column; }.summary { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
