<script setup>
/**
 * An attachment's name that previews itself: a mouse hover (or keyboard focus)
 * lifts a sheet showing the file's first page, and a click opens the full
 * preview. Every payload that lists a stored file carries the same
 * original_name / mime_type / preview_url trio, so the request page, the
 * decision wizard and the appeal dossier all share this one
 * component instead of each wiring its own blob fetch.
 *
 * The file is a private stream behind the bearer token, so it is fetched once
 * through `api` into an object URL — a plain <a href> would hit it
 * unauthenticated — and that URL serves both the sheet and the dialog.
 */
import { computed, nextTick, onBeforeUnmount, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import AppIcon from './AppIcon.vue'

const props = defineProps({
  attachment: { type: Object, required: true },
})

// The document gates accept a verdict only on a file its reviewer opened.
const emit = defineEmits(['previewed'])

const { t } = useI18n()
const tipId = useId()

const SHEET_WIDTH = 208
const SHEET_HEIGHT = 340
const GAP = 8
// Long enough that sweeping the pointer down a list doesn't fire a fetch per row.
const HOVER_DELAY_MS = 350

const objectUrl = ref('')
const loading = ref(false)
const failed = ref('')
const tipOpen = ref(false)
const tipStyle = ref({})
const dialogOpen = ref(false)
const trigger = ref(null)
const closeButton = ref(null)
let hoverTimer = null
// Closing the dialog hands focus back to the name; that must not re-open the sheet.
let returningFocus = false

const isImage = computed(() => props.attachment.mime_type?.startsWith('image/'))
const isPdf = computed(() => props.attachment.mime_type === 'application/pdf')
const previewable = computed(() => isImage.value || isPdf.value)
const extension = computed(() => props.attachment.original_name?.split('.').pop()?.toUpperCase() ?? '')
const size = computed(() => fileSize(props.attachment.size_bytes))

function fileSize(bytes) {
  if (!bytes && bytes !== 0) return ''
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 ** 2).toFixed(1)} MB`
}

async function fetchFile() {
  if (objectUrl.value || loading.value) return
  loading.value = true
  failed.value = ''
  try {
    const { data } = await api.get(props.attachment.preview_url, { responseType: 'blob' })
    objectUrl.value = URL.createObjectURL(data)
  } catch (requestError) {
    failed.value = requestError.response?.data?.message ?? t('attachments.previewFailed')
  } finally {
    loading.value = false
  }
}

// The sheet opens below the name, or above it when the viewport has no room,
// and hangs from the name's start edge so it mirrors with the page direction.
function placeTip() {
  const rect = trigger.value.getBoundingClientRect()
  const rtl = document.documentElement.dir === 'rtl'
  const below = window.innerHeight - rect.bottom >= SHEET_HEIGHT + GAP
  const start = rtl ? rect.right - SHEET_WIDTH : rect.left
  const left = Math.min(Math.max(start, GAP), window.innerWidth - SHEET_WIDTH - GAP)
  tipStyle.value = {
    left: `${left}px`,
    top: below ? `${rect.bottom + GAP}px` : `${Math.max(rect.top - SHEET_HEIGHT - GAP, GAP)}px`,
  }
}

function showTip() {
  placeTip()
  tipOpen.value = true
  if (previewable.value) fetchFile()
  window.addEventListener('scroll', hideTip, true)
}

function hideTip() {
  window.clearTimeout(hoverTimer)
  tipOpen.value = false
  window.removeEventListener('scroll', hideTip, true)
}

// Touch has no hover: a tap is a click and goes straight to the dialog.
function onPointerEnter(event) {
  if (event.pointerType !== 'mouse' || dialogOpen.value) return
  hoverTimer = window.setTimeout(showTip, HOVER_DELAY_MS)
}

function onFocus() {
  if (returningFocus) {
    returningFocus = false
    return
  }
  if (!dialogOpen.value && trigger.value.matches(':focus-visible')) showTip()
}

async function openDialog() {
  hideTip()
  dialogOpen.value = true
  // Captured on window so the preview's Escape closes only the preview, not an
  // AppModal (the decision wizard) open underneath it, whose listener sits on document.
  window.addEventListener('keydown', onDialogKeydown, true)
  if (previewable.value) fetchFile()
  reportPreviewed()
  await nextTick()
  closeButton.value?.focus()
}

// Only request files carry `previewed_url`; the server records the open (and
// audits it), so the gate can trust it. A failed report just leaves the file
// unticked, and opening it again retries.
async function reportPreviewed() {
  if (!props.attachment.previewed_url) return
  try {
    await api.post(props.attachment.previewed_url)
    emit('previewed', props.attachment.id)
  } catch {
    // Nothing to show: the gate's own checkbox stays unticked.
  }
}

function closeDialog() {
  dialogOpen.value = false
  window.removeEventListener('keydown', onDialogKeydown, true)
  returningFocus = true
  trigger.value?.focus({ preventScroll: true })
}

function onDialogKeydown(event) {
  if (event.key !== 'Escape') return
  event.stopPropagation()
  closeDialog()
}

function onTriggerKeydown(event) {
  if (event.key === 'Escape') hideTip()
}

async function download() {
  try {
    const { data } = await api.get(props.attachment.preview_url, { responseType: 'blob' })
    const url = URL.createObjectURL(data)
    const link = document.createElement('a')
    link.href = url
    link.download = props.attachment.original_name
    document.body.append(link)
    link.click()
    link.remove()
    window.setTimeout(() => URL.revokeObjectURL(url), 0)
  } catch {
    failed.value = t('attachments.downloadFailed')
  }
}

onBeforeUnmount(() => {
  hideTip()
  window.removeEventListener('keydown', onDialogKeydown, true)
  if (objectUrl.value) URL.revokeObjectURL(objectUrl.value)
})
</script>

<template>
  <button
    ref="trigger"
    class="attachment-link"
    type="button"
    dir="auto"
    :aria-describedby="tipOpen ? tipId : undefined"
    @pointerenter="onPointerEnter"
    @pointerleave="hideTip"
    @focus="onFocus"
    @blur="hideTip"
    @keydown="onTriggerKeydown"
    @click="openDialog"
  >{{ attachment.original_name }}</button>

  <Teleport to="body">
    <Transition name="sheet">
      <div v-if="tipOpen" :id="tipId" class="sheet-tip" role="tooltip" :style="tipStyle">
        <div class="sheet" :class="{ pdf: isPdf && objectUrl }">
          <img v-if="isImage && objectUrl" :src="objectUrl" alt="" />
          <iframe
            v-else-if="isPdf && objectUrl"
            :src="`${objectUrl}#toolbar=0&navpanes=0&scrollbar=0&view=FitH`"
            :title="attachment.original_name"
            tabindex="-1"
          />
          <span v-else-if="loading" class="sheet-state">{{ t('common.loading') }}</span>
          <span v-else-if="failed" class="sheet-state">{{ failed }}</span>
          <span v-else class="sheet-state blank">
            <AppIcon name="file-text" :size="28" />
            <strong class="ltr">{{ extension }}</strong>
            {{ t('attachments.noPreview') }}
          </span>
        </div>
        <p class="sheet-caption"><template v-if="attachment.label">{{ attachment.label }} · </template><bdi>{{ size }}</bdi></p>
      </div>
    </Transition>

    <div v-if="dialogOpen" class="modal-backdrop" @click.self="closeDialog">
      <section class="viewer" role="dialog" aria-modal="true" :aria-label="attachment.original_name">
        <header class="viewer-head">
          <strong class="viewer-name" dir="auto">{{ attachment.original_name }}</strong>
          <button class="ghost" type="button" @click="download">{{ t('attachments.download') }}</button>
          <button ref="closeButton" class="ghost" type="button" @click="closeDialog">{{ t('common.close') }}</button>
        </header>
        <p v-if="loading" class="viewer-state">{{ t('common.loading') }}</p>
        <p v-else-if="failed" class="viewer-state error" role="alert">{{ failed }}</p>
        <img v-else-if="isImage && objectUrl" class="viewer-file" :src="objectUrl" :alt="attachment.original_name" />
        <iframe v-else-if="isPdf && objectUrl" class="viewer-file viewer-pdf" :src="objectUrl" :title="attachment.original_name" />
        <p v-else class="viewer-state">{{ t('attachments.noPreview') }}</p>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.attachment-link {
  padding: 0;
  border: 0;
  background: none;
  color: var(--color-black-700);
  font: inherit;
  font-size: var(--text-sm);
  font-weight: 600;
  text-align: start;
  overflow-wrap: anywhere;
  cursor: zoom-in;
  text-decoration: underline dotted var(--color-border-hover);
  text-underline-offset: 0.2em;
  /* Stored names are «<Arabic document> - <employee number>.pdf», so dir="auto"
     reads them right-to-left; the box itself stays on the row's start edge. */
  justify-self: start;
}
.attachment-link:hover { color: var(--color-brand-text); text-decoration-color: currentColor; }
.attachment-link:focus-visible { outline: 2px solid var(--color-brand-text); outline-offset: 2px; border-radius: var(--radius-sm); }

/* A sheet of paper lifted off the file: the √2 page ratio is the point, so a
   photo sits inside the page rather than reshaping it. */
.sheet-tip {
  position: fixed;
  z-index: 1100;
  inline-size: 13rem;
  pointer-events: none;
}
.sheet {
  display: grid;
  place-items: center;
  aspect-ratio: 1 / 1.414;
  overflow: hidden;
  border: 1px solid var(--color-border-hover);
  border-radius: var(--radius-sm);
  background: var(--color-surface);
  box-shadow: var(--shadow-2xl);
}
.sheet img { inline-size: 100%; block-size: 100%; object-fit: contain; }
/* Pages are not all A4, so the viewer's next page can peek in under the first;
   fading the foot into the sheet hides it and still says the file goes on. */
.sheet.pdf { position: relative; }
.sheet.pdf::after {
  content: '';
  position: absolute;
  inset: auto 0 0;
  block-size: 22%;
  background: linear-gradient(to bottom, transparent, var(--color-surface) 70%);
}
.sheet iframe { inline-size: 100%; block-size: 100%; border: 0; }
.sheet-state {
  display: grid;
  justify-items: center;
  gap: var(--space-1);
  padding: var(--space-3);
  color: var(--color-muted);
  font-size: var(--text-xs);
  text-align: center;
}
.sheet-state.blank strong { color: var(--color-black-700); font-size: var(--text-sm); }
.sheet-caption {
  margin: var(--space-2) 0 0;
  padding: var(--space-1) var(--space-2);
  border-radius: var(--radius-sm);
  background: var(--color-surface);
  box-shadow: var(--shadow-md);
  color: var(--color-black-700);
  font-size: var(--text-xs);
}

.sheet-enter-active { transition: opacity 120ms ease-out, transform 120ms ease-out; }
.sheet-enter-from { opacity: 0; transform: translateY(4px); }
@media (prefers-reduced-motion: reduce) {
  .sheet-enter-active { transition: none; }
}

.viewer {
  display: grid;
  gap: var(--space-3);
  inline-size: min(64rem, 100%);
  max-block-size: calc(100dvh - 2rem);
  padding: var(--space-4);
  overflow: auto;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  background: var(--color-surface);
  box-shadow: var(--shadow-2xl);
}
.viewer-head { display: flex; align-items: center; gap: var(--space-2); }
.viewer-head .ghost { margin: 0; }
.viewer-name {
  flex: 1;
  min-width: 0;
  color: var(--color-black-700);
  font-size: var(--text-base);
  overflow-wrap: anywhere;
  text-align: start;
}
.viewer-file { display: block; inline-size: 100%; max-block-size: 78dvh; border: 0; object-fit: contain; }
.viewer-pdf { block-size: 78dvh; }
.viewer-state { margin: 0; padding: var(--space-6) 0; color: var(--color-muted); text-align: center; }
.viewer-state.error { color: var(--color-danger-fg); }

@media (max-width: 640px) {
  .viewer-head { flex-wrap: wrap; }
  .viewer-name { flex-basis: 100%; }
}
</style>
