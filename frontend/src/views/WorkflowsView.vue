<script setup>
/**
 * Workflow designer — slice 2. Pick a workflow version and read it as a
 * diagram. The version shown defaults to the published one, and
 * `?version=<id>` keeps a picked one across reloads and shared links.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import WorkflowCanvas from '../components/workflow/WorkflowCanvas.vue'
import WorkflowInspector from '../components/workflow/WorkflowInspector.vue'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()

const workflows = ref([])
const version = ref(null)
const selection = ref(null)
const loading = ref(true)
const error = ref('')

const name = (item) => (locale.value === 'ar' ? item?.name_ar || item?.name_en : item?.name_en || item?.name_ar)
const versions = computed(() => workflows.value.flatMap((workflow) => workflow.versions.map((item) => ({ ...item, workflow }))))
const versionId = computed(() => Number(route.query.version) || versions.value.find((item) => item.status === 'published')?.id || null)

async function loadVersion(id) {
  selection.value = null
  version.value = null
  if (!id) return
  try {
    version.value = (await api.get(`/workflow-versions/${id}`)).data.data
  } catch {
    error.value = t('workflows.loadFailed')
  }
}

onMounted(async () => {
  try {
    workflows.value = (await api.get('/workflows')).data.data
  } catch {
    error.value = t('workflows.loadFailed')
  } finally {
    loading.value = false
  }
})

watch(versionId, loadVersion, { immediate: true })
const pick = (id) => router.replace({ query: { ...route.query, version: id } })
</script>

<template>
  <section class="page">
    <div class="heading">
      <h2>{{ t('workflows.title') }}</h2>
      <label v-if="versions.length" class="picker">
        <span>{{ t('workflows.pickVersion') }}</span>
        <select :value="versionId" @change="pick(Number($event.target.value))">
          <option v-for="item in versions" :key="item.id" :value="item.id">
            {{ name(item.workflow) }} — {{ t('workflows.version', { number: item.number }) }} ({{ t(`workflows.status.${item.status}`) }})
          </option>
        </select>
      </label>
    </div>

    <p v-if="loading" class="state">{{ t('common.loading') }}</p>
    <p v-else-if="error" class="alert" role="alert">{{ error }}</p>
    <div v-else-if="version" class="designer">
      <WorkflowCanvas :version="version" @select="selection = $event" />
      <WorkflowInspector :version="version" :selection="selection" @select="selection = $event" />
    </div>
  </section>
</template>

<style scoped>
.picker { display: grid; gap: var(--space-1); }
.designer { display: grid; gap: var(--space-4); grid-template-columns: minmax(0, 1fr) 22rem; align-items: start; }
@media (max-width: 1023px) {
  .designer { grid-template-columns: minmax(0, 1fr); }
}
</style>
