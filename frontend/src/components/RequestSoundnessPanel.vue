<script setup>
// Stage 78 — [D] Art. 103's قائمة فحص سلامة القرار, verified "قبل إحالة
// النتيجة للتنفيذ".
//
// Eight of the twelve are shown read-only: the server derives them from real
// state and does not accept them from a caller at all, which is Art. 104's
// "صحة المستند والاختصاص ليستا إجراءات شكلية، بل عنصران جوهريان في سلامة
// القرار" made literal. Rendering them as inputs would invite someone to
// answer a question their answer cannot change.
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import {
  SOUNDNESS_ANSWERS,
  SOUNDNESS_CERTIFIER_CHECKS,
  SOUNDNESS_CHECKS,
  isCertifierCheck,
} from '../lib/controlGates'

const props = defineProps({
  requestId: { type: [Number, String], required: true },
  // The stored twelve-answer card, once one exists.
  record: { type: Object, default: null },
  // The eight the server computes, so the panel can show what it will store.
  derived: { type: Object, default: () => ({}) },
  // Why execution would be refused right now; null means the gate passes.
  refusal: { type: String, default: null },
})

const emit = defineEmits(['updated'])

const { t } = useI18n()

const open = ref(false)
const saving = ref(false)
const error = ref('')

const checks = reactive({})

function seed() {
  for (const key of SOUNDNESS_CERTIFIER_CHECKS) {
    checks[key] = props.record?.[key] ?? ''
  }
}

watch(open, (isOpen) => {
  error.value = ''
  if (isOpen) seed()
})

const canSubmit = computed(() => SOUNDNESS_CERTIFIER_CHECKS.every((key) => checks[key] !== ''))

function derivedAnswer(key) {
  return props.derived?.[key] === true ? 'yes' : 'no'
}

function describe(requestError) {
  const errors = requestError.response?.data?.errors
  return errors
    ? Object.values(errors).flat().join(' — ')
    : (requestError.response?.data?.message ?? t('controlGates.error'))
}

async function submit() {
  saving.value = true
  error.value = ''
  try {
    const { data } = await api.patch(`/requests/${props.requestId}/execution-soundness`, {
      checks: { ...checks },
    })
    open.value = false
    emit('updated', data.data)
  } catch (requestError) {
    error.value = describe(requestError)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="gate-panel">
    <p v-if="refusal" class="alert warning">{{ refusal }}</p>
    <p v-else class="alert success">{{ t('controlGates.soundness.passed') }}</p>

    <ul class="checklist">
      <li v-for="key in SOUNDNESS_CHECKS" :key="key">
        <span>{{ t(`controlGates.soundness.checks.${key}`) }}</span>
        <span
          :class="['answer', record ? record[key] : isCertifierCheck(key) ? 'pending' : derivedAnswer(key)]"
        >
          {{
            record
              ? t(`controlGates.soundness.answers.${record[key]}`)
              : isCertifierCheck(key)
                ? t('controlGates.soundness.answers.pending')
                : t(`controlGates.soundness.answers.${derivedAnswer(key)}`)
          }}
        </span>
      </li>
    </ul>

    <button
      v-if="!open"
      v-can="'meeting_outputs.edit'"
      class="btn btn-sm primary"
      type="button"
      @click="open = true"
    >
      {{ record ? t('controlGates.soundness.reviseAction') : t('controlGates.soundness.action') }}
    </button>

    <form v-else class="gate-form" @submit.prevent="submit">
      <p class="hint">{{ t('controlGates.soundness.formNote') }}</p>

      <label v-for="key in SOUNDNESS_CERTIFIER_CHECKS" :key="key">
        <span>{{ t(`controlGates.soundness.checks.${key}`) }}</span>
        <select v-model="checks[key]" required>
          <option value="" disabled>{{ t('controlGates.intake.choose') }}</option>
          <option v-for="answer in SOUNDNESS_ANSWERS" :key="answer" :value="answer">
            {{ t(`controlGates.soundness.answers.${answer}`) }}
          </option>
        </select>
      </label>

      <p v-if="error" class="alert warning">{{ error }}</p>

      <div class="actions">
        <button class="primary" type="submit" :disabled="saving || !canSubmit">
          {{ saving ? t('controlGates.saving') : t('controlGates.save') }}
        </button>
        <button class="ghost" type="button" @click="open = false">{{ t('controlGates.cancel') }}</button>
      </div>
    </form>
  </div>
</template>

