<script setup>
/**
 * Workflow designer — slice 4. A custom step's checklist, edited in place in
 * the stage modal. New items carry `key: null`; the server mints keys and
 * keeps only the fields each type uses, so switching a type here needs no
 * clean-up.
 */
import { useI18n } from 'vue-i18n'

const items = defineModel({ type: Array, required: true })
defineProps({ documents: { type: Array, required: true } })
const { t, locale } = useI18n()
const TYPES = ['yes_no', 'attachment', 'note', 'attestation']
const docName = (doc) => (locale.value === 'ar' ? doc.ar || doc.en : doc.en || doc.ar)

function add() {
  items.value = [...items.value, { key: null, type: 'yes_no', label_ar: '', label_en: '', blocking_answer: 'no', document_key: null }]
}
function remove(index) {
  items.value = items.value.filter((_, at) => at !== index)
}
</script>

<template>
  <fieldset class="checklist">
    <legend>{{ t('workflows.edit.checklist.title') }}</legend>
    <p class="hint">{{ t('workflows.edit.checklist.hint') }}</p>
    <p v-if="!items.length" class="hint">{{ t('workflows.edit.checklist.empty') }}</p>
    <ol class="items">
      <li v-for="(item, index) in items" :key="item.key ?? `new-${index}`" class="item">
        <div class="field-grid">
          <label>
            {{ t('workflows.edit.checklist.type') }}
            <select v-model="item.type">
              <option v-for="type in TYPES" :key="type" :value="type">{{ t(`workflows.edit.checklist.types.${type}`) }}</option>
            </select>
          </label>
          <label>
            <span>{{ t('workflows.edit.checklist.labelAr') }}<span class="required-mark" aria-hidden="true">*</span></span>
            <input v-model="item.label_ar" required maxlength="255" dir="auto" />
          </label>
          <label>
            {{ t('workflows.edit.checklist.labelEn') }}
            <input v-model="item.label_en" maxlength="255" dir="auto" />
          </label>
          <label v-if="item.type === 'yes_no'">
            <span>{{ t('workflows.edit.checklist.blockingAnswer') }}<span class="required-mark" aria-hidden="true">*</span></span>
            <select v-model="item.blocking_answer" required>
              <option value="no">{{ t('stepChecklist.no') }}</option>
              <option value="yes">{{ t('stepChecklist.yes') }}</option>
            </select>
          </label>
          <label v-if="item.type === 'attachment'">
            <span>{{ t('workflows.edit.checklist.document') }}<span class="required-mark" aria-hidden="true">*</span></span>
            <select v-model="item.document_key" required>
              <option v-for="doc in documents" :key="doc.key" :value="doc.key">{{ docName(doc) }}</option>
            </select>
            <small v-if="!documents.length" class="hint">{{ t('workflows.edit.checklist.noDocuments') }}</small>
          </label>
        </div>
        <button type="button" class="ghost danger" @click="remove(index)">{{ t('workflows.edit.checklist.remove') }}</button>
      </li>
    </ol>
    <button type="button" class="ghost" @click="add">{{ t('workflows.edit.checklist.add') }}</button>
  </fieldset>
</template>

<style scoped>
.checklist { display: grid; gap: var(--space-2); margin: 0; padding: var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.checklist legend { padding-inline: var(--space-1); font-size: var(--text-sm); }
.hint { margin: 0; color: var(--color-muted); font-size: var(--text-sm); }
.items { display: grid; gap: var(--space-3); margin: 0; padding-inline-start: var(--space-4); }
.item { display: grid; gap: var(--space-2); justify-items: start; }
.item .field-grid { inline-size: 100%; }
</style>
