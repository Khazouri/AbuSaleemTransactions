<script setup>
/**
 * Workflow designer — slice 3. A step: its names, the role shown as
 * its owner, its soft target, and where it sits in the chain («بعد المرحلة»;
 * the save renumbers the version from that order). Slice 4: and its checklist.
 * Slice 5: a built-in step too, minus its place and its checklist (the writer keeps both).
 */
import { reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import ChecklistEditor from './ChecklistEditor.vue'

const props = defineProps({
  stage: { type: Object, default: null },
  after: { type: String, default: null },
  stages: { type: Array, required: true },
  roles: { type: Array, required: true },
  documents: { type: Array, default: () => [] },
  error: { type: String, default: '' },
  saving: { type: Boolean, default: false },
})
const emit = defineEmits(['submit', 'cancel'])
const builtIn = props.stage !== null && !props.stage.custom
const { t, locale } = useI18n()
const name = (item) => (locale.value === 'ar' ? item?.name_ar || item?.name_en : item?.name_en || item?.name_ar)

const form = reactive({
  name_ar: props.stage?.name_ar ?? '',
  name_en: props.stage?.name_en ?? '',
  responsible_role_id: props.stage?.responsible_role?.id ?? null,
  target_days_min: props.stage?.target_days_min ?? null,
  target_days_max: props.stage?.target_days_max ?? null,
  checklist: (props.stage?.checklist ?? []).map((item) => ({ ...item })),
})
const after = reactive({ code: props.after })
// v-model.number leaves an emptied number input as '', which the API reads as a value.
const days = (value) => (value === '' ? null : value)

function submit() {
  emit('submit', {
    fields: { ...form, target_days_min: days(form.target_days_min), target_days_max: days(form.target_days_max), checklist: form.checklist.length ? form.checklist : null },
    after: after.code,
  })
}
</script>

<template>
  <form class="stage-form" @submit.prevent="submit">
    <p v-if="error" class="alert" role="alert">{{ error }}</p>
    <p v-if="builtIn" class="hint">{{ t('workflows.edit.builtinStageHint') }}</p>
    <div class="field-grid">
      <label>
        <span>{{ t('workflows.edit.nameAr') }}<span class="required-mark" aria-hidden="true">*</span></span>
        <input v-model="form.name_ar" required maxlength="255" dir="auto" />
      </label>
      <label>
        {{ t('workflows.edit.nameEn') }}
        <input v-model="form.name_en" maxlength="255" dir="auto" />
      </label>
      <label>
        {{ t('workflows.responsibleRole') }}
        <select v-model="form.responsible_role_id">
          <option :value="null">—</option>
          <option v-for="role in roles" :key="role.id" :value="role.id">{{ name(role) }}</option>
        </select>
      </label>
      <label v-if="!builtIn">
        {{ t('workflows.edit.after') }}
        <select v-model="after.code">
          <option v-for="item in stages.filter((s) => s.code !== stage?.code)" :key="item.code" :value="item.code">
            {{ item.order_no }}. {{ name(item) }}
          </option>
        </select>
      </label>
      <label>
        {{ t('workflows.edit.targetMin') }}
        <input v-model.number="form.target_days_min" type="number" min="0" max="365" />
      </label>
      <label>
        {{ t('workflows.edit.targetMax') }}
        <input v-model.number="form.target_days_max" type="number" min="0" max="365" />
      </label>
    </div>
    <ChecklistEditor v-if="!builtIn" v-model="form.checklist" :documents="documents" />
    <div class="modal-actions">
      <button class="ghost" type="button" @click="emit('cancel')">{{ t('common.cancel') }}</button>
      <button class="primary" type="submit" :disabled="saving">{{ saving ? t('common.saving') : t('common.save') }}</button>
    </div>
  </form>
</template>

<style scoped>
.stage-form { display: grid; gap: var(--space-3); }
.hint { margin: 0; color: var(--color-muted); }
</style>
