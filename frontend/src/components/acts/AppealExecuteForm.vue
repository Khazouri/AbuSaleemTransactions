<script setup>
/** Decision wizard — sub-project 3. Stage 64's execution of the committee's recorded outcome. */
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../../lib/api'

const props = defineProps({ appeal: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t, locale } = useI18n()

const stages = ref([])
const loadError = ref('')
onMounted(async () => {
  if (props.appeal.committee_decision?.outcome !== 'appeal_redo') return
  try {
    const { data } = await api.get('/appeals/redo-stage-options')
    stages.value = data.data ?? []
  } catch {
    loadError.value = t('appeals.execution.loadStagesFailed')
  }
})
</script>

<template>
  <div class="gate-form">
    <p class="hint">{{ t('appeals.execution.hint') }}</p>
    <p><strong>{{ t('appeals.execution.decidedOutcome') }}:</strong> {{ t(`decisions.outcome.${appeal.committee_decision?.outcome}`) }}</p>
    <template v-if="appeal.committee_decision?.outcome === 'appeal_redo'">
      <label>
        <span>{{ t('appeals.execution.redoStage') }} *</span>
        <select v-model="form.redo_stage_id" required>
          <option value="" disabled>{{ t('appeals.execution.chooseStage') }}</option>
          <option v-for="stage in stages" :key="stage.id" :value="stage.id">{{ locale === 'ar' ? stage.name_ar : stage.name_en }}</option>
        </select>
      </label>
      <p class="hint">{{ t('appeals.execution.redoHint') }}</p>
      <p v-if="loadError" class="alert warning" role="alert">{{ loadError }}</p>
    </template>
  </div>
</template>
