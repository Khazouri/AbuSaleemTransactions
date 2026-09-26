<script setup>
import { onBeforeUnmount, onMounted, ref, useId } from 'vue'

// The one dialog shell for a form that used to sit inline above a table. The
// parent keeps its own open/close state and mounts this with v-if, so opening
// and resetting the form stay exactly where they were.
defineProps({
  title: { type: String, required: true },
  wide: { type: Boolean, default: false },
})
const emit = defineEmits(['close'])

const titleId = useId()
const dialog = ref(null)
let returnFocusTo = null

// Escape closes, a backdrop click deliberately does not: the larger forms here
// take minutes to fill in, and one stray click must not throw that away.
function onKeydown(event) {
  if (event.key === 'Escape') emit('close')
}

onMounted(() => {
  returnFocusTo = document.activeElement
  document.addEventListener('keydown', onKeydown)
  dialog.value?.querySelector('input, select, textarea, button')?.focus()
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
  returnFocusTo?.focus?.()
})
</script>

<template>
  <Teleport to="body">
    <div class="modal-backdrop">
      <section
        ref="dialog"
        class="modal"
        :class="{ 'modal-wide': wide }"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
      >
        <h3 :id="titleId">{{ title }}</h3>
        <div class="modal-body"><slot /></div>
      </section>
    </div>
  </Teleport>
</template>
