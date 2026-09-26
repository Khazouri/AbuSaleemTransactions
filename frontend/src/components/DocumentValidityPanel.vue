<script setup>
// [D] Appendix 31 — التحقق من صحة المستندات, answered by صاحب العلاقة's direct
// manager before «موافقة وإحالة» (user decision 2026-09-26). The rows and the
// refusal come from the request's own `control_gates.document_validity`, the
// same predicate the forward endpoint enforces, so this card and the refused
// button can never disagree.
import { reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import { VALIDITY_ANSWERS, VALIDITY_CHECKS } from '../lib/lifecycle'

const props = defineProps({
  requestId: { type: [Number, String], required: true },
  // [{ attachment_id, original_name, card }]
  rows: { type: Array, default: () => [] },
  // Why forward is refused right now; null means every document passed.
  refusal: { type: String, default: null },
})
const emit = defineEmits(['updated'])

const { t } = useI18n()

const form = reactive({ attachmentId: null, checks: {} })
const saving = ref(false)
const error = ref('')

function start(row) {
  form.attachmentId = row.attachment_id
  form.checks = Object.fromEntries(VALIDITY_CHECKS.map(({ code }) => [
    code,
    row.card?.checks?.find((check) => check.key === code)?.answer ?? 'yes',
  ]))
  error.value = ''
}

async function submit() {
  saving.value = true
  error.value = ''
  try {
    await api.patch(`/requests/${props.requestId}/attachments/${form.attachmentId}/validity`, { checks: { ...form.checks } })
    form.attachmentId = null
    emit('updated')
  } catch (requestError) {
    const errors = requestError.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat().join(' — ')
      : (requestError.response?.data?.message ?? t('lifecycle.error'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="gate-panel">
    <p v-if="refusal" class="alert warning">{{ refusal }}</p>
    <p v-else-if="rows.length" class="alert success">{{ t('controlGates.documentValidity.passed') }}</p>
    <p v-else class="hint">{{ t('controlGates.documentValidity.noDocuments') }}</p>

    <ul v-if="rows.length" class="rounds">
      <li v-for="row in rows" :key="row.attachment_id">
        <p class="round-head">
          <strong class="ltr-name">{{ row.original_name }}</strong>
          <span v-if="row.card" :class="['verdict', row.card.verdict]">
            {{ t(`lifecycle.validity.verdicts.${row.card.verdict}`) }}
          </span>
          <span v-else class="verdict unchecked">{{ t('lifecycle.validity.unchecked') }}</span>
        </p>
        <button
          v-if="form.attachmentId !== row.attachment_id"
          class="btn btn-sm primary"
          type="button"
          @click="start(row)"
        >
          {{ row.card ? t('lifecycle.validity.recheckAction') : t('lifecycle.validity.action') }}
        </button>

        <form v-else class="gate-form" @submit.prevent="submit">
          <label v-for="check in VALIDITY_CHECKS" :key="check.code">
            <span>{{ t(`lifecycle.validity.checks.${check.code}`) }}</span>
            <select v-model="form.checks[check.code]">
              <option
                v-for="answer in VALIDITY_ANSWERS"
                :key="answer"
                :value="answer"
                :disabled="answer === 'not_applicable' && !check.conditional"
              >
                {{ t(`lifecycle.validity.answers.${answer}`) }}
              </option>
            </select>
          </label>
          <p v-if="error" class="alert warning" role="alert">{{ error }}</p>
          <div class="actions">
            <button class="primary" type="submit" :disabled="saving">{{ t('lifecycle.save') }}</button>
            <button class="ghost" type="button" :disabled="saving" @click="form.attachmentId = null">{{ t('lifecycle.cancel') }}</button>
          </div>
        </form>
      </li>
    </ul>
  </div>
</template>

<style scoped>
/* A filename is Latin text inside an RTL row: isolate it without the global
   .ltr helper's text-align, which throws it to the far side (see
   TimelineDocuments). */
.ltr-name {
  direction: ltr;
  unicode-bidi: isolate;
}
.verdict.sound {
  color: var(--color-success-fg);
}
.verdict.doubtful {
  color: var(--color-danger-fg);
}
.verdict.unchecked {
  color: var(--color-black-500);
}
</style>
