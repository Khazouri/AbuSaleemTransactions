<script setup>
/**
 * Decision wizard — sub-project 3. Art. 103's four attested checks, moved out
 * of RequestSoundnessPanel; the other eight are derived by the server and stay
 * read-only there.
 */
import { useI18n } from 'vue-i18n'
import { SOUNDNESS_ANSWERS, SOUNDNESS_CERTIFIER_CHECKS } from '../../lib/controlGates'

defineProps({ request: { type: Object, required: true }, act: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()
</script>

<template>
  <div class="gate-form">
    <p class="hint">{{ t('controlGates.soundness.formNote') }}</p>
    <label v-for="key in SOUNDNESS_CERTIFIER_CHECKS" :key="key">
      <span>{{ t(`controlGates.soundness.checks.${key}`) }}</span>
      <select v-model="form.checks[key]" required>
        <option value="" disabled>{{ t('controlGates.intake.choose') }}</option>
        <option v-for="answer in SOUNDNESS_ANSWERS" :key="answer" :value="answer">
          {{ t(`controlGates.soundness.answers.${answer}`) }}
        </option>
      </select>
    </label>
  </div>
</template>
