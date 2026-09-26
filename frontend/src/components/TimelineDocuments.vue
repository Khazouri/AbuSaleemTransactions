<script setup>
/**
 * The documents that entered the file during one «سجل سير العمل» entry.
 *
 * Shared by the request workspace and «متابعة طلباتي» so the two renderings of
 * [D] Art. 100's السجل الزمني cannot drift apart again (the tracking copy had
 * already lost the kind and the reference). The kind is an icon, named for
 * screen readers; a committee decision is the one row drawn in the brand
 * green, because it is the only document here that changes the outcome.
 */
import { useI18n } from 'vue-i18n'
import AppIcon from './AppIcon.vue'
import { fileSectionLabel } from '../lib/fileSections'

defineProps({
  documents: { type: Array, required: true },
})

const { t } = useI18n()

const ICONS = { attachment: 'file-text', decision: 'scale' }
</script>

<template>
  <ul class="timeline-documents">
    <li v-for="(doc, index) in documents" :key="index" :class="`kind-${doc.kind}`">
      <span class="glyph" role="img" :aria-label="t(`requestDetail.linkedDocuments.kinds.${doc.kind}`)">
        <AppIcon :name="ICONS[doc.kind] || 'file-text'" :size="16" />
      </span>
      <span class="name">
        <span class="label">{{ doc.label }}</span>
        <span v-if="doc.reference && doc.reference !== doc.label" class="reference">{{ doc.reference }}</span>
      </span>
      <span v-if="doc.section" class="section">{{ fileSectionLabel(t, doc.section) }}</span>
    </li>
  </ul>
</template>

<style scoped>
.timeline-documents {
  display: grid;
  padding: 0 var(--space-3);
  margin: var(--space-2) 0 0;
  list-style: none;
  border: 1px solid var(--color-border-hover);
  border-radius: var(--radius-md);
  background: var(--color-black-50);
}
.timeline-documents li {
  display: grid;
  grid-template-columns: 1rem minmax(0, 1fr) auto;
  align-items: start;
  gap: 0.15rem var(--space-3);
  padding-block: var(--space-2);
}
.timeline-documents li + li { border-top: 1px solid var(--color-border); }
.glyph { display: flex; padding-top: 0.1rem; color: var(--color-muted); }
.name { display: grid; gap: 0.1rem; min-width: 0; }
.label { color: var(--color-black-700); font-size: var(--text-sm); font-weight: 500; overflow-wrap: anywhere; }
.reference {
  color: var(--color-muted);
  font-size: var(--text-xs);
  font-variant-numeric: tabular-nums;
  overflow-wrap: anywhere;
  /* Filenames and decision numbers read LTR, but the box stays on the grid's
     start edge so in Arabic it sits under its label rather than across the row. */
  direction: ltr;
  justify-self: start;
  max-width: 100%;
}
.section { color: var(--color-muted); font-size: var(--text-xs); white-space: nowrap; padding-top: 0.15rem; }
.kind-decision .glyph, .kind-decision .label { color: var(--color-brand-text); }

/* Narrow cards: the folder name drops under the document rather than squeezing it. */
@media (max-width: 640px) {
  .timeline-documents li { grid-template-columns: 1rem minmax(0, 1fr); }
  .section { grid-column: 2; padding-top: 0; white-space: normal; }
}
</style>
