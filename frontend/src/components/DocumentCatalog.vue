<script setup>
/**
 * The managed documents list (قائمة المستندات, 2026-10-23), the second tab of
 * the Request Types screen. Every row a type lists is picked from here, and an
 * entry's id is the permanent number both tabs show.
 *
 * An entry holds the defaults a picked row starts from. Editing it changes
 * none of the types already listing it: each type keeps the wording it copied,
 * because that wording is part of the key its files are stored under. Which
 * types list an entry is read from the types the screen already holds.
 *
 * No delete, the master-data rule: an entry is deactivated, which takes it out
 * of the picker and leaves every type listing it intact.
 */
import { computed, nextTick, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import AppModal from './AppModal.vue'
import { DOCUMENT_GROUPS, documentNumber } from '../lib/requiredDocuments'

const props = defineProps({
  documents: { type: Array, required: true },
  types: { type: Array, required: true },
})
const emit = defineEmits(['changed', 'open-type'])

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()

const listEl = ref(null)
const detailHeading = ref(null)
const showForm = ref(false)
const editingId = ref(null)
const saving = ref(false)
const formError = ref(null)
const errors = ref({})

const blankForm = () => ({
  name_ar: '',
  name_en: '',
  group: 'specific',
  condition_ar: '',
  condition_en: '',
  required: true,
})
const form = ref(blankForm())

function name(doc) {
  return locale.value === 'ar' ? doc.name_ar : doc.name_en || doc.name_ar
}

function typeName(type) {
  return locale.value === 'ar' ? type.name_ar : type.name_en || type.name_ar
}

// Same rule as the types tab: a wide screen opens on the first entry, a phone
// on the list.
const selected = computed(
  () => props.documents.find((doc) => String(doc.id) === String(route.query.document)) ?? props.documents[0] ?? null,
)
const showingDetail = computed(
  () => route.query.document != null && String(selected.value?.id) === String(route.query.document),
)

const usedBy = computed(() =>
  props.types.filter((type) => (type.required_documents ?? []).some((row) => row.document_id === selected.value?.id)),
)

const condition = computed(() => {
  const doc = selected.value
  if (!doc) return ''
  return (locale.value === 'ar' ? doc.condition_ar || doc.condition_en : doc.condition_en || doc.condition_ar) || ''
})

async function select(doc) {
  await router.replace({ query: { tab: 'documents', document: doc.id } })
  await nextTick()
  if (listEl.value?.offsetParent === null) detailHeading.value?.focus()
}

async function backToList() {
  const id = selected.value?.id
  await router.replace({ query: { tab: 'documents' } })
  await nextTick()
  listEl.value?.querySelector('[data-document-id="' + id + '"]')?.focus()
}

function startCreate() {
  editingId.value = null
  form.value = blankForm()
  errors.value = {}
  formError.value = null
  showForm.value = true
}

function startEdit(doc) {
  editingId.value = doc.id
  form.value = {
    name_ar: doc.name_ar,
    name_en: doc.name_en ?? '',
    group: doc.group,
    condition_ar: doc.condition_ar ?? '',
    condition_en: doc.condition_en ?? '',
    required: doc.required,
  }
  errors.value = {}
  formError.value = null
  showForm.value = true
}

function cancelForm() {
  showForm.value = false
  editingId.value = null
}

async function save() {
  saving.value = true
  errors.value = {}
  formError.value = null
  try {
    // Blank text goes back as null, so clearing a field clears the column.
    const payload = {
      ...form.value,
      name_en: form.value.name_en || null,
      condition_ar: form.value.condition_ar || null,
      condition_en: form.value.condition_en || null,
    }
    const { data } = editingId.value === null
      ? await api.post('/documents', payload)
      : await api.put('/documents/' + editingId.value, payload)

    cancelForm()
    emit('changed')
    await router.replace({ query: { tab: 'documents', document: data.data.id } })
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

async function toggleActive(doc) {
  formError.value = null
  try {
    await api.patch('/documents/' + doc.id + '/toggle-active')
    emit('changed')
  } catch (e) {
    formError.value = e?.response?.data?.message ?? t('requestTypes.saveFailed')
  }
}

defineExpose({ startCreate })
</script>

<template>
  <p v-if="formError && !showForm" class="alert">{{ formError }}</p>

  <AppModal
    v-if="showForm"
    :title="editingId === null ? t('requestTypes.documents.add') : t('requestTypes.catalog.edit')"
    @close="cancelForm"
  >
    <form class="form" @submit.prevent="save">
      <p v-if="formError" class="alert">{{ formError }}</p>
      <p class="hint">{{ t('requestTypes.catalog.defaultsNote') }}</p>

      <div class="grid">
        <label>
          <span>{{ t('requestTypes.documents.nameAr') }}<span class="required-mark" aria-hidden="true">*</span></span>
          <input v-model="form.name_ar" required />
          <small v-if="errors.name_ar" class="field-error">{{ errors.name_ar[0] }}</small>
        </label>
        <label>
          {{ t('requestTypes.documents.nameEn') }}
          <input v-model="form.name_en" class="ltr" />
        </label>
        <label>
          {{ t('requestTypes.documents.group') }}
          <select v-model="form.group">
            <option v-for="group in DOCUMENT_GROUPS" :key="group" :value="group">
              {{ t('requestTypes.documents.groups.' + group) }}
            </option>
          </select>
          <small v-if="errors.group" class="field-error">{{ errors.group[0] }}</small>
        </label>
        <label>
          {{ t('requestTypes.documents.conditionAr') }}
          <input v-model="form.condition_ar" />
        </label>
        <label>
          {{ t('requestTypes.documents.conditionEn') }}
          <input v-model="form.condition_en" class="ltr" />
        </label>
      </div>
      <label class="checkbox">
        <input v-model="form.required" type="checkbox" />{{ t('requestTypes.documents.required') }}
      </label>

      <div class="modal-actions">
        <button class="ghost" type="button" @click="cancelForm">{{ t('common.cancel') }}</button>
        <button class="primary" type="submit" :disabled="saving">
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </form>
  </AppModal>

  <div v-if="documents.length === 0" class="card card-flat card-pad">
    <p class="state">{{ t('requestTypes.catalog.empty') }}</p>
  </div>

  <div v-else class="split-view" :class="{ 'showing-detail': showingDetail }">
    <nav ref="listEl" class="split-list card card-flat" :aria-label="t('requestTypes.tabs.documents')">
      <ul>
        <li v-for="doc in documents" :key="doc.id">
          <button
            type="button"
            class="split-item"
            :class="{ inactive: !doc.is_active }"
            :data-document-id="doc.id"
            :aria-current="selected?.id === doc.id ? 'true' : undefined"
            @click="select(doc)"
          >
            <span class="split-item-name">{{ name(doc) }}</span>
            <span class="split-item-meta">
              <code class="ltr">{{ documentNumber(doc.id) }}</code>
              <span v-if="!doc.is_active" class="pill">{{ t('common.inactive') }}</span>
            </span>
          </button>
        </li>
      </ul>
    </nav>

    <article v-if="selected" class="split-detail card card-flat card-pad" aria-labelledby="catalog-document-title">
      <button class="ghost split-back" type="button" @click="backToList">{{ t('requestTypes.catalog.backToList') }}</button>

      <header class="split-head">
        <div>
          <h3 id="catalog-document-title" ref="detailHeading" tabindex="-1">{{ name(selected) }}</h3>
          <p class="split-sub">
            <code class="ltr" :title="t('requestTypes.catalog.number')">{{ documentNumber(selected.id) }}</code>
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
        </div>
      </header>

      <dl class="facts">
        <div class="wide">
          <dt>{{ locale === 'ar' ? t('requestTypes.documents.nameEn') : t('requestTypes.documents.nameAr') }}</dt>
          <dd>{{ (locale === 'ar' ? selected.name_en : selected.name_ar) || '—' }}</dd>
        </div>
        <div>
          <dt>{{ t('requestTypes.documents.group') }}</dt>
          <dd>{{ t('requestTypes.documents.groups.' + selected.group) }}</dd>
        </div>
        <div>
          <dt>{{ t('requestTypes.catalog.requiredDefault') }}</dt>
          <dd>{{ selected.required ? t('requestTypes.documents.required') : t('requestTypes.documents.optional') }}</dd>
        </div>
        <div class="wide">
          <dt>{{ t('requestTypes.catalog.condition') }}</dt>
          <dd>{{ condition || '—' }}</dd>
        </div>
      </dl>

      <section class="used-by" aria-labelledby="catalog-used-by">
        <h4 id="catalog-used-by">{{ t('requestTypes.catalog.usedBy', { count: usedBy.length }) }}</h4>
        <p v-if="usedBy.length === 0" class="hint">{{ t('requestTypes.catalog.unused') }}</p>
        <ul v-else>
          <li v-for="type in usedBy" :key="type.id">
            <button class="link" type="button" @click="emit('open-type', type.id)">{{ typeName(type) }}</button>
            <code class="ltr">{{ type.code }}</code>
          </li>
        </ul>
      </section>
    </article>
  </div>
</template>

<style scoped>
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); gap: var(--space-4); margin-bottom: var(--space-4); }
label { display: flex; flex-direction: column; gap: .3rem; min-inline-size: 0; font-size: var(--text-base); color: var(--color-black-700); }
label.checkbox { flex-direction: row; align-items: center; gap: var(--space-2); margin-bottom: var(--space-4); }
input, select {
  padding: .5rem .6rem;
  border: 1px solid var(--color-border-hover);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
  color: var(--color-foreground);
}
.used-by { padding-top: var(--space-4); border-top: 1px solid var(--color-border); }
.used-by h4 { margin: 0 0 var(--space-2); font-size: var(--text-lg); }
.used-by ul { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: var(--space-2) var(--space-4); }
.used-by li { display: flex; align-items: baseline; gap: var(--space-2); }
.link {
  padding: 0;
  border: 0;
  background: none;
  color: var(--color-brand-text);
  text-decoration: underline;
  text-underline-offset: 2px;
  cursor: pointer;
}
</style>
