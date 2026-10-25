<script setup>
/**
 * Stage 100 — [D] Appendix 6 row 15's archive. Read-only since decision
 * wizard sub-project 3: HR records it in the wizard (acts/ArchiveForm.vue).
 * Since 2026-10-25 HR alone archives; a committee-file record shows only when
 * one was made before then.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  archive: { type: Object, required: true },
})

const { t } = useI18n()
const files = computed(() => (props.archive.committee_file ? ['committee_file', 'service_file'] : ['service_file']))
</script>

<template>
  <div class="gate-panel">
    <p class="hint">{{ t('requestArchive.intro') }}</p>
    <dl>
      <div v-for="file in files" :key="file">
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
