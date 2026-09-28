<script setup>
/** Decision wizard — sub-project 3. Art. 105's cause, moved out of RequestSuspensionPanel. */
import { useI18n } from 'vue-i18n'
import { SUSPENSION_GROUNDS } from '../../lib/controlGates'

defineProps({ request: { type: Object, required: true }, act: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()
</script>

<template>
  <div class="gate-form">
    <p class="hint">{{ t('controlGates.suspension.formNote') }}</p>
    <label>
      <span>{{ t('controlGates.suspension.fields.ground') }} *</span>
      <select v-model="form.ground">
        <option v-for="ground in SUSPENSION_GROUNDS" :key="ground" :value="ground">
          {{ t(`controlGates.suspension.grounds.${ground}`) }}
        </option>
      </select>
    </label>
    <label>
      <span>{{ t('controlGates.suspension.fields.detail') }} *</span>
      <textarea v-model="form.detail" rows="3" required />
    </label>
  </div>
</template>
