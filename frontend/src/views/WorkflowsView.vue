<script setup>
/**
 * Workflow designer — slice 2. Pick a workflow version and read it as a
 * diagram. The version shown defaults to the published one, and
 * `?version=<id>` keeps a picked one across reloads and shared links.
 *
 * Slice 3: a draft is edited here. Every edit is one modal whose submit sends
 * the whole graph (PUT), and the server's answer — with its fresh report —
 * replaces the version, so there is no unsaved state to lose.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api, { firstError } from '../lib/api'
import { useAuthStore } from '../stores/auth'
import AppModal from '../components/AppModal.vue'
import WorkflowCanvas from '../components/workflow/WorkflowCanvas.vue'
import WorkflowInspector from '../components/workflow/WorkflowInspector.vue'
import StageForm from '../components/workflow/StageForm.vue'
import OutcomeForm from '../components/workflow/OutcomeForm.vue'
import ValidationPanel from '../components/workflow/ValidationPanel.vue'
import { placeStage, removeRule, removeStage, toPayload, upsertRule } from '../lib/workflowDraft'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const workflows = ref([])
const options = ref({ roles: [], statuses: [], documents: [] })
const version = ref(null)
const selection = ref(null)
const loading = ref(true)
const error = ref('')
// One open dialog at a time: { kind: 'stage'|'rule'|'workflow'|'clone'|'publish'|'discard'|'deleteStage'|'deleteRule', ...its data }.
const dialog = ref(null)
const dialogError = ref('')
const saving = ref(false)
const newWorkflow = reactive({ code: '', name_ar: '', name_en: '' })

const name = (item) => (locale.value === 'ar' ? item?.name_ar || item?.name_en : item?.name_en || item?.name_ar)
const versions = computed(() => workflows.value.flatMap((workflow) => workflow.versions.map((item) => ({ ...item, workflow }))))
const versionId = computed(() => Number(route.query.version) || versions.value.find((item) => item.status === 'published')?.id || null)
const editable = computed(() => version.value?.status === 'draft' && auth.can('workflow_designer', 'edit'))
const draftOfThisWorkflow = computed(() => versions.value.find((item) => item.status === 'draft' && item.workflow.id === version.value?.workflow.id))
const orderedStages = computed(() => [...(version.value?.stages ?? [])].sort((a, b) => a.order_no - b.order_no))

async function loadWorkflows() {
  const { data } = await api.get('/workflows')
  workflows.value = data.data
  options.value = data.options
}

async function loadVersion(id) {
  error.value = ''
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
    await loadWorkflows()
  } catch {
    error.value = t('workflows.loadFailed')
  } finally {
    loading.value = false
  }
})

watch(versionId, loadVersion, { immediate: true })
const pick = (id) => router.replace({ query: { ...route.query, version: id } })
function open(kind, data = {}) {
  dialogError.value = ''
  dialog.value = { kind, ...data }
}
const close = () => { dialog.value = null }

async function save(payload) {
  saving.value = true
  dialogError.value = ''
  try {
    version.value = (await api.put(`/workflow-versions/${version.value.id}`, payload)).data.data
    close()
  } catch (e) {
    dialogError.value = firstError(e, t('workflows.edit.saveFailed'))
  } finally {
    saving.value = false
  }
}

async function post(request, then) {
  saving.value = true
  dialogError.value = ''
  try {
    const { data } = await request()
    await loadWorkflows()
    close()
    then(data?.data)
  } catch (e) {
    dialogError.value = firstError(e, t('workflows.edit.saveFailed'))
  } finally {
    saving.value = false
  }
}

const publishedOf = (workflowId) => versions.value.find((item) => item.status === 'published' && item.workflow.id === workflowId)?.id
const actions = {
  clone: () => post(() => api.post(`/workflow-versions/${version.value.id}/clone`), (draft) => pick(draft.id)),
  publish: () => post(() => api.post(`/workflow-versions/${version.value.id}/publish`), (published) => { version.value = published }),
  discard: () => {
    const workflowId = version.value.workflow.id
    return post(() => api.delete(`/workflow-versions/${version.value.id}`), () => pick(publishedOf(workflowId)))
  },
  // Workflow designer — slice 5. A deleted built-in can't be re-made in the
  // draft, so every delete asks first; a refusal shows in the same dialog.
  deleteStage: async () => {
    const { stage } = dialog.value
    await save(removeStage(toPayload(version.value), stage.code))
    if (!version.value.stages.some((item) => item.id === stage.id)) selection.value = null
  },
  deleteRule: () => save(removeRule(toPayload(version.value), dialog.value.rule.id)),
}
const confirmKinds = ['clone', 'publish', 'discard', 'deleteStage', 'deleteRule']
const confirmText = computed(() => (dialog.value?.kind === 'deleteRule' && !dialog.value.rule.custom
  ? 'workflows.edit.deleteBuiltinRuleConfirm'
  : `workflows.edit.${dialog.value?.kind}Confirm`))
const createWorkflow = () => post(
  () => api.post('/workflows', { ...newWorkflow, from_version_id: version.value.id }),
  (draft) => pick(draft.id),
)

function openNewWorkflow() {
  Object.assign(newWorkflow, { code: '', name_ar: '', name_en: '' })
  open('workflow')
}
function addStage() {
  const selected = selection.value?.kind === 'stage' ? version.value.stages.find((stage) => stage.id === selection.value.id)?.code : null
  open('stage', { stage: null, after: selected ?? orderedStages.value.at(-1)?.code ?? null })
}
function editStage(stage) {
  const index = orderedStages.value.findIndex((item) => item.id === stage.id)
  open('stage', { stage, after: orderedStages.value[index - 1]?.code ?? null })
}
// Workflow designer — slice 4. An attachment item names a document of the
// types this workflow files; with no type bound yet, any type's.
const documentOptions = computed(() => {
  const bound = new Set(workflows.value.find((item) => item.id === version.value?.workflow.id)?.request_types.map((type) => type.id) ?? [])
  return bound.size ? options.value.documents.filter((doc) => doc.type_ids.some((id) => bound.has(id))) : options.value.documents
})
function addRule(stage) {
  open('rule', {
    builtIn: false,
    rule: {
      id: null, from: stage.code, to: stage.code, label_ar: '', label_en: '',
      required_role_id: null, set_status_id: null, required_status_id: null,
      is_exception: false, requires_comment: false, requires_submitter_manager: false, requires_creator: false, requires_checks: false,
    },
  })
}
function editRule(rule) {
  // `action` rides along for the form's title only; the PUT's validated() drops it.
  const payloadRule = toPayload(version.value).transitions.find((item) => item.id === rule.id)
  open('rule', { rule: { ...payloadRule, action: rule.action }, builtIn: !rule.custom })
}

const submitStage = ({ fields, after }) => save(placeStage(toPayload(version.value), dialog.value.stage?.code ?? null, fields, after))
const submitRule = (rule) => save(upsertRule(toPayload(version.value), rule))
const selectCode = (code) => { selection.value = { kind: 'stage', id: version.value.stages.find((stage) => stage.code === code)?.id } }
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
    <template v-else-if="version">
      <div class="toolbar">
        <template v-if="version.status !== 'draft'">
          <button v-if="draftOfThisWorkflow" type="button" class="primary" @click="pick(draftOfThisWorkflow.id)">{{ t('workflows.edit.openDraft') }}</button>
          <button v-else v-can="'workflow_designer.add'" type="button" class="primary" @click="open('clone')">{{ t('workflows.edit.cloneDraft') }}</button>
          <button v-can="'workflow_designer.add'" type="button" class="ghost" @click="openNewWorkflow">{{ t('workflows.edit.newWorkflow') }}</button>
        </template>
        <template v-else>
          <button v-can="'workflow_designer.edit'" type="button" class="primary" @click="addStage">{{ t('workflows.edit.addStage') }}</button>
          <button v-can="'workflow_designer.approve'" type="button" class="ghost" :disabled="version.report?.errors.length > 0" @click="open('publish')">{{ t('workflows.edit.publish') }}</button>
          <button v-can="'workflow_designer.delete'" type="button" class="ghost danger" @click="open('discard')">{{ t('workflows.edit.deleteDraft') }}</button>
        </template>
      </div>

      <ValidationPanel v-if="version.report" :report="version.report" @select="selectCode" />

      <div class="designer">
        <WorkflowCanvas :key="version.id" :version="version" @select="selection = $event" />
        <WorkflowInspector
          :version="version"
          :selection="selection"
          :editable="editable"
          @select="selection = $event"
          @edit-stage="editStage"
          @delete-stage="open('deleteStage', { stage: $event })"
          @add-rule="addRule"
          @edit-rule="editRule"
          @delete-rule="open('deleteRule', { rule: $event })"
        />
      </div>
    </template>

    <AppModal v-if="dialog?.kind === 'stage'" :title="t(dialog.stage ? 'workflows.edit.editStage' : 'workflows.edit.addStage')" wide @close="close">
      <StageForm
        :stage="dialog.stage" :after="dialog.after" :stages="orderedStages" :roles="options.roles" :documents="documentOptions"
        :error="dialogError" :saving="saving" @submit="submitStage" @cancel="close"
      />
    </AppModal>
    <AppModal v-if="dialog?.kind === 'rule'" :title="t(dialog.rule.id ? 'workflows.edit.editOutcome' : 'workflows.edit.addOutcome')" @close="close">
      <OutcomeForm
        :rule="dialog.rule" :built-in="dialog.builtIn" :stages="orderedStages" :roles="options.roles" :statuses="options.statuses"
        :error="dialogError" :saving="saving" @submit="submitRule" @cancel="close"
      />
    </AppModal>
    <AppModal v-if="dialog?.kind === 'workflow'" :title="t('workflows.edit.newWorkflow')" @close="close">
      <form class="dialog-form" @submit.prevent="createWorkflow">
        <p v-if="dialogError" class="alert" role="alert">{{ dialogError }}</p>
        <div class="field-grid">
          <label>
            <span>{{ t('workflows.edit.code') }}<span class="required-mark" aria-hidden="true">*</span></span>
            <input v-model="newWorkflow.code" required maxlength="50" pattern="[a-z][a-z0-9_]*" dir="ltr" />
          </label>
          <label>
            <span>{{ t('workflows.edit.nameAr') }}<span class="required-mark" aria-hidden="true">*</span></span>
            <input v-model="newWorkflow.name_ar" required maxlength="255" dir="auto" />
          </label>
          <label>
            {{ t('workflows.edit.nameEn') }}
            <input v-model="newWorkflow.name_en" maxlength="255" dir="auto" />
          </label>
        </div>
        <div class="modal-actions">
          <button class="ghost" type="button" @click="close">{{ t('common.cancel') }}</button>
          <button class="primary" type="submit" :disabled="saving">{{ saving ? t('common.saving') : t('common.save') }}</button>
        </div>
      </form>
    </AppModal>
    <AppModal v-if="confirmKinds.includes(dialog?.kind)" :title="t(`workflows.edit.${dialog.kind}Title`)" @close="close">
      <div class="dialog-form">
        <p>{{ t(confirmText) }}</p>
        <p v-if="dialogError" class="alert" role="alert">{{ dialogError }}</p>
        <div class="modal-actions">
          <button class="ghost" type="button" @click="close">{{ t('common.cancel') }}</button>
          <button type="button" :class="['discard', 'deleteStage', 'deleteRule'].includes(dialog.kind) ? 'btn danger' : 'primary'" :disabled="saving" @click="actions[dialog.kind]()">
            {{ t(`workflows.edit.${dialog.kind}Title`) }}
          </button>
        </div>
      </div>
    </AppModal>
  </section>
</template>

<style scoped>
.picker { display: grid; gap: var(--space-1); }
.toolbar { display: flex; flex-wrap: wrap; gap: var(--space-2); margin-block-end: var(--space-3); }
.designer { display: grid; gap: var(--space-4); grid-template-columns: minmax(0, 1fr) 22rem; align-items: start; margin-block-start: var(--space-3); }
.dialog-form { display: grid; gap: var(--space-3); }
.dialog-form p { margin: 0; }
@media (max-width: 1023px) {
  .designer { grid-template-columns: minmax(0, 1fr); }
}
</style>
