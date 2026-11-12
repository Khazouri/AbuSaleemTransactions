<script setup>
// Learning centre — narrated walkthroughs. Self-contained: it asks for its own
// signed URL, so the learning-centre screen and the top bar's «شاهد الشرح»
// open it the same way.
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import AppModal from './AppModal.vue'

const props = defineProps({ video: { type: Object, required: true } })
const emit = defineEmits(['close'])

const { t, locale } = useI18n()
const url = ref(null)
const failed = ref(false)

const title = computed(() => (locale.value === 'en' ? props.video.title_en || props.video.title_ar : props.video.title_ar))

async function fetchUrl() {
  const { data } = await api.get(`/learning-center/${props.video.id}/play`)
  url.value = data.url
}

onMounted(async () => {
  try {
    await fetchUrl()
  } catch {
    failed.value = true
  }
})

// A signed URL lives 2 h and the stream re-checks the grant, so a failing <video>
// first gets one fresh URL; a second failure means the clip really is unavailable.
let retried = false
async function onVideoError() {
  if (retried) {
    failed.value = true
    return
  }
  retried = true
  try {
    await fetchUrl()
  } catch {
    failed.value = true
  }
}
</script>

<template>
  <AppModal :title="title" wide @close="emit('close')">
    <p v-if="failed" class="alert">{{ t('learningCenter.playFailed') }}</p>
    <video v-else-if="url" class="player" controls autoplay playsinline :src="url" @error="onVideoError" />
    <p v-else class="hint">{{ t('common.loading') }}</p>
    <div class="modal-actions">
      <button class="ghost" type="button" @click="emit('close')">{{ t('common.close') }}</button>
    </div>
  </AppModal>
</template>

<style scoped>
.player {
  display: block;
  inline-size: 100%;
  max-block-size: 70vh;
  background: var(--color-surface);
  border-radius: var(--radius-md);
}
</style>
