<script setup>
/**
 * Stage 100 — [D] Appendix 6 row 15: الأرشفة has two مسؤول. Read-only since
 * decision wizard sub-project 3: each file's owner records it in the wizard
 * (acts/ArchiveForm.vue).
 */
import { useI18n } from 'vue-i18n'

defineProps({
  archive: { type: Object, required: true },
})

const { t } = useI18n()
const FILES = ['committee_file', 'service_file']
</script>

<template>
  <div class="gate-panel">
    <p class="hint">{{ t('requestArchive.intro') }}</p>
    <dl>
      <div v-for="file in FILES" :key="file">
        <span>{{ t(`requestArchive.files.${file}`) }}</span>
        <strong v-if="archive[file]">
          {{ archive[file].location }}
          <small>— {{ archive[file].archived_by?.name ?? '—' }}</small>
        </strong>
        <strong v-else-if="file === 'service_file' && !archive.service_file_required">{{ t('requestArchive.notOwed') }}</strong>
        <strong v-else>{{ t('requestArchive.pending') }}</strong>
      </div>
    </dl>
  </div>
</template>
