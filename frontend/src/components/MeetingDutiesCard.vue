<script setup>
/**
 * Decision wizard — sub-project 2. The one way into a meeting's duties: every
 * meeting screen renders this card, which appears only when the signed-in
 * member has something to do on the meeting (or something held back), and
 * opens MeetingWizard. «المهام المعلقة» arrives with ?decide=1 to open it once.
 *
 * `autoOpen` is false on meeting_live when the URL also names an item: there
 * `decide=1` belongs to AgendaItemWizard instead, and this card must neither
 * open on it nor consume it (final-review F1 — two wizards were opening
 * stacked for the chair). `refreshKey` lets a host force a re-fetch when an
 * act taken elsewhere can change what this card offers — e.g. the item
 * wizard recording the meeting's last decision unblocks `generate_minutes`
 * here, which a poll-only reload would otherwise miss (F3).
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import MeetingWizard from './MeetingWizard.vue'
import api from '../lib/api'

const props = defineProps({
  meetingId: { type: [Number, String], default: '' },
  autoOpen: { type: Boolean, default: true },
  refreshKey: { type: [String, Number], default: '' },
})
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
    // Once only, and only when this card owns `decide` (F1).
    if (!autoOpened && props.autoOpen && route.query.decide && hasDuties.value) {
      autoOpened = true
      open.value = true
    }
  } catch {
    duties.value = null
  }
}
watch(() => props.meetingId, load, { immediate: true })
watch(() => props.refreshKey, load)

// F3(a) — an act taken elsewhere can change what is offered; refresh before
// opening so the wizard the click opens is never a stale list.
async function act() {
  await load()
  open.value = true
}

function close() {
  open.value = false
  // Only a card that actually consumed `decide` may strip it — one that
  // never auto-opened (autoOpen=false, or opened by the button instead)
  // leaves it alone for whoever else owns it (F1).
  if (autoOpened && route.query.decide) {
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
    <button class="primary" type="button" @click="act">{{ t('decisionWizard.act') }}</button>
  </section>
  <MeetingWizard v-if="open && duties" :meeting-id="meetingId" :duties="duties" @updated="onUpdated" @close="close" />
</template>

<style scoped>
.meeting-duties { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3); margin-block-end: var(--space-4); border-inline-start: 3px solid var(--color-brand); }
.meeting-duties p { margin: 0; color: var(--color-black-700); }
</style>
