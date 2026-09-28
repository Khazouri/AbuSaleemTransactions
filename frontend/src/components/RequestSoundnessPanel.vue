<script setup>
// Stage 78 — [D] Art. 103's قائمة فحص سلامة القرار, verified "قبل إحالة
// النتيجة للتنفيذ". Read-only since decision wizard sub-project 3: the four
// attested checks are answered in the wizard (acts/SoundnessForm.vue); the
// other eight are derived by the server, per Art. 104.
import { useI18n } from 'vue-i18n'
import { SOUNDNESS_CHECKS, isCertifierCheck } from '../lib/controlGates'

const props = defineProps({
  // The stored twelve-answer card, once one exists.
  record: { type: Object, default: null },
  // The eight the server computes, so the panel can show what it will store.
  derived: { type: Object, default: () => ({}) },
  // Why execution would be refused right now; null means the gate passes.
  refusal: { type: String, default: null },
})

const { t } = useI18n()

function derivedAnswer(key) {
  return props.derived?.[key] === true ? 'yes' : 'no'
}
</script>

<template>
  <div class="gate-panel">
    <p v-if="refusal" class="alert warning">{{ refusal }}</p>
    <p v-else class="alert success">{{ t('controlGates.soundness.passed') }}</p>

    <ul class="checklist">
      <li v-for="key in SOUNDNESS_CHECKS" :key="key">
        <span>{{ t(`controlGates.soundness.checks.${key}`) }}</span>
        <span :class="['answer', record ? record[key] : isCertifierCheck(key) ? 'pending' : derivedAnswer(key)]">
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
  </div>
</template>
