<script setup>
/**
 * 2026-10-25 — a set of scanned pages shown one at a time in a modal. Each
 * page is fetched through `api` as a blob, because an <img src> cannot carry
 * the bearer token; a page is fetched when first shown and kept until the
 * slideshow closes.
 *
 * `pageUrl(n)` is the API path of page n (from 1), and `filePrefix` names a
 * downloaded page «<prefix>-<n>.<ext>», as the server stored it.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AppModal from './AppModal.vue'
import api from '../lib/api'

const props = defineProps({
  title: { type: String, required: true },
  count: { type: Number, required: true },
  pageUrl: { type: Function, required: true },
  filePrefix: { type: String, required: true },
})
const emit = defineEmits(['close'])
const { t } = useI18n()

const page = ref(1)
const sources = ref({})
const failed = ref(false)
const nextButton = ref(null)
const closeButton = ref(null)

async function load(n) {
  failed.value = false
  if (sources.value[n]) return
  try {
    const { data } = await api.get(props.pageUrl(n), { responseType: 'blob' })
    sources.value = { ...sources.value, [n]: { url: URL.createObjectURL(data), type: data.type } }
  } catch {
    if (page.value === n) failed.value = true
  }
}
watch(page, load, { immediate: true })

const go = (offset) => {
  page.value = Math.min(props.count, Math.max(1, page.value + offset))
}
const downloadName = (n) => `${props.filePrefix}-${n}.${sources.value[n]?.type === 'image/png' ? 'png' : 'jpg'}`

// The arrows follow the page's direction: in Arabic the next page is to the left.
function onKeydown(event) {
  if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return
  const rtl = document.documentElement.dir === 'rtl'
  go((event.key === 'ArrowRight') !== rtl ? 1 : -1)
}

onMounted(() => {
  document.addEventListener('keydown', onKeydown)
  // AppModal focuses its first button, which is the disabled «previous».
  ;(props.count > 1 ? nextButton.value : closeButton.value)?.focus()
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
  Object.values(sources.value).forEach((source) => URL.revokeObjectURL(source.url))
})
</script>

<template>
  <AppModal :title="title" wide @close="emit('close')">
    <div class="slideshow">
      <div class="stage">
        <img v-if="sources[page]" :src="sources[page].url" :alt="t('slideshow.alt', { title, n: page })" class="page-image" />
        <p v-else-if="failed" class="alert" role="alert">{{ t('slideshow.failed') }}</p>
        <p v-else class="state">{{ t('common.loading') }}</p>
      </div>

      <div class="controls">
        <button class="ghost" type="button" :disabled="page === 1" @click="go(-1)">{{ t('slideshow.previous') }}</button>
        <p class="position" aria-live="polite">{{ t('slideshow.position', { n: page, total: count }) }}</p>
        <button ref="nextButton" class="ghost" type="button" :disabled="page === count" @click="go(1)">{{ t('slideshow.next') }}</button>
      </div>

      <div class="footer">
        <a v-if="sources[page]" class="ghost" :href="sources[page].url" :download="downloadName(page)">{{ t('slideshow.download') }}</a>
        <button ref="closeButton" type="button" @click="emit('close')">{{ t('common.close') }}</button>
      </div>
    </div>
  </AppModal>
</template>

<style scoped>
.slideshow { display: grid; gap: var(--space-3); }
.stage {
  display: grid;
  place-items: center;
  min-block-size: 16rem;
  padding: var(--space-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: var(--color-surface-hover);
}
.page-image { max-inline-size: 100%; max-block-size: 70vh; object-fit: contain; }
.controls { display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); }
.position { margin: 0; color: var(--color-black-700); font-size: var(--text-sm); }
.footer { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: var(--space-2); }
</style>
