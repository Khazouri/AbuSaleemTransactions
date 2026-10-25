<script setup>
/**
 * 2026-10-25 — scanned pages, images only, in the order the user arranges
 * them. The order is what the server numbers the pages by, so moving one is
 * done with ↑/↓ buttons (keyboard-reachable, no drag-and-drop library), and
 * each row shows a thumbnail to tell the scans apart.
 *
 * It only collects the files: the caller posts them as `<field>[i]`, in this
 * array's order.
 */
import { onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import FileDropZone from './FileDropZone.vue'

defineProps({
  disabled: { type: Boolean, default: false },
})
const pages = defineModel({ type: Array, default: () => [] })
const { t } = useI18n()

const IMAGE_TYPES = ['image/jpeg', 'image/png']
const rejected = ref([])

// One object URL per File, revoked when the page is removed or the form goes.
const thumbnails = new Map()
function thumbnail(file) {
  if (!thumbnails.has(file)) thumbnails.set(file, URL.createObjectURL(file))
  return thumbnails.get(file)
}
function forget(file) {
  URL.revokeObjectURL(thumbnails.get(file))
  thumbnails.delete(file)
}
onBeforeUnmount(() => thumbnails.forEach((url) => URL.revokeObjectURL(url)))

// `accept` is only a hint to the picker; a dropped file ignores it.
function add(files) {
  rejected.value = files.filter((file) => !IMAGE_TYPES.includes(file.type)).map((file) => file.name)
  pages.value = [...pages.value, ...files.filter((file) => IMAGE_TYPES.includes(file.type))]
}

function move(index, offset) {
  const next = [...pages.value]
  ;[next[index], next[index + offset]] = [next[index + offset], next[index]]
  pages.value = next
}

function remove(index) {
  forget(pages.value[index])
  pages.value = pages.value.filter((_, at) => at !== index)
}
</script>

<template>
  <div class="scanned-pages">
    <FileDropZone accept=".jpg,.jpeg,.png" :disabled="disabled" @files="add" />
    <p class="hint">{{ t('paperMeeting.pages.hint') }}</p>
    <p v-if="rejected.length" class="alert" role="alert">
      {{ t('paperMeeting.pages.rejected', { names: rejected.join('، ') }) }}
    </p>

    <ol v-if="pages.length" class="pages">
      <li v-for="(file, index) in pages" :key="thumbnail(file)" class="page">
        <span class="number">{{ index + 1 }}</span>
        <img :src="thumbnail(file)" alt="" class="thumb" />
        <span class="name" dir="auto">{{ file.name }}</span>
        <span class="actions">
          <button
            class="ghost"
            type="button"
            :disabled="disabled || index === 0"
            :aria-label="t('paperMeeting.pages.moveUp', { n: index + 1 })"
            @click="move(index, -1)"
          >↑</button>
          <button
            class="ghost"
            type="button"
            :disabled="disabled || index === pages.length - 1"
            :aria-label="t('paperMeeting.pages.moveDown', { n: index + 1 })"
            @click="move(index, 1)"
          >↓</button>
          <button
            class="link"
            type="button"
            :disabled="disabled"
            :aria-label="t('paperMeeting.pages.remove', { n: index + 1 })"
            @click="remove(index)"
          >{{ t('paperMeeting.removeFile') }}</button>
        </span>
      </li>
    </ol>
  </div>
</template>

<style scoped>
.scanned-pages { display: grid; gap: var(--space-2); min-inline-size: 0; }
.hint { margin: 0; color: var(--color-muted); font-size: var(--text-sm); }
.pages { display: grid; gap: var(--space-2); margin: 0; padding: 0; list-style: none; }
.page {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
  min-inline-size: 0;
}
.number { display: inline-grid; place-items: center; min-inline-size: 1.75rem; block-size: 1.75rem; border-radius: var(--radius-full); background: var(--color-brand); color: var(--color-on-brand); font-size: var(--text-sm); }
.thumb { inline-size: 3rem; block-size: 3rem; object-fit: cover; border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.name { flex: 1 1 8rem; min-inline-size: 0; overflow-wrap: anywhere; }
.actions { display: inline-flex; align-items: center; gap: var(--space-1); }
</style>
