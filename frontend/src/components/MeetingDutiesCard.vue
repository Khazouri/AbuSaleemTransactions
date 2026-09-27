<script setup>
/**
 * Decision wizard — sub-project 2. The one way into a meeting's duties: every
 * meeting screen renders this card, which appears only when the signed-in
 * member has something to do on the meeting (or something held back), and
 * opens MeetingWizard. «المهام المعلقة» arrives with ?decide=1 to open it once.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import MeetingWizard from './MeetingWizard.vue'
import api from '../lib/api'

const props = defineProps({ meetingId: { type: [Number, String], default: '' } })
const emit = defineEmits(['updated'])
const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const duties = ref(null)
const open = ref(false)
let autoOpened = false
const hasDuties = computed(() => Boolean(duties.value?.available?.length || duties.value?.blocked?.length))

async function load() {
  duties.value = null
  if (!props.meetingId) return
  try {
    const { data } = await api.get(`/meetings/${props.meetingId}/duties`)
    duties.value = data.data
    // Once only: a reload after acting must not reopen it.
    if (!autoOpened && route.query.decide && hasDuties.value) {
      autoOpened = true
      open.value = true
    }
  } catch {
    duties.value = null
  }
}
watch(() => props.meetingId, load, { immediate: true })

function close() {
  open.value = false
  if (route.query.decide) {
    const { decide, ...query } = route.query
    router.replace({ query })
  }
}

async function onUpdated() {
  emit('updated')
  await load()
}
</script>

<template>
  <section v-if="hasDuties" class="card card-flat card-pad meeting-duties">
    <p>{{ t('decisionWizard.meetingPrompt') }}</p>
    <button class="primary" type="button" @click="open = true">{{ t('decisionWizard.act') }}</button>
  </section>
  <MeetingWizard v-if="open && duties" :meeting-id="meetingId" :duties="duties" @updated="onUpdated" @close="close" />
</template>

<style scoped>
.meeting-duties { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3); margin-block-end: var(--space-4); border-inline-start: 3px solid var(--color-brand); }
.meeting-duties p { margin: 0; color: var(--color-black-700); }
</style>
