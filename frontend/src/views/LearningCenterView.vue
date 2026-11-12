<script setup>
// Learning centre — narrated walkthroughs.
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
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

// The duration is read from the file itself, off-DOM, so the person never
// types it and the server never has to probe the container.
function onPick(event) {
  const picked = event.target.files?.[0] ?? null
  file.value = null
  duration.value = null
  formError.value = null
  if (!picked) return
  if (maxBytes.value && picked.size > maxBytes.value) {
    formError.value = t('learningCenter.tooLarge', { limit: mb(maxBytes.value) })
    event.target.value = ''
    return
  }
  const probe = document.createElement('video')
  probe.preload = 'metadata'
  const objectUrl = URL.createObjectURL(picked)
  probe.onloadedmetadata = () => {
    duration.value = Math.max(1, Math.round(probe.duration))
    file.value = picked
    URL.revokeObjectURL(objectUrl)
  }
  probe.onerror = () => {
    formError.value = t('learningCenter.unreadable')
    URL.revokeObjectURL(objectUrl)
  }
  probe.src = objectUrl
}

async function upload() {
  if (!file.value || !duration.value) return
  uploading.value = true
  formError.value = null
  const body = new FormData()
  body.append('file', file.value)
  body.append('duration_seconds', duration.value)
  try {
    await api.post(`/learning-center/${target.value.id}/file`, body)
    cancelUpload()
    await load()
  } catch (e) {
    const errors = e?.response?.data?.errors
    formError.value = (errors && Object.values(errors).flat()[0]) || e?.response?.data?.message || t('learningCenter.uploadFailed')
  } finally {
    uploading.value = false
  }
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

    <VideoPlayerModal v-if="playing" :video="playing" @close="playing = null" />
  </section>
</template>

<style scoped>
.view-tabs { display: flex; flex-wrap: wrap; gap: var(--space-2); margin-bottom: var(--space-4); }
.group { margin-bottom: var(--space-6, 1.5rem); }
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
/* The triangle's visual centre sits off its box; nudge it, mirrored in RTL. */
.play svg { margin-inline-start: 2px; }
:root[dir='rtl'] .play svg { transform: scaleX(-1); }
.meta { display: flex; flex-direction: column; gap: 0.25rem; min-inline-size: 0; }
.desc { font-size: var(--text-sm); color: var(--color-muted); overflow-wrap: anywhere; }
.dur { font-size: var(--text-xs); color: var(--color-muted); font-variant-numeric: tabular-nums; }
.code { display: block; color: var(--color-muted); }
.row-actions { white-space: nowrap; }
.empty { text-align: center; color: var(--color-muted); }
</style>
