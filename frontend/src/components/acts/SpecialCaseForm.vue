<script setup>
/** Decision wizard — sub-project 3. Appendix 60 — one case with its own determinations. */
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { HALTING_CASES, SPECIAL_CASES } from '../../lib/lifecycle'

defineProps({ request: { type: Object, required: true }, act: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()

const selectedCase = computed(() => SPECIAL_CASES.find((entry) => entry.code === form.value.case_kind))
const caseCanHalt = computed(() => HALTING_CASES.includes(form.value.case_kind))

// Each case asks different questions, so its answers are not carried across.
watch(() => form.value.case_kind, () => {
  form.value.determinations = {}
  form.value.halt_progress = false
})
</script>

<template>
  <div class="gate-form">
    <label>
      <span>{{ t('lifecycle.specialCases.fields.case_kind') }} *</span>
      <select v-model="form.case_kind">
        <option v-for="entry in SPECIAL_CASES" :key="entry.code" :value="entry.code">{{ t(`lifecycle.specialCases.kinds.${entry.code}`) }}</option>
      </select>
    </label>
    <label v-for="field in selectedCase.fields" :key="field.code">
      <span>{{ t(`lifecycle.specialCases.fields.${field.code}`) }} *</span>
      <select v-if="field.options" v-model="form.determinations[field.code]">
        <option v-for="option in field.options" :key="option" :value="option">
          {{ t(`lifecycle.specialCases.options.${field.code}.${option}`) }}
        </option>
      </select>
      <input v-else v-model="form.determinations[field.code]" required>
    </label>
    <!-- "يوقف الانتقال للمرحلة التالية عند الحاجة" — declared, not automatic. -->
    <label v-if="caseCanHalt" class="inline">
      <input v-model="form.halt_progress" type="checkbox">
      <span>{{ t('lifecycle.specialCases.haltProgress') }}</span>
    </label>
    <p v-if="form.case_kind === 'document_lost'" class="hint">{{ t('lifecycle.specialCases.lostDocumentRule') }}</p>
    <label>
      <span>{{ t('lifecycle.specialCases.fields.detail') }}</span>
      <textarea v-model="form.detail" rows="2" />
    </label>
  </div>
</template>
