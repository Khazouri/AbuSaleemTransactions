<script setup>
/** Decision wizard — sub-project 3. Art. 105's consequence; each outcome carries its own routing. */
import { useI18n } from 'vue-i18n'
import { SUSPENSION_RESOLUTIONS } from '../../lib/controlGates'

defineProps({ request: { type: Object, required: true }, act: { type: Object, required: true } })
const form = defineModel({ type: Object, required: true })
const { t } = useI18n()
</script>

<template>
  <div class="gate-form">
    <p class="hint">{{ t('controlGates.suspension.liftNote') }}</p>
    <label>
      <span>{{ t('controlGates.suspension.fields.resolution_action') }} *</span>
      <select v-model="form.resolution_action">
        <option v-for="action in SUSPENSION_RESOLUTIONS" :key="action" :value="action">
          {{ t(`controlGates.suspension.resolutions.${action}`) }}
        </option>
      </select>
    </label>
    <label>
      <span>{{ t('controlGates.suspension.fields.resolution_note') }}</span>
      <textarea v-model="form.resolution_note" rows="3" />
    </label>
  </div>
</template>
