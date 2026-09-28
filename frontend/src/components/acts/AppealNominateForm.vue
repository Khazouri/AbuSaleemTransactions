<script setup>
/**
 * Decision wizard — sub-project 3. Putting an appeal on a sitting's agenda,
 * moved out of the agenda builder. Offers the sittings the actor can see
 * whose agenda is still open; the endpoint's own 422 guards the rest.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../../lib/api'

defineProps({ appeal: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t, locale } = useI18n()

const meetings = ref([])
const loading = ref(true)
const open = computed(() => meetings.value.filter((meeting) => ['pending_confirmation', 'scheduled'].includes(meeting.status)
  && !meeting.agenda_adopted_at))
const when = (value) => new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium' }).format(new Date(value))

onMounted(async () => {
  try {
    const { data } = await api.get('/meetings')
    meetings.value = data.data ?? []
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="gate-form">
    <p v-if="loading" class="hint">{{ t('common.loading') }}</p>
    <p v-else-if="!open.length" class="alert warning">{{ t('decisionWizard.appeal.noOpenMeeting') }}</p>
    <label v-else>
      <span>{{ t('decisionWizard.appeal.meeting') }} *</span>
      <select v-model="form.meeting_id" required>
        <option value="" disabled>{{ t('decisionWizard.appeal.chooseMeeting') }}</option>
        <option v-for="meeting in open" :key="meeting.id" :value="meeting.id">
          {{ meeting.title }} — {{ when(meeting.scheduled_at) }}
        </option>
      </select>
    </label>
  </div>
</template>
