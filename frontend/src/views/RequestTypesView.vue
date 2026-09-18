<script setup>
/**
 * Request-type catalogue (أنواع الطلبات) — CRUD over `request_types`.
 *
 * The rows here are not just labels. A type carries the SLA that becomes a
 * request's due_date, the decision grade at/above which the case must reach
 * the ministry, the default financial-impact flag, and [D] Appendix 57's
 * required-document matrix — so this screen is the one place those rules can
 * be changed without a reseed.
 *
 * Retiring a type is `toggle-active`, not delete: an inactive type disappears
 * from the intake picker while every request already filed under it keeps
 * resolving. The delete button is therefore offered only for a type nothing
 * references, and the server refuses the rest — see the controller's docblock
 * for why each of the two foreign keys fails quietly if left to itself.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import { DOCUMENT_GROUPS } from '../lib/requiredDocuments'

const { t, locale } = useI18n()

const types = ref([])
const loading = ref(false)
const saving = ref(false)
const loadError = ref(null)
const formError = ref(null)
const errors = ref({})
const editingId = ref(null)
const showForm = ref(false)
const expandedId = ref(null)

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
  default_has_financial_impact: false,
  default_administrative_route: '',
  legal_basis_ar: '',
  legal_basis_note_ar: '',
  required_documents: [],
})
const form = ref(blankForm())

const activeName = computed(() => (locale.value === 'ar' ? 'name_ar' : 'name_en'))

function label(type) {
  return type[activeName.value] || type.name_ar || type.name_en
}

function docLabel(pair) {
  if (!pair) return ''
  return locale.value === 'ar' ? pair.ar || pair.en : pair.en || pair.ar
}

/** A type is deletable only when nothing points at it; the server re-checks. */
function isDeletable(type) {
  return (type.requests_count ?? 0) === 0 && (type.workflow_transitions_count ?? 0) === 0
}

/** Appendix 57's grouping, dropping a group this type has no entries for. */
function documentsByGroup(type) {
  const list = Array.isArray(type.required_documents) ? type.required_documents : []

  return DOCUMENT_GROUPS
    .map((group) => ({ group, items: list.filter((doc) => (doc.group || 'basic') === group) }))
    .filter((section) => section.items.length > 0)
}

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const { data } = await api.get('/request-types')
    types.value = data.data ?? data
  } catch (e) {
    loadError.value = e
  } finally {
    loading.value = false
  }
}

function startCreate() {
  editingId.value = null
  form.value = blankForm()
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
      ar: doc.ar ?? '',
      en: doc.en ?? '',
      group: doc.group || 'basic',
      condition_ar: doc.condition?.ar ?? '',
      condition_en: doc.condition?.en ?? '',
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

function addDocument() {
  form.value.required_documents.push({
    ar: '',
    en: '',
    group: 'specific',
    condition_ar: '',
    condition_en: '',
  })
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
      legal_basis_ar: form.value.legal_basis_ar || null,
      legal_basis_note_ar: form.value.legal_basis_note_ar || null,
      // Back into Appendix 57's stored shape. An entirely blank condition goes
      // back as null, never as a pair of empty strings — documentCondition()
      // tests whether the key is set, so an empty pair would render a blank
      // qualifier chip on the intake checklist. The server normalises this
      // too; doing it here as well keeps the form honest about what it sent.
      required_documents: form.value.required_documents.map((doc) => ({
        ar: doc.ar,
        en: doc.en || null,
        group: doc.group,
        condition:
          (doc.condition_ar || '').trim() === '' && (doc.condition_en || '').trim() === ''
            ? null
            : { ar: doc.condition_ar || null, en: doc.condition_en || null },
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
  <section>
    <div class="toolbar">
      <button v-can="'request_types.add'" class="primary" @click="startCreate">
        + {{ t('requestTypes.add') }}
      </button>
    </div>

    <p v-if="formError" class="alert">{{ formError }}</p>

    <form v-if="showForm" class="card form" @submit.prevent="save">
      <h3>{{ editingId === null ? t('requestTypes.add') : t('requestTypes.edit') }}</h3>

      <div class="grid">
        <label>
          {{ t('requestTypes.code') }}
          <input v-model="form.code" class="ltr" />
          <small class="hint">{{ t('requestTypes.codeHint') }}</small>
          <small v-if="errors.code" class="field-error">{{ errors.code[0] }}</small>
        </label>
        <label>
          {{ t('requestTypes.nameAr') }} *
          <input v-model="form.name_ar" required />
          <small v-if="errors.name_ar" class="field-error">{{ errors.name_ar[0] }}</small>
        </label>
        <label>
          {{ t('requestTypes.nameEn') }}
          <input v-model="form.name_en" class="ltr" />
          <small v-if="errors.name_en" class="field-error">{{ errors.name_en[0] }}</small>
        </label>
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
            <option v-for="route in ROUTES" :key="route" :value="route">
              {{ t('requestTypes.routes.' + route) }}
            </option>
          </select>
          <small class="hint">{{ t('requestTypes.routeHint') }}</small>
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

      <div class="checks">
        <label class="checkbox">
          <input v-model="form.is_active" type="checkbox" />{{ t('common.active') }}
        </label>
        <label class="checkbox">
          <input v-model="form.default_has_financial_impact" type="checkbox" />
          {{ t('requestTypes.financialImpact') }}
        </label>
      </div>

      <fieldset class="documents">
        <legend>{{ t('requestTypes.documents.title') }}</legend>
        <p class="hint">{{ t('requestTypes.documents.sourceNote') }}</p>

        <p v-if="form.required_documents.length === 0" class="hint">
          {{ t('requestTypes.documents.empty') }}
        </p>

        <div v-for="(doc, index) in form.required_documents" :key="index" class="document-row">
          <label>
            {{ t('requestTypes.documents.nameAr') }} *
            <input v-model="doc.ar" required />
            <small v-if="documentError(index, 'ar')" class="field-error">
              {{ documentError(index, 'ar') }}
            </small>
          </label>
          <label>
            {{ t('requestTypes.documents.nameEn') }}
            <input v-model="doc.en" class="ltr" />
          </label>
          <label>
            {{ t('requestTypes.documents.group') }}
            <select v-model="doc.group">
              <option v-for="group in DOCUMENT_GROUPS" :key="group" :value="group">
                {{ t('requestTypes.documents.groups.' + group) }}
              </option>
            </select>
          </label>
          <label>
            {{ t('requestTypes.documents.conditionAr') }}
            <input v-model="doc.condition_ar" />
            <small class="hint">{{ t('requestTypes.documents.conditionHint') }}</small>
          </label>
          <label>
            {{ t('requestTypes.documents.conditionEn') }}
            <input v-model="doc.condition_en" class="ltr" />
          </label>
          <button class="ghost danger remove" type="button" @click="removeDocument(index)">
            {{ t('common.delete') }}
          </button>
        </div>

        <button class="ghost" type="button" @click="addDocument">
          + {{ t('requestTypes.documents.add') }}
        </button>
      </fieldset>

      <div class="actions">
        <button class="primary" type="submit" :disabled="saving">
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
        <button class="ghost" type="button" @click="cancelForm">{{ t('common.cancel') }}</button>
      </div>
    </form>

    <div class="card list">
      <p v-if="loading" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="loadError" class="state error">
        {{ t('nav.error') }}
        <button class="ghost" @click="load">{{ t('common.retry') }}</button>
      </p>
      <p v-else-if="types.length === 0" class="state">{{ t('requestTypes.empty') }}</p>
      <table v-else>
        <thead>
          <tr>
            <th>{{ t('requestTypes.code') }}</th>
            <th>{{ t('requestTypes.name') }}</th>
            <th>{{ t('requestTypes.slaDays') }}</th>
            <th>{{ t('requestTypes.gradeThreshold') }}</th>
            <th>{{ t('requestTypes.usage') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <template v-for="type in types" :key="type.id">
            <tr :class="{ dimmed: !type.is_active }">
              <td><code class="ltr">{{ type.code || '—' }}</code></td>
              <td>
                {{ label(type) }}
                <span v-if="!type.is_active" class="pill">{{ t('common.inactive') }}</span>
                <span v-if="type.default_has_financial_impact" class="pill">
                  {{ t('requestTypes.financialImpactShort') }}
                </span>
                <span v-if="type.default_administrative_route" class="pill">
                  {{ t('requestTypes.routes.' + type.default_administrative_route) }}
                </span>
              </td>
              <td class="ltr">{{ type.default_sla_days ?? '—' }}</td>
              <td class="ltr">{{ type.decision_grade_threshold ?? '—' }}</td>
              <td class="usage">
                <span>{{ t('requestTypes.requestsCount', { count: type.requests_count ?? 0 }) }}</span>
                <span v-if="(type.workflow_transitions_count ?? 0) > 0">
                  {{ t('requestTypes.rulesCount', { count: type.workflow_transitions_count }) }}
                </span>
              </td>
              <td class="row-actions">
                <button class="ghost" @click="expandedId = expandedId === type.id ? null : type.id">
                  {{ t('requestTypes.documents.show', { count: (type.required_documents ?? []).length }) }}
                </button>
                <button v-can="'request_types.edit'" class="ghost" @click="startEdit(type)">
                  {{ t('common.edit') }}
                </button>
                <button v-can="'request_types.edit'" class="ghost" @click="toggleActive(type)">
                  {{ type.is_active ? t('common.deactivate') : t('common.activate') }}
                </button>
                <!-- Offered only for a type nothing references. The server
                     refuses the rest regardless, so this is guidance about
                     what will work, not the gate itself. -->
                <button
                  v-if="isDeletable(type)"
                  v-can="'request_types.delete'"
                  class="ghost danger"
                  @click="remove(type)"
                >
                  {{ t('common.delete') }}
                </button>
              </td>
            </tr>
            <tr v-if="expandedId === type.id" class="documents-row">
              <td colspan="6">
                <p v-if="(type.required_documents ?? []).length === 0" class="hint">
                  {{ t('requestTypes.documents.empty') }}
                </p>
                <div
                  v-for="section in documentsByGroup(type)"
                  :key="section.group"
                  class="document-section"
                >
                  <h4>{{ t('requestTypes.documents.groups.' + section.group) }}</h4>
                  <ul>
                    <li v-for="(doc, i) in section.items" :key="i">
                      {{ docLabel(doc) }}
                      <span v-if="doc.condition" class="pill">{{ docLabel(doc.condition) }}</span>
                    </li>
                  </ul>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.toolbar { margin-bottom: 1rem; }
.card { padding: 1.25rem; margin-bottom: 1rem; }
.form h3 { margin: 0 0 1rem; font-size: 1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1rem; }
label { display: flex; flex-direction: column; gap: .3rem; font-size: .875rem; }
label.checkbox { flex-direction: row; align-items: center; gap: .5rem; }
.checks { display: flex; flex-wrap: wrap; gap: 1.25rem; margin-bottom: 1rem; }
input, select {
  padding: .5rem .6rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
  color: var(--color-foreground);
}
.documents { border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 1rem; margin-bottom: 1rem; }
.documents legend { padding: 0 .4rem; font-size: .875rem; }
.document-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: .75rem;
  align-items: end;
  padding: .75rem 0;
  border-bottom: 1px solid var(--color-border);
}
.document-row .remove { align-self: end; }
.document-section h4 { margin: .5rem 0 .25rem; font-size: .8rem; color: var(--color-muted); }
.document-section ul { margin: 0 0 .5rem; padding-inline-start: 1.1rem; font-size: .84rem; }
.documents-row td { background: var(--color-black-100); }
.hint, .state { color: var(--color-muted); font-size: .8rem; }
.field-error, .state.error { color: var(--color-red); }
.actions { display: flex; gap: .5rem; margin-top: 1rem; }
.alert {
  padding: .65rem .8rem;
  color: var(--color-red);
  border: 1px solid var(--color-red);
  border-radius: var(--radius-lg);
  margin: 0 0 1rem;
}
.list { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th { text-align: start; color: var(--color-muted); font-size: .78rem; }
th, td { padding: .6rem .5rem; border-bottom: 1px solid var(--color-border); }
tr:last-child td { border-bottom: 0; }
tr.dimmed { opacity: .55; }
.usage { font-size: .8rem; color: var(--color-muted); display: flex; flex-direction: column; gap: .15rem; }
.pill {
  margin-inline-start: .5rem;
  padding: .1rem .5rem;
  background: var(--color-black-100);
  color: var(--color-muted);
  border-radius: var(--radius-full);
  font-size: .72rem;
}
.row-actions { text-align: end; white-space: nowrap; }
button { cursor: pointer; border-radius: var(--radius-lg); font-size: .85rem; }
.primary { padding: .5rem .9rem; border: 0; background: var(--color-primary); color: var(--color-on-primary); }
.ghost {
  padding: .35rem .6rem;
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  color: var(--color-foreground);
  margin-inline-start: .3rem;
}
.ghost.danger { color: var(--color-red); border-color: var(--color-red); }
</style>
