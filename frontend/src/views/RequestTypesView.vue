<script setup>
/**
 * Request-type catalogue (أنواع الطلبات) — CRUD over `request_types`.
 *
 * The rows here are not just labels. A type carries the SLA that becomes a
 * request's due_date, the decision grade at/above which the case must reach
 * the ministry, and [D] Appendix 57's required-document matrix — so this
 * screen is the one place those rules can be changed without a reseed.
 *
 * Retiring a type is `toggle-active`, not delete: an inactive type disappears
 * from the intake picker while every request already filed under it keeps
 * resolving. The delete button is therefore offered only for a type nothing
 * references, and the server refuses the rest — see the controller's docblock
 * for why each of the two foreign keys fails quietly if left to itself.
 *
 * Laid out as master–detail: the list names the types, and the selected one
 * (`?type=<id>`, so a link lands on it) shows its rules and its whole
 * Appendix 57 matrix at once. A type's documents are what an admin comes here
 * to check, and a table row had no room to show them.
 *
 * Every row is picked from the managed documents list (قائمة المستندات,
 * 2026-10-23), kept on this screen's second tab (`?tab=documents`) and
 * numbered by its id. A picked row copies the entry's wording; the names are
 * not editable here, because a row's Arabic name is part of the key its files
 * are stored under.
 */
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import AppModal from '../components/AppModal.vue'
import DocumentCatalog from '../components/DocumentCatalog.vue'
import {
  DOCUMENT_GROUPS,
  documentCondition,
  documentLabel,
  documentNumber,
  groupDocuments,
} from '../lib/requiredDocuments'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()

const types = ref([])
const documents = ref([])
const catalog = ref(null)
// Workflow designer — slice 3. The workflows a type may be bound to (each has a published version).
const workflows = ref([])
const standardWorkflowId = () => workflows.value.find((workflow) => workflow.code === 'standard')?.id
const loading = ref(false)
const saving = ref(false)
const loadError = ref(null)
const formError = ref(null)
const errors = ref({})
const editingId = ref(null)
const showForm = ref(false)
const listEl = ref(null)
const detailHeading = ref(null)

// Mirrors RequestType::ADMINISTRATIVE_ROUTES. Advisory only — the server never
// enforces it and all three routes stay freely selectable at runtime, so "not
// set" is a valid answer here rather than a gap.
const ROUTES = ['hr', 'diwan', 'committee_secretary']

const blankForm = () => ({
  code: '',
  name_ar: '',
  name_en: '',
  default_sla_days: '',
  decision_grade_threshold: '',
  is_active: true,
  default_administrative_route: '',
  legal_basis_ar: '',
  legal_basis_note_ar: '',
  required_documents: [],
  workflow_id: null,
})
const form = ref(blankForm())

const activeName = computed(() => (locale.value === 'ar' ? 'name_ar' : 'name_en'))

function label(type) {
  return type[activeName.value] || type.name_ar || type.name_en
}

/** A type is deletable only when nothing points at it; the server re-checks. */
function isDeletable(type) {
  return (type.requests_count ?? 0) === 0 && (type.workflow_transitions_count ?? 0) === 0
}

const tab = computed(() => (route.query.tab === 'documents' ? 'documents' : 'types'))

function switchTab(next) {
  router.replace({ query: next === 'documents' ? { tab: 'documents' } : {} })
}

function openType(id) {
  router.replace({ query: { type: id } })
}

// With no `?type=` the first type is shown, so a wide screen never opens on an
// empty pane. A phone shows one pane at a time and keys it off the query
// alone, so it opens on the list.
const selected = computed(
  () => types.value.find((type) => String(type.id) === String(route.query.type)) ?? types.value[0] ?? null,
)
const showingDetail = computed(() => route.query.type != null && String(selected.value?.id) === String(route.query.type))

const selectedDocuments = computed(() => selected.value?.required_documents ?? [])
const requiredCount = computed(() => selectedDocuments.value.filter((doc) => doc.required).length)

function workflowLabel(type) {
  const workflow = workflows.value.find((w) => w.id === type.workflow_id)
  return workflow ? label(workflow) : '—'
}

/** True when the list pane is hidden, i.e. a phone showing one pane at a time. */
const listHidden = () => listEl.value?.offsetParent === null

async function select(type) {
  await router.replace({ query: { ...route.query, type: type.id } })
  // On a phone the list the reader was in has just gone; move them to what replaced it.
  if (listHidden()) detailHeading.value?.focus()
}

async function backToList() {
  const id = selected.value?.id
  const { type: _type, ...query } = route.query
  await router.replace({ query })
  await nextTick()
  listEl.value?.querySelector('[data-type-id="' + id + '"]')?.focus()
}

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const { data } = await api.get('/request-types')
    types.value = data.data ?? data
    documents.value = data.documents ?? []
    workflows.value = data.workflows ?? []
  } catch (e) {
    loadError.value = e
  } finally {
    loading.value = false
  }
}

/** A type row as the editor holds it, copied from a list entry's defaults. */
function rowFromDocument(doc) {
  return {
    document_id: doc.id,
    ar: doc.name_ar,
    en: doc.name_en ?? '',
    group: doc.group,
    condition_ar: doc.condition_ar ?? '',
    condition_en: doc.condition_en ?? '',
    required: doc.required,
    section: doc.section ?? null,
  }
}

/** Active entries this type does not list yet; the server refuses a repeat too. */
const pickable = computed(() => {
  const listed = new Set(form.value.required_documents.map((doc) => doc.document_id))
  return documents.value.filter((doc) => doc.is_active && !listed.has(doc.id))
})

function startCreate() {
  editingId.value = null
  // A new type opens with the active shared basics, which Appendix 57 merges
  // into every type; anything else is picked.
  form.value = {
    ...blankForm(),
    workflow_id: standardWorkflowId() ?? null,
    required_documents: documents.value
      .filter((doc) => doc.is_active && doc.group === 'basic')
      .map(rowFromDocument),
  }
  errors.value = {}
  formError.value = null
  showForm.value = true
}

function startEdit(type) {
  editingId.value = type.id
  form.value = {
    ...blankForm(),
    ...type,
    // Nullable columns arrive as null; the inputs want strings, and save()
    // turns a blank back into null so clearing a field clears the column.
    code: type.code ?? '',
    name_en: type.name_en ?? '',
    default_sla_days: type.default_sla_days ?? '',
    decision_grade_threshold: type.decision_grade_threshold ?? '',
    default_administrative_route: type.default_administrative_route ?? '',
    legal_basis_ar: type.legal_basis_ar ?? '',
    legal_basis_note_ar: type.legal_basis_note_ar ?? '',
    // Copied entry by entry, never by reference: editing a row in the form
    // must not mutate the list behind it, or cancelling would leave the table
    // showing edits that were never saved. The condition's two halves are
    // flattened because a nested object is awkward to v-model.
    required_documents: (type.required_documents ?? []).map((doc) => ({
      document_id: doc.document_id ?? null,
      ar: doc.ar ?? '',
      en: doc.en ?? '',
      group: doc.group || 'basic',
      condition_ar: doc.condition?.ar ?? '',
      condition_en: doc.condition?.en ?? '',
      required: doc.required ?? true,
      section: doc.section ?? null,
    })),
  }
  errors.value = {}
  formError.value = null
  showForm.value = true
}

function cancelForm() {
  editingId.value = null
  showForm.value = false
  errors.value = {}
  formError.value = null
}

function documentName(doc) {
  return locale.value === 'ar' ? doc.name_ar : doc.name_en || doc.name_ar
}

/** The picker is a one-shot action, so it resets to its prompt after each pick. */
function addDocument(event) {
  const doc = documents.value.find((entry) => entry.id === Number(event.target.value))
  if (doc) form.value.required_documents.push(rowFromDocument(doc))
  event.target.value = ''
}

function removeDocument(index) {
  form.value.required_documents.splice(index, 1)
}

/** First message for a nested required_documents.N.field validation key. */
function documentError(index, field) {
  return errors.value['required_documents.' + index + '.' + field]?.[0] ?? null
}

async function save() {
  saving.value = true
  errors.value = {}
  formError.value = null

  try {
    const payload = {
      ...form.value,
      // Blank text becomes null, so clearing a field clears the column rather
      // than storing an empty string a reader would render as a value.
      code: form.value.code || null,
      name_en: form.value.name_en || null,
      default_sla_days:
        form.value.default_sla_days === '' ? null : Number(form.value.default_sla_days),
      decision_grade_threshold:
        form.value.decision_grade_threshold === ''
          ? null
          : Number(form.value.decision_grade_threshold),
      default_administrative_route: form.value.default_administrative_route || null,
      // A new type runs on Standard unless another workflow is picked.
      workflow_id: form.value.workflow_id ?? standardWorkflowId(),
      legal_basis_ar: form.value.legal_basis_ar || null,
      legal_basis_note_ar: form.value.legal_basis_note_ar || null,
      // Back into Appendix 57's stored shape. An entirely blank condition goes
      // back as null, never as a pair of empty strings — documentCondition()
      // tests whether the key is set, so an empty pair would render a blank
      // qualifier chip on the intake checklist. The server normalises this
      // too; doing it here as well keeps the form honest about what it sent.
      required_documents: form.value.required_documents.map((doc) => ({
        document_id: doc.document_id,
        ar: doc.ar,
        en: doc.en || null,
        group: doc.group,
        condition:
          (doc.condition_ar || '').trim() === '' && (doc.condition_en || '').trim() === ''
            ? null
            : { ar: doc.condition_ar || null, en: doc.condition_en || null },
        required: doc.required,
        section: doc.section,
      })),
    }

    if (editingId.value === null) await api.post('/request-types', payload)
    else await api.put('/request-types/' + editingId.value, payload)

    cancelForm()
    await load()
  } catch (e) {
    if (e?.response?.status === 422) {
      errors.value = e.response.data.errors ?? {}
      formError.value = e.response.data.message ?? null
    } else {
      formError.value = t('requestTypes.saveFailed')
    }
  } finally {
    saving.value = false
  }
}

async function toggleActive(type) {
  formError.value = null
  try {
    await api.patch('/request-types/' + type.id + '/toggle-active')
    await load()
  } catch (e) {
    formError.value = e?.response?.data?.message ?? t('requestTypes.saveFailed')
  }
}

async function remove(type) {
  if (!window.confirm(t('common.confirmDelete'))) return
  formError.value = null
  try {
    await api.delete('/request-types/' + type.id)
    if (String(route.query.type) === String(type.id)) {
      const { type: _type, ...query } = route.query
      await router.replace({ query })
    }
    await load()
  } catch (e) {
    // The server's own Arabic refusal is more useful than a generic one: it
    // names which reference blocked the delete, and the two remedies differ.
    formError.value = e?.response?.data?.message ?? t('requestTypes.deleteFailed')
  }
}

onMounted(load)
</script>

<template>
  <section class="page">
    <div class="heading">
      <h2>{{ t('requestTypes.title') }}</h2>
      <button v-if="tab === 'types'" v-can="'request_types.add'" class="primary" type="button" @click="startCreate">
        {{ t('requestTypes.add') }}
      </button>
      <button v-else v-can="'request_types.add'" class="primary" type="button" @click="catalog?.startCreate()">
        {{ t('requestTypes.documents.add') }}
      </button>
    </div>

    <div class="view-tabs" role="group" :aria-label="t('requestTypes.title')">
      <button type="button" class="chip" :class="{ active: tab === 'types' }" :aria-pressed="tab === 'types'" @click="switchTab('types')">
        {{ t('requestTypes.tabs.types') }} <span class="count">{{ types.length }}</span>
      </button>
      <button type="button" class="chip" :class="{ active: tab === 'documents' }" :aria-pressed="tab === 'documents'" @click="switchTab('documents')">
        {{ t('requestTypes.tabs.documents') }} <span class="count">{{ documents.length }}</span>
      </button>
    </div>

    <p v-if="formError && !showForm" class="alert">{{ formError }}</p>

    <AppModal v-if="showForm" :title="editingId === null ? t('requestTypes.add') : t('requestTypes.edit')" wide @close="cancelForm">
      <form class="form" @submit.prevent="save">
        <p v-if="formError" class="alert">{{ formError }}</p>

        <fieldset class="form-section">
          <legend>{{ t('requestTypes.sections.identity') }}</legend>
          <div class="grid">
            <label>
              <span>{{ t('requestTypes.nameAr') }}<span class="required-mark" aria-hidden="true">*</span></span>
              <input v-model="form.name_ar" required />
              <small v-if="errors.name_ar" class="field-error">{{ errors.name_ar[0] }}</small>
            </label>
            <label>
              {{ t('requestTypes.nameEn') }}
              <input v-model="form.name_en" class="ltr" />
              <small v-if="errors.name_en" class="field-error">{{ errors.name_en[0] }}</small>
            </label>
            <label>
              {{ t('requestTypes.code') }}
              <input v-model="form.code" class="ltr" />
              <small class="hint">{{ t('requestTypes.codeHint') }}</small>
              <small v-if="errors.code" class="field-error">{{ errors.code[0] }}</small>
            </label>
          </div>
          <label class="checkbox">
            <input v-model="form.is_active" type="checkbox" />{{ t('common.active') }}
          </label>
        </fieldset>

        <fieldset class="form-section">
          <legend>{{ t('requestTypes.sections.rules') }}</legend>
          <div class="grid">
            <label>
              {{ t('requestTypes.slaDays') }}
              <input v-model="form.default_sla_days" type="number" min="1" max="365" class="ltr" />
              <small class="hint">{{ t('requestTypes.slaHint') }}</small>
              <small v-if="errors.default_sla_days" class="field-error">
                {{ errors.default_sla_days[0] }}
              </small>
            </label>
            <label>
              {{ t('requestTypes.gradeThreshold') }}
              <input v-model="form.decision_grade_threshold" type="number" min="1" max="100" class="ltr" />
              <small class="hint">{{ t('requestTypes.gradeHint') }}</small>
              <small v-if="errors.decision_grade_threshold" class="field-error">
                {{ errors.decision_grade_threshold[0] }}
              </small>
            </label>
            <label>
              {{ t('requestTypes.route') }}
              <select v-model="form.default_administrative_route">
                <option value="">{{ t('requestTypes.noRoute') }}</option>
                <option v-for="routeCode in ROUTES" :key="routeCode" :value="routeCode">
                  {{ t('requestTypes.routes.' + routeCode) }}
                </option>
              </select>
              <small class="hint">{{ t('requestTypes.routeHint') }}</small>
            </label>
            <label v-if="workflows.length">
              {{ t('requestTypes.workflow') }}
              <select v-model="form.workflow_id">
                <option v-for="workflow in workflows" :key="workflow.id" :value="workflow.id">
                  {{ label(workflow) }}
                </option>
              </select>
              <small class="hint">{{ t('requestTypes.workflowHint') }}</small>
              <small v-if="errors.workflow_id" class="field-error">{{ errors.workflow_id[0] }}</small>
            </label>
          </div>
          <div class="grid">
            <label>
              {{ t('requestTypes.legalBasis') }}
              <input v-model="form.legal_basis_ar" />
              <small class="hint">{{ t('requestTypes.legalBasisHint') }}</small>
            </label>
            <label>
              {{ t('requestTypes.legalBasisNote') }}
              <input v-model="form.legal_basis_note_ar" />
            </label>
          </div>
        </fieldset>

        <fieldset class="form-section">
          <legend>{{ t('requestTypes.documents.title') }}</legend>
          <p class="hint">{{ t('requestTypes.documents.sourceNote') }} {{ t('requestTypes.documents.conditionHint') }}</p>

          <p v-if="form.required_documents.length === 0" class="hint">
            {{ t('requestTypes.documents.empty') }}
          </p>

          <div v-for="(doc, index) in form.required_documents" :key="index" class="document-row">
            <!-- Read-only: the wording comes from the documents list, and the
                 Arabic name is part of the key this row's files are stored under. -->
            <div class="document-name">
              <code class="ltr" :title="t('requestTypes.catalog.number')">{{ documentNumber(doc.document_id) }}</code>
              <span>
                {{ doc.ar }}
                <small v-if="doc.en">{{ doc.en }}</small>
              </span>
              <small v-if="documentError(index, 'document_id')" class="field-error">
                {{ documentError(index, 'document_id') }}
              </small>
            </div>
            <label>
              {{ t('requestTypes.documents.group') }}
              <select v-model="doc.group">
                <option v-for="group in DOCUMENT_GROUPS" :key="group" :value="group">
                  {{ t('requestTypes.documents.groups.' + group) }}
                </option>
              </select>
            </label>
            <div class="document-controls">
              <label class="checkbox">
                <input v-model="doc.required" type="checkbox" />{{ t('requestTypes.documents.required') }}
              </label>
              <button
                class="ghost danger"
                type="button"
                :aria-label="t('common.delete') + ' ' + (doc.ar || '')"
                @click="removeDocument(index)"
              >
                {{ t('common.delete') }}
              </button>
            </div>
            <label>
              {{ t('requestTypes.documents.conditionAr') }}
              <input v-model="doc.condition_ar" />
            </label>
            <label>
              {{ t('requestTypes.documents.conditionEn') }}
              <input v-model="doc.condition_en" class="ltr" />
            </label>
          </div>

          <label v-if="pickable.length" class="document-pick">
            {{ t('requestTypes.documents.pick') }}
            <select @change="addDocument">
              <option value="" selected disabled>{{ t('requestTypes.documents.pickPlaceholder') }}</option>
              <option v-for="doc in pickable" :key="doc.id" :value="doc.id">
                {{ documentNumber(doc.id) }}  {{ documentName(doc) }}
              </option>
            </select>
          </label>
        </fieldset>

        <div class="modal-actions">
          <button class="ghost" type="button" @click="cancelForm">{{ t('common.cancel') }}</button>
          <button class="primary" type="submit" :disabled="saving">
            {{ saving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </form>
    </AppModal>

    <div v-if="loading || loadError || (tab === 'types' && types.length === 0)" class="card card-flat card-pad">
      <p v-if="loading" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="loadError" class="alert">
        {{ t('nav.error') }}
        <button class="ghost" @click="load">{{ t('common.retry') }}</button>
      </p>
      <p v-else class="state">{{ t('requestTypes.empty') }}</p>
    </div>

    <DocumentCatalog
      v-else-if="tab === 'documents'"
      ref="catalog"
      :documents="documents"
      :types="types"
      @changed="load"
      @open-type="openType"
    />

    <div v-else class="split-view" :class="{ 'showing-detail': showingDetail }">
      <nav ref="listEl" class="split-list card card-flat" :aria-label="t('requestTypes.title')">
        <ul>
          <li v-for="type in types" :key="type.id">
            <button
              type="button"
              class="split-item"
              :class="{ inactive: !type.is_active }"
              :data-type-id="type.id"
              :aria-current="selected?.id === type.id ? 'true' : undefined"
              @click="select(type)"
            >
              <span class="split-item-name">{{ label(type) }}</span>
              <span class="split-item-meta">
                <code class="ltr">{{ type.code || '—' }}</code>
                <span v-if="!type.is_active" class="pill">{{ t('common.inactive') }}</span>
              </span>
            </button>
          </li>
        </ul>
      </nav>

      <article v-if="selected" class="split-detail card card-flat card-pad" aria-labelledby="request-type-title">
        <button class="ghost split-back" type="button" @click="backToList">{{ t('requestTypes.backToList') }}</button>

        <header class="split-head">
          <div>
            <h3 id="request-type-title" ref="detailHeading" tabindex="-1">{{ label(selected) }}</h3>
            <p class="split-sub">
              <code class="ltr">{{ selected.code || '—' }}</code>
              <span class="pill" :class="{ success: selected.is_active }">
                {{ selected.is_active ? t('common.active') : t('common.inactive') }}
              </span>
            </p>
          </div>
          <div class="actions">
            <button v-can="'request_types.edit'" class="ghost" type="button" @click="startEdit(selected)">
              {{ t('common.edit') }}
            </button>
            <button v-can="'request_types.edit'" class="ghost" type="button" @click="toggleActive(selected)">
              {{ selected.is_active ? t('common.deactivate') : t('common.activate') }}
            </button>
            <!-- Offered only for a type nothing references. The server
                 refuses the rest regardless, so this is guidance about
                 what will work, not the gate itself. -->
            <button
              v-if="isDeletable(selected)"
              v-can="'request_types.delete'"
              class="ghost danger"
              type="button"
              @click="remove(selected)"
            >
              {{ t('common.delete') }}
            </button>
          </div>
        </header>

        <dl class="facts">
          <div>
            <dt>{{ t('requestTypes.slaDays') }}</dt>
            <dd class="figure">{{ selected.default_sla_days ?? '—' }}</dd>
          </div>
          <div>
            <dt>{{ t('requestTypes.gradeThreshold') }}</dt>
            <dd class="figure">{{ selected.decision_grade_threshold ?? '—' }}</dd>
          </div>
          <div>
            <dt>{{ t('requestTypes.route') }}</dt>
            <dd>
              {{ selected.default_administrative_route ? t('requestTypes.routes.' + selected.default_administrative_route) : '—' }}
            </dd>
          </div>
          <div v-if="workflows.length">
            <dt>{{ t('requestTypes.workflow') }}</dt>
            <dd>{{ workflowLabel(selected) }}</dd>
          </div>
          <div>
            <dt>{{ t('requestTypes.usage') }}</dt>
            <dd>
              {{ t('requestTypes.requestsCount', { count: selected.requests_count ?? 0 }) }}
              <template v-if="(selected.workflow_transitions_count ?? 0) > 0">
                <br />{{ t('requestTypes.rulesCount', { count: selected.workflow_transitions_count }) }}
              </template>
            </dd>
          </div>
          <div class="wide">
            <dt>{{ t('requestTypes.legalBasis') }}</dt>
            <dd>{{ selected.legal_basis_ar || '—' }}</dd>
          </div>
          <div v-if="selected.legal_basis_note_ar" class="wide">
            <dt>{{ t('requestTypes.legalBasisNote') }}</dt>
            <dd>{{ selected.legal_basis_note_ar }}</dd>
          </div>
        </dl>

        <section class="documents" aria-labelledby="request-type-documents">
          <div class="documents-head">
            <h4 id="request-type-documents">{{ t('requestTypes.documents.title') }}</h4>
            <p v-if="selectedDocuments.length" class="hint">
              {{ t('requestTypes.documents.summary', { total: selectedDocuments.length, required: requiredCount }) }}
            </p>
          </div>
          <p v-if="selectedDocuments.length === 0" class="hint">{{ t('requestTypes.documents.empty') }}</p>
          <div v-for="section in groupDocuments(selectedDocuments)" :key="section.group" class="document-group">
            <h5>{{ t('requestTypes.documents.groups.' + section.group) }}</h5>
            <ul>
              <li v-for="(doc, i) in section.items" :key="i" :class="{ required: doc.required }">
                <span class="mark" aria-hidden="true"></span>
                <code class="ltr" :title="t('requestTypes.catalog.number')">{{ documentNumber(doc.document_id) }}</code>
                <span class="doc-text">
                  <span>{{ documentLabel(doc, locale) }}</span>
                  <small v-if="doc.condition">{{ documentCondition(doc, locale) }}</small>
                </span>
                <span class="need">
                  {{ doc.required ? t('requestTypes.documents.required') : t('requestTypes.documents.optional') }}
                </span>
              </li>
            </ul>
          </div>
        </section>
      </article>
    </div>
  </section>
</template>

<style scoped>
/* -- Edit modal -- */
.form-section {
  min-inline-size: 0;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--space-4);
  margin: 0 0 var(--space-4);
}
.form-section legend { padding: 0 .4rem; font-weight: 600; color: var(--color-foreground); }
.form-section > .grid:last-child { margin-bottom: 0; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); gap: var(--space-4); margin-bottom: var(--space-4); }
label { display: flex; flex-direction: column; gap: .3rem; min-inline-size: 0; font-size: var(--text-base); color: var(--color-black-700); }
label.checkbox { flex-direction: row; align-items: center; gap: var(--space-2); }
input, select {
  padding: .5rem .6rem;
  border: 1px solid var(--color-border-hover);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
  color: var(--color-foreground);
}
/* Names, then group and the «مطلوب» switch, then the condition pair: the two
   things an editor scans for sit on one line instead of floating loose. */
.document-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--space-3);
  padding: var(--space-3);
  margin-bottom: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: var(--color-surface-hover);
}
.document-controls { display: flex; align-items: flex-end; justify-content: space-between; gap: var(--space-3); padding-block-end: .3rem; }

/* Appendix 57's matrix, read as the checklist it is: a filled square is a
   document the file cannot go forward without, an empty one may be skipped.
   The word beside it says the same, so the square is never the only cue. */
.documents { padding-top: var(--space-4); border-top: 1px solid var(--color-border); }
.documents-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: var(--space-2); }
.documents-head h4 { margin: 0 0 var(--space-2); font-size: var(--text-lg); }
.document-group h5 { margin: var(--space-4) 0 var(--space-2); font-size: var(--text-sm); font-weight: 600; color: var(--color-muted); }
.document-group ul { list-style: none; margin: 0; padding: 0; }
.document-group li {
  display: grid;
  grid-template-columns: auto auto minmax(0, 1fr) auto;
  align-items: baseline;
  gap: var(--space-3);
  padding: var(--space-2) 0;
  border-bottom: 1px solid var(--color-border);
}
.document-group li:last-child { border-bottom: 0; }
.mark {
  inline-size: .7rem;
  block-size: .7rem;
  border: 1.5px solid var(--color-border-hover);
  border-radius: 2px;
  transform: translateY(.05rem);
}
li.required .mark { background: var(--color-brand); border-color: var(--color-brand); }
.doc-text { display: flex; flex-direction: column; gap: .1rem; }
.doc-text small { color: var(--color-muted); font-size: var(--text-sm); }
.need { font-size: var(--text-sm); color: var(--color-muted); white-space: nowrap; }
li.required .need { color: var(--color-foreground); font-weight: 600; }

.view-tabs { display: flex; flex-wrap: wrap; gap: var(--space-2); margin-bottom: var(--space-4); }
.view-tabs .count { font-variant-numeric: tabular-nums; opacity: .75; }
.document-name { grid-column: 1 / -1; display: flex; flex-wrap: wrap; align-items: baseline; gap: var(--space-3); }
.document-name small { display: block; color: var(--color-muted); font-size: var(--text-sm); }
.document-pick { margin-top: var(--space-2); }
code { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--color-muted); white-space: nowrap; }

@media (max-width: 640px) {
  .document-row { grid-template-columns: minmax(0, 1fr); }
}
</style>
