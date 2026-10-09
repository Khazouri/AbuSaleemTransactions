<script setup>
/**
 * Stage 82 — النموذج 11's card: [D] Art. 85's nine-step sequence for one
 * agenda item, grouped by Appendix 25's five إلزامية stages. The last two
 * steps are read from the item's votes and decision, so they render read-only.
 * Shared by the live runner and the agenda-item wizard's Checks step
 * (decision wizard, sub-project 2).
 */
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api, { firstError } from '../lib/api'

const props = defineProps({
  meetingId: { type: [Number, String], required: true },
  itemId: { type: [Number, String], required: true },
  canEdit: { type: Boolean, default: false },
  showTitle: { type: Boolean, default: true },
})
const emit = defineEmits(['changed'])
const { t, locale } = useI18n()

const sequence = ref(null)
const busy = ref('')
const error = ref('')

async function load() {
  sequence.value = null
  try {
    const { data } = await api.get(`/meetings/${props.meetingId}/agenda/${props.itemId}/study-sequence`)
    sequence.value = data.data
  } catch {
    sequence.value = null
  }
}
// A string key, so the live runner's 5s poll (same ids) does not refetch.
watch(() => `${props.meetingId}:${props.itemId}`, load, { immediate: true })

async function toggle(step) {
  if (step.mode === 'derived' || !step.applicable) return
  error.value = ''
  busy.value = step.code
  try {
    const { data } = await api.patch(
      `/meetings/${props.meetingId}/agenda/${props.itemId}/study-sequence`,
      { step: step.code, done: !step.done },
    )
    sequence.value = data.data
    // The completion flag opens voting, so the host's agenda payload has to catch up.
    emit('changed')
  } catch (requestError) {
    error.value = firstError(requestError, t('requestDetail.actionFailed'))
  } finally {
    busy.value = ''
  }
}
</script>

<template>
  <section v-if="sequence" class="study-sequence">
    <h3 v-if="showTitle">{{ t('meetingsUnit.live.studySequence.title') }}</h3>
    <p v-if="sequence.material_frozen" class="alert">{{ t('meetingsUnit.live.studySequence.frozen') }}</p>
    <p v-else-if="!sequence.is_complete" class="state">{{ t('meetingsUnit.live.studySequence.incomplete') }}</p>
    <ol class="steps">
      <li
        v-for="step in sequence.steps"
        :key="step.code"
        :class="{ done: step.done, na: !step.applicable, derived: step.mode === 'derived' }"
      >
        <label>
          <input
            type="checkbox"
            :checked="step.done"
            :disabled="step.mode === 'derived' || !step.applicable || busy === step.code || !canEdit"
            @change="toggle(step)"
          />
          <span class="step-name">{{ locale === 'ar' ? step.name_ar : step.name_en }}</span>
          <span class="pill small">{{ locale === 'ar' ? step.stage_name_ar : step.stage_name_en }}</span>
          <span v-if="step.mode === 'derived'" class="pill small">{{ t('meetingsUnit.live.studySequence.derived') }}</span>
          <span v-else-if="step.mode === 'optional'" class="pill small">{{ t('meetingsUnit.live.studySequence.optional') }}</span>
          <span v-else-if="!step.applicable" class="pill small">{{ t('meetingsUnit.live.studySequence.notApplicable') }}</span>
        </label>
      </li>
    </ol>
    <p v-if="error" class="alert" role="alert">{{ error }}</p>
  </section>
</template>

<style scoped>
.study-sequence { padding-top: var(--space-2); margin-bottom: var(--space-3); }
.study-sequence h3 { margin: 0 0 .4rem; font-size: var(--text-lg); color: var(--color-brand-text); }
.steps { list-style: none; margin: .4rem 0 0; padding: 0; display: grid; gap: .3rem; }
.steps li label { display: flex; flex-wrap: wrap; align-items: center; gap: .45rem; font-size: var(--text-base); }
.steps li.done .step-name { font-weight: 600; }
.steps li.na .step-name,
.steps li.derived .step-name { color: var(--color-black-600); }
</style>
