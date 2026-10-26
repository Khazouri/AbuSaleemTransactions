<script setup>
/**
 * Draws lib/confirm.js's dialog. The safe button comes first, so AppModal's
 * first-button focus lands on it and a stray Enter never runs the act.
 */
import { onBeforeUnmount, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import AppModal from './AppModal.vue'
import { answerConfirm, pendingConfirm as pending } from '../lib/confirm'

const { t } = useI18n()

// Most asks open over a wizard, whose AppModal also closes on Escape (it
// listens on document). Taking the key on window, before it reaches document,
// closes this dialog alone and leaves the wizard where it was.
function onKeydown(event) {
  if (!pending.value || event.key !== 'Escape') return
  event.stopImmediatePropagation()
  answerConfirm(false)
}

onMounted(() => window.addEventListener('keydown', onKeydown, true))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown, true))
</script>

<template>
  <AppModal v-if="pending" :title="pending.title" @close="answerConfirm(false)">
    <div class="confirm" :class="{ danger: pending.danger }">
      <p class="consequence">{{ pending.message }}</p>
      <p v-if="pending.reference" class="reference">
        {{ pending.referenceLabel ?? t('confirmAction.reference') }} <bdi>{{ pending.reference }}</bdi>
      </p>
    </div>
    <div class="modal-actions">
      <button class="ghost" type="button" @click="answerConfirm(false)">{{ t('confirmAction.keep') }}</button>
      <button :class="pending.danger ? 'btn danger' : 'primary'" type="button" @click="answerConfirm(true)">
        {{ pending.confirmLabel }}
      </button>
    </div>
  </AppModal>
</template>

<style scoped>
/* The wizard's تأشيرة slip carries its act on a start-edge bar; the last
   question before it goes out wears the same bar, red when the act ends or
   refuses something. */
.confirm {
  display: grid;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
  border-inline-start: 4px solid var(--color-warning-border);
  border-radius: var(--radius-lg);
  background: var(--color-warning-bg);
}
.confirm.danger {
  border-inline-start-color: var(--color-danger-fg);
  background: var(--color-danger-bg);
}
.consequence {
  margin: 0;
  color: var(--color-foreground);
  font-size: var(--text-base);
  line-height: 1.7;
  max-inline-size: 55ch;
}
.reference {
  margin: 0;
  color: var(--color-muted);
  font-size: var(--text-sm);
}
.reference bdi { color: var(--color-foreground); font-weight: 600; }
</style>
