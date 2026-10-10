<script setup>
/**
 * Workflow designer — slice 3. The draft's report: errors block publishing,
 * warnings do not. An entry that names a step selects it on the canvas.
 * Messages are the server's own, Arabic in both locales like every refusal.
 */
import { useI18n } from 'vue-i18n'

defineProps({ report: { type: Object, required: true } })
const emit = defineEmits(['select'])
const { t } = useI18n()
</script>

<template>
  <section class="report card card-flat card-pad" aria-live="polite">
    <h3>{{ t('workflows.edit.reportTitle') }}</h3>
    <p v-if="!report.errors.length && !report.warnings.length" class="hint">{{ t('workflows.edit.reportClean') }}</p>
    <ul v-for="kind in ['errors', 'warnings']" :key="kind" class="entries">
      <li v-for="(entry, index) in report[kind]" :key="kind + index">
        <span class="pill" :class="kind === 'errors' ? 'danger' : 'warning'">{{ t(`workflows.edit.${kind}`) }}</span>
        <button v-if="entry.stage_code" type="button" class="ghost" dir="auto" @click="emit('select', entry.stage_code)">{{ entry.message_ar }}</button>
        <span v-else dir="auto">{{ entry.message_ar }}</span>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.report { display: grid; gap: var(--space-2); }
.report h3 { margin: 0; }
.hint { margin: 0; color: var(--color-muted); }
.entries { list-style: none; margin: 0; padding: 0; display: grid; gap: var(--space-2); }
.entries:empty { display: none; }
.entries li { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
.entries button { text-align: start; white-space: normal; }
</style>
