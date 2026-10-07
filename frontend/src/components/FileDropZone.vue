<script setup>
/**
 * A file picker that also takes files dropped on it. It only collects them:
 * each caller judges and uploads what it gets, because intake and the request
 * page accept different types and post to different endpoints.
 *
 * The input stays inside the <label>, so a click or Enter on the zone still
 * opens the native picker. It is not display:none, which would take it out of
 * the keyboard order.
 */
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  accept: { type: String, required: true },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['files'])
const { t } = useI18n()
const dragging = ref(false)

function pick(event) {
  const files = Array.from(event.target.files ?? [])
  // Cleared so picking the same file again after removing it still fires.
  event.target.value = ''
  if (files.length) emit('files', files)
}

function drop(event) {
  dragging.value = false
  if (props.disabled) return
  const files = Array.from(event.dataTransfer?.files ?? [])
  if (files.length) emit('files', files)
}
</script>

<template>
  <label
    class="drop-zone"
    :class="{ dragging, disabled }"
    @dragover.prevent="dragging = !disabled"
    @dragleave="dragging = false"
    @drop.prevent="drop"
  >
    <input type="file" multiple :accept="accept" :disabled="disabled" @change="pick" />
    <strong>{{ t('attachments.dropZone') }}</strong>
    <span>{{ t('attachments.dropZoneBrowse') }}</span>
  </label>
</template>

<style scoped>
.drop-zone {
  position: relative;
  display: grid;
  gap: .25rem;
  justify-items: center;
  padding: var(--space-5) var(--space-3);
  border: 2px dashed var(--color-border-hover);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
  color: var(--color-black-700);
  text-align: center;
  cursor: pointer;
}
.drop-zone span { color: var(--color-muted); font-size: var(--text-sm); }
.drop-zone:hover, .drop-zone.dragging, .drop-zone:focus-within {
  border-color: var(--color-brand-text);
  background: var(--color-surface-hover);
}
.drop-zone.disabled { cursor: not-allowed; opacity: .6; }
.drop-zone input {
  position: absolute;
  inline-size: 1px;
  block-size: 1px;
  opacity: 0;
}
</style>
