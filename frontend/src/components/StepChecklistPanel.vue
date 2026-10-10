<script setup>
/**
 * Workflow designer — slice 4. A custom step's checklist, filled in the
 * decision wizard's Checks step by whoever the step offers a checked outcome.
 * One submit for the whole card. An attachment item lists the file(s) under
 * its document; each is judged only after its full preview was opened (the
 * server records the open), as on both document gates.
 */
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api, { firstError } from '../lib/api'
import AttachmentLink from './AttachmentLink.vue'

const props = defineProps({ request: { type: Object, required: true } })
const emit = defineEmits(['updated'])
const { t, locale } = useI18n()

const card = computed(() => props.request.control_gates?.step_checklist)
const label = (item) => (locale.value === 'ar' ? item.label_ar : item.label_en || item.label_ar)
const fileById = computed(() => new Map((props.request.attachments ?? []).map((file) => [file.id, file])))
const at = computed(() => (card.value?.reviewed_at
  ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(card.value.reviewed_at))
  : ''))

const answers = reactive({})
const verdicts = reactive({})
const previewed = reactive({})
const saving = ref(false)
const error = ref('')

// Re-seeded whenever the server answers, so a reopened wizard shows the saved card.
function seed() {
  for (const item of card.value?.items ?? []) {
    answers[item.key] = item.answer ?? (item.type === 'attestation' ? false : '')
    for (const file of item.files) {
      verdicts[file.id] = file.verdict ?? ''
      previewed[file.id] = file.previewed
    }
  }
}
watch(card, seed, { immediate: true })

function payload() {
  return Object.fromEntries((card.value?.items ?? []).flatMap((item) => {
    if (item.type === 'attachment') {
      const judged = item.files.filter((file) => verdicts[file.id])
      return judged.length ? [[item.key, Object.fromEntries(judged.map((file) => [file.id, verdicts[file.id]]))]] : []
    }
    const value = answers[item.key]
    return value === '' || value === null ? [] : [[item.key, value]]
  }))
}

async function submit() {
  saving.value = true
  error.value = ''
  try {
    const { data } = await api.patch(`/requests/${props.request.id}/step-review`, { answers: payload() })
    emit('updated', data.data)
  } catch (requestError) {
    error.value = firstError(requestError, t('stepChecklist.error'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <form v-if="card" class="step-checklist" @submit.prevent="submit">
    <p v-if="card.refusal" class="alert warning">{{ card.refusal }}</p>
    <p v-else class="alert success">{{ t('stepChecklist.passed') }}</p>
    <p v-if="card.reviewed_by" class="hint">{{ t('stepChecklist.recordedBy', { name: card.reviewed_by.name, at }) }}</p>

    <ol class="items">
      <li v-for="item in card.items" :key="item.key">
        <template v-if="item.type === 'yes_no'">
          <fieldset class="inline-set">
            <legend dir="auto">{{ label(item) }}</legend>
            <label v-for="value in ['yes', 'no']" :key="value" class="inline">
              <input v-model="answers[item.key]" type="radio" :name="`check-${item.key}`" :value="value" />
              <span>{{ t(`stepChecklist.${value}`) }}</span>
            </label>
          </fieldset>
          <small class="hint">{{ t('stepChecklist.blocks', { answer: t(`stepChecklist.${item.blocking_answer}`) }) }}</small>
        </template>

        <label v-else-if="item.type === 'note'" class="block">
          <span dir="auto">{{ label(item) }}</span>
          <textarea v-model="answers[item.key]" rows="3" maxlength="5000" />
        </label>

        <label v-else-if="item.type === 'attestation'" class="inline">
          <input v-model="answers[item.key]" type="checkbox" />
          <span dir="auto">{{ label(item) }}</span>
        </label>

        <template v-else>
          <p class="item-title" dir="auto">{{ label(item) }}</p>
          <p v-if="!item.files.length" class="hint">{{ t('stepChecklist.noFiles') }}</p>
          <ul v-else class="files">
            <li v-for="file in item.files" :key="file.id">
              <AttachmentLink v-if="fileById.get(file.id)" :attachment="fileById.get(file.id)" @previewed="previewed[file.id] = true" />
              <label class="inline">
                <input type="checkbox" :checked="previewed[file.id]" disabled />
                <span>{{ t('controlGates.review.previewed') }}</span>
              </label>
              <fieldset class="inline-set" :disabled="!previewed[file.id]">
                <legend class="sr-only">{{ fileById.get(file.id)?.original_name }}</legend>
                <label v-for="verdict in ['approved', 'not_approved']" :key="verdict" class="inline">
                  <input v-model="verdicts[file.id]" type="radio" :name="`step-verdict-${file.id}`" :value="verdict" />
                  <span>{{ t(`controlGates.review.${verdict}`) }}</span>
                </label>
              </fieldset>
            </li>
          </ul>
        </template>
      </li>
    </ol>

    <p v-if="error" class="alert warning" role="alert">{{ error }}</p>
    <div class="actions">
      <button class="primary" type="submit" :disabled="saving">{{ saving ? t('stepChecklist.saving') : t('stepChecklist.save') }}</button>
    </div>
  </form>
</template>

<style scoped>
.step-checklist { display: grid; gap: var(--space-3); }
.items, .files { display: grid; gap: var(--space-3); margin: 0; padding-inline-start: var(--space-4); }
.files { list-style: none; padding-inline-start: 0; gap: var(--space-2); }
.files li { display: grid; gap: var(--space-1); }
.inline-set { display: flex; flex-wrap: wrap; gap: var(--space-3); margin: 0; padding: 0; border: 0; }
.inline-set legend { padding: 0; margin-block-end: var(--space-1); font-weight: 600; }
.inline { display: inline-flex; align-items: center; gap: var(--space-2); }
.block { display: grid; gap: var(--space-1); }
.item-title { margin: 0; font-weight: 600; }
.hint { margin: 0; color: var(--color-muted); font-size: var(--text-sm); }
.sr-only { position: absolute; inline-size: 1px; block-size: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
.actions { display: flex; justify-content: flex-end; }
</style>
