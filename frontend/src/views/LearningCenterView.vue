<script setup>
// Learning centre — narrated walkthroughs.
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import { matchVideoFiles } from '../lib/bulkVideoUpload'
import { confirmDelete } from '../lib/confirm'
import { useScreensStore } from '../stores/screens'
import AppModal from '../components/AppModal.vue'
import VideoPlayerModal from '../components/VideoPlayerModal.vue'

const { t, locale } = useI18n()
const screensStore = useScreensStore()

const videos = ref([])
const manage = ref(null)
const maxBytes = ref(0)
const loading = ref(true)
const loadError = ref(false)
const tab = ref('videos')
const playing = ref(null)

// Upload form state.
const target = ref(null)
const file = ref(null)
const duration = ref(null)
const uploading = ref(false)
const formError = ref(null)
const actionError = ref(null)

const en = computed(() => locale.value === 'en')
const titleOf = (v) => (en.value ? v.title_en || v.title_ar : v.title_ar)
const descOf = (v) => (en.value ? v.description_en || v.description_ar : v.description_ar)

const mb = (bytes) => `${+(bytes / 1048576).toFixed(1)} ${t('learningCenter.mb')}`
const clock = (s) => (s ? `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}` : '—')
const when = (iso) => (iso ? new Date(iso).toLocaleDateString(en.value ? 'en-GB' : 'ar-LY') : '—')

const screenOf = (code) => screensStore.screens.find((s) => s.code === code)
const screenName = (code) => {
  const s = screenOf(code)
  return s ? (en.value ? s.name_en || s.name_ar : s.name_ar) || code : code
}

// Grouped by the sidebar group of the video's screen, in catalogue order; a
// screen with no group falls under «عام».
const groups = computed(() => {
  const map = new Map()
  for (const v of videos.value) {
    const key = screenOf(v.screen_code)?.group || ''
    if (!map.has(key)) map.set(key, [])
    map.get(key).push(v)
  }
  return Array.from(map, ([key, items]) => ({
    key,
    heading: key ? t(`nav.groups.${key}`) : t('learningCenter.general'),
    items,
  }))
})

async function load() {
  loading.value = true
  loadError.value = false
  try {
    await screensStore.fetchScreens()
    const { data } = await api.get('/learning-center')
    videos.value = data.data
    manage.value = data.manage
    maxBytes.value = data.max_upload_bytes
  } catch {
    loadError.value = true
  } finally {
    loading.value = false
  }
}

function startUpload(video) {
  target.value = video
  file.value = null
  duration.value = null
  formError.value = null
}

function cancelUpload() {
  target.value = null
}

const tooLarge = (f) => maxBytes.value && f.size > maxBytes.value

// The duration is read from the file itself, off-DOM, so the person never
// types it and the server never has to probe the container.
function readDuration(picked) {
  return new Promise((resolve, reject) => {
    const probe = document.createElement('video')
    probe.preload = 'metadata'
    const objectUrl = URL.createObjectURL(picked)
    probe.onloadedmetadata = () => {
      URL.revokeObjectURL(objectUrl)
      // A stream or odd container can report Infinity/NaN; Save would then do nothing, silently.
      if (Number.isFinite(probe.duration)) resolve(Math.max(1, Math.round(probe.duration)))
      else reject(new Error(t('learningCenter.unreadable')))
    }
    probe.onerror = () => {
      URL.revokeObjectURL(objectUrl)
      reject(new Error(t('learningCenter.unreadable')))
    }
    probe.src = objectUrl
  })
}

const errorOf = (e) => {
  const errors = e?.response?.data?.errors
  return (errors && Object.values(errors).flat()[0]) || e?.response?.data?.message || e?.message || t('learningCenter.uploadFailed')
}

const sendVideo = (video, picked, seconds) => {
  const body = new FormData()
  body.append('file', picked)
  body.append('duration_seconds', seconds)
  return api.post(`/learning-center/${video.id}/file`, body)
}

async function onPick(event) {
  const picked = event.target.files?.[0] ?? null
  file.value = null
  duration.value = null
  formError.value = null
  if (!picked) return
  if (tooLarge(picked)) {
    formError.value = t('learningCenter.tooLarge', { limit: mb(maxBytes.value) })
    event.target.value = ''
    return
  }
  try {
    duration.value = await readDuration(picked)
    file.value = picked
  } catch (e) {
    formError.value = e.message
  }
}

async function upload() {
  if (!file.value || !duration.value) return
  uploading.value = true
  formError.value = null
  try {
    await sendVideo(target.value, file.value, duration.value)
    cancelUpload()
    await load()
  } catch (e) {
    formError.value = errorOf(e)
  } finally {
    uploading.value = false
  }
}

// Bulk upload — one pick of the recorded clips, each sent to the row its name matches.
const bulkOpen = ref(false)
const bulk = reactive({ matched: [], skipped: [], failed: [], done: 0, total: 0, current: '', running: false, finished: false })

function openBulk() {
  Object.assign(bulk, { matched: [], skipped: [], failed: [], done: 0, total: 0, current: '', running: false, finished: false })
  bulkOpen.value = true
}

// Closing mid-run stops after the file in flight; the loop checks the flag between files.
function closeBulk() {
  bulkOpen.value = false
}

function onBulkPick(event) {
  const { matched, skipped } = matchVideoFiles(Array.from(event.target.files ?? []), manage.value ?? [])
  Object.assign(bulk, { matched, skipped })
}

// One at a time: a 5 MB request each stays well inside the host's limits, and a
// failure costs that file only.
async function runBulk(items) {
  Object.assign(bulk, { failed: [], done: 0, total: items.length, running: true, finished: false })
  for (const item of items) {
    if (!bulkOpen.value) break
    bulk.current = titleOf(item.video)
    try {
      if (tooLarge(item.file)) throw new Error(t('learningCenter.tooLarge', { limit: mb(maxBytes.value) }))
      await sendVideo(item.video, item.file, await readDuration(item.file))
    } catch (e) {
      bulk.failed.push({ ...item, reason: errorOf(e) })
    }
    bulk.done++
  }
  Object.assign(bulk, { running: false, finished: true, current: '' })
  await load()
}

async function removeFile(video) {
  if (!(await confirmDelete(titleOf(video)))) return
  actionError.value = null
  try {
    await api.delete(`/learning-center/${video.id}/file`)
    await load()
  } catch (e) {
    actionError.value = e?.response?.data?.message || t('learningCenter.deleteFailed')
  }
}

onMounted(load)
</script>

<template>
  <section class="page">
    <div class="heading">
      <h2>{{ t('learningCenter.title') }}</h2>
    </div>

    <div v-can="'learning_center.edit'" class="view-tabs" role="group" :aria-label="t('learningCenter.title')">
      <button type="button" class="chip" :class="{ active: tab === 'videos' }" :aria-pressed="tab === 'videos'" @click="tab = 'videos'">
        {{ t('learningCenter.tabs.videos') }}
      </button>
      <button type="button" class="chip" :class="{ active: tab === 'manage' }" :aria-pressed="tab === 'manage'" @click="tab = 'manage'">
        {{ t('learningCenter.tabs.manage') }}
      </button>
    </div>

    <p v-if="loading" class="hint">{{ t('common.loading') }}</p>
    <p v-else-if="loadError" class="alert">{{ t('learningCenter.loadFailed') }}</p>

    <template v-else-if="tab === 'videos' || !manage">
      <p v-if="!videos.length" class="card card-flat card-pad empty">{{ t('learningCenter.empty') }}</p>
      <section v-for="group in groups" :key="group.key" class="group">
        <h3>{{ group.heading }}</h3>
        <div class="cards">
          <button v-for="v in group.items" :key="v.id" type="button" class="card card-hover card-pad video-card" @click="playing = v">
            <span class="play"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg></span>
            <span class="meta">
              <strong>{{ titleOf(v) }}</strong>
              <span v-if="descOf(v)" class="desc">{{ descOf(v) }}</span>
              <span class="dur">{{ clock(v.duration_seconds) }}</span>
            </span>
          </button>
        </div>
      </section>
    </template>

    <template v-else>
      <p v-if="actionError" class="alert">{{ actionError }}</p>
      <div class="toolbar">
        <button class="primary" type="button" @click="openBulk">{{ t('learningCenter.bulk.open') }}</button>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{{ t('learningCenter.col.video') }}</th>
              <th>{{ t('learningCenter.col.screen') }}</th>
              <th>{{ t('learningCenter.col.state') }}</th>
              <th>{{ t('learningCenter.col.size') }}</th>
              <th>{{ t('learningCenter.col.uploaded') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="v in manage" :key="v.id">
              <td>
                {{ titleOf(v) }}
                <small class="code ltr">{{ v.code }}</small>
              </td>
              <td>{{ screenName(v.screen_code) }}</td>
              <td>{{ v.has_file ? t('learningCenter.hasFile') : t('learningCenter.noFile') }}</td>
              <td>{{ v.has_file ? `${mb(v.size_bytes)} · ${clock(v.duration_seconds)}` : '—' }}</td>
              <td>{{ when(v.uploaded_at) }}</td>
              <td class="row-actions">
                <button class="ghost" type="button" @click="startUpload(v)">
                  {{ v.has_file ? t('learningCenter.replace') : t('learningCenter.upload') }}
                </button>
                <button v-if="v.has_file" class="ghost danger" type="button" @click="removeFile(v)">{{ t('learningCenter.removeFile') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <AppModal v-if="target" :title="titleOf(target)" @close="cancelUpload">
      <form class="form" @submit.prevent="upload">
        <p v-if="formError" class="alert">{{ formError }}</p>
        <label>
          <span>{{ t('learningCenter.file') }}<span class="required-mark" aria-hidden="true">*</span></span>
          <input type="file" accept="video/mp4" required @change="onPick" />
          <small class="hint">{{ t('learningCenter.limitHint', { limit: mb(maxBytes) }) }}</small>
          <small v-if="duration" class="hint">{{ t('learningCenter.durationRead', { duration: clock(duration) }) }}</small>
        </label>
        <div class="modal-actions">
          <button class="ghost" type="button" @click="cancelUpload">{{ t('common.cancel') }}</button>
          <button class="primary" type="submit" :disabled="uploading || !file">{{ uploading ? t('common.saving') : t('common.save') }}</button>
        </div>
      </form>
    </AppModal>

    <AppModal v-if="bulkOpen" wide :title="t('learningCenter.bulk.open')" @close="closeBulk">
      <div class="form">
        <template v-if="!bulk.running && !bulk.finished">
          <p class="hint">{{ t('learningCenter.bulk.intro') }}</p>
          <label>
            <span>{{ t('learningCenter.bulk.files') }}</span>
            <input type="file" accept="video/mp4" multiple @change="onBulkPick" />
            <small class="hint">{{ t('learningCenter.bulk.pickHint') }} {{ t('learningCenter.limitHint', { limit: mb(maxBytes) }) }}</small>
          </label>
          <p v-if="bulk.matched.length" class="hint">{{ t('learningCenter.bulk.ready', { count: bulk.matched.length }) }}</p>
        </template>

        <div v-if="bulk.running" class="progress" role="status">
          <progress :value="bulk.done" :max="bulk.total"></progress>
          <span class="count">{{ bulk.done }} / {{ bulk.total }}</span>
          <span class="hint">{{ t('learningCenter.bulk.current', { title: bulk.current }) }}</span>
        </div>

        <template v-if="bulk.finished">
          <p role="status">{{ t('learningCenter.bulk.uploaded', { count: bulk.total - bulk.failed.length }) }}</p>
          <div v-if="bulk.failed.length" class="alert">
            <p>{{ t('learningCenter.bulk.failed') }}</p>
            <ul>
              <li v-for="f in bulk.failed" :key="f.video.id"><span class="ltr">{{ f.file.name }}</span>: {{ f.reason }}</li>
            </ul>
          </div>
        </template>

        <div v-if="bulk.skipped.length && !bulk.running" class="skipped">
          <p class="hint">{{ t('learningCenter.bulk.skipped') }}</p>
          <ul>
            <li v-for="f in bulk.skipped" :key="f.name"><span class="ltr">{{ f.name }}</span></li>
          </ul>
        </div>

        <div class="modal-actions">
          <button class="ghost" type="button" @click="closeBulk">{{ bulk.finished ? t('common.close') : t('common.cancel') }}</button>
          <button v-if="bulk.finished && bulk.failed.length" class="primary" type="button" @click="runBulk(bulk.failed)">
            {{ t('learningCenter.bulk.retry') }}
          </button>
          <button v-if="!bulk.finished && !bulk.running" class="primary" type="button" :disabled="!bulk.matched.length" @click="runBulk(bulk.matched)">
            {{ t('learningCenter.bulk.start') }}
          </button>
        </div>
      </div>
    </AppModal>

    <VideoPlayerModal v-if="playing" :video="playing" @close="playing = null" />
  </section>
</template>

<style scoped>
.view-tabs { display: flex; flex-wrap: wrap; gap: var(--space-2); margin-block-end: var(--space-4); }
.group { margin-block-end: var(--space-6); }
.group h3 { margin-block-end: var(--space-3); color: var(--color-brand-text); }
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 18rem), 1fr)); gap: var(--space-3); }
.video-card {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  text-align: start;
  cursor: pointer;
  color: var(--color-foreground);
  min-inline-size: 0;
}
.play {
  flex: none;
  display: grid;
  place-items: center;
  inline-size: 2.75rem;
  block-size: 2.75rem;
  border-radius: var(--radius-full);
  background: var(--color-brand);
  color: var(--color-on-brand);
}
/* The triangle's visual centre sits off its box; nudge it. Media icons point right in both directions, so a transform, not a logical margin. */
.play svg { transform: translateX(2px); }
.meta { display: flex; flex-direction: column; gap: 0.25rem; min-inline-size: 0; }
.desc { font-size: var(--text-sm); color: var(--color-muted); overflow-wrap: anywhere; }
.dur { font-size: var(--text-xs); color: var(--color-muted); font-variant-numeric: tabular-nums; }
.code { display: block; color: var(--color-muted); }
.row-actions { white-space: nowrap; }
.empty { text-align: center; color: var(--color-muted); }
.toolbar { display: flex; justify-content: flex-end; margin-block-end: var(--space-3); }
.progress { display: grid; gap: var(--space-2); }
.progress progress { inline-size: 100%; accent-color: var(--color-brand); }
.count { font-variant-numeric: tabular-nums; font-weight: 600; }
.skipped ul, .alert ul { margin: 0; padding-inline-start: var(--space-5); max-block-size: 12rem; overflow-y: auto; }
</style>
