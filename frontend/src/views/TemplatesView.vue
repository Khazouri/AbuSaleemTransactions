<script setup>
/** Bilingual reusable text-template editor — Stage 10. */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import AppModal from '../components/AppModal.vue'

const { t, locale } = useI18n()
const templates = ref([])
const loading = ref(false)
const saving = ref(false)
const loadError = ref(null)
const formError = ref(null)
const errors = ref({})
const editingId = ref(null)
const showForm = ref(false)
const blankForm = () => ({ code: '', category: '', name_ar: '', name_en: '', subject_ar: '', subject_en: '', body_ar: '', body_en: '', is_active: true })
const form = ref(blankForm())
// Stage 42 — the tokens DecisionDraftComposer interpolates. Kept as plain
// data (not passed through t()) since vue-i18n's own message syntax uses
// single braces for interpolation and would choke on a literal "{{...}}".
const decisionPlaceholders = ['reference_number', 'request_title', 'employee_name', 'department', 'request_type', 'committee_name', 'meeting_date', 'decision_date']
// A plain function, not an inline template literal: writing the literal
// "{{"/"}}" delimiters directly inside a `{{ }}` interpolation expression
// confuses Vue's compiler, which scans for the first "}}" to close it.
function braced(token) { return '{{' + token + '}}' }
const activeName = computed(() => locale.value === 'ar' ? 'name_ar' : 'name_en')

function label(template) { return template[activeName.value] || template.name_ar || template.name_en }
async function load() { loading.value = true; loadError.value = null; try { const { data } = await api.get('/templates'); templates.value = data.data ?? data } catch (e) { loadError.value = e } finally { loading.value = false } }
function startCreate() { editingId.value = null; form.value = blankForm(); errors.value = {}; formError.value = null; showForm.value = true }
function startEdit(template) { editingId.value = template.id; form.value = { ...blankForm(), ...template }; errors.value = {}; formError.value = null; showForm.value = true }
function cancelForm() { editingId.value = null; showForm.value = false; errors.value = {}; formError.value = null }
async function save() {
  saving.value = true; errors.value = {}; formError.value = null
  try {
    const payload = { ...form.value }
    for (const key of ['category', 'name_en', 'subject_ar', 'subject_en', 'body_en']) payload[key] = payload[key] || null
    if (editingId.value === null) await api.post('/templates', payload)
    else await api.put(`/templates/${editingId.value}`, payload)
    cancelForm(); await load()
  } catch (e) { if (e?.response?.status === 422) { errors.value = e.response.data.errors ?? {}; formError.value = e.response.data.message ?? null } else formError.value = 'تعذّر الحفظ. حاول مرة أخرى.' } finally { saving.value = false }
}
async function remove(template) { if (!window.confirm(t('common.confirmDelete'))) return; formError.value = null; try { await api.delete(`/templates/${template.id}`); await load() } catch (e) { formError.value = e?.response?.data?.message ?? 'تعذّر الحذف.' } }
onMounted(load)
</script>

<template>
  <section class="page">
    <div class="heading">
      <div>
        <h2>{{ t('templates.title') }}</h2>
        <p v-if="!loading && !loadError" class="subtitle">{{ templates.length }}</p>
      </div>
      <button v-can="'templates.add'" class="primary" type="button" @click="startCreate">{{ t('templates.add') }}</button>
    </div>
    <p v-if="formError && !showForm" class="alert">{{ formError }}</p>
    <AppModal v-if="showForm" :title="editingId === null ? t('templates.add') : t('templates.edit')" wide @close="cancelForm">
      <form class="form" @submit.prevent="save">
        <p v-if="formError" class="alert">{{ formError }}</p>
        <div class="grid">
          <label>{{ t('templates.code') }} *<input v-model="form.code" class="ltr" required /><small class="hint">{{ t('templates.codeHint') }}</small><small v-if="errors.code" class="field-error">{{ errors.code[0] }}</small></label>
          <label>{{ t('templates.category') }}
            <select v-model="form.category">
              <option value="">{{ t('templates.categoryGeneral') }}</option>
              <option value="decision">{{ t('templates.categoryDecision') }}</option>
            </select>
            <small v-if="errors.category" class="field-error">{{ errors.category[0] }}</small>
          </label>
          <label>{{ t('templates.nameAr') }} *<input v-model="form.name_ar" required /><small v-if="errors.name_ar" class="field-error">{{ errors.name_ar[0] }}</small></label>
          <label>{{ t('templates.nameEn') }}<input v-model="form.name_en" class="ltr" /><small v-if="errors.name_en" class="field-error">{{ errors.name_en[0] }}</small></label>
          <label>{{ t('templates.subjectAr') }}<input v-model="form.subject_ar" /><small v-if="errors.subject_ar" class="field-error">{{ errors.subject_ar[0] }}</small></label>
          <label>{{ t('templates.subjectEn') }}<input v-model="form.subject_en" class="ltr" /><small v-if="errors.subject_en" class="field-error">{{ errors.subject_en[0] }}</small></label>
        </div>
        <div class="grid bodies">
          <label>{{ t('templates.bodyAr') }} *<textarea v-model="form.body_ar" rows="7" required /><small v-if="errors.body_ar" class="field-error">{{ errors.body_ar[0] }}</small></label>
          <label>{{ t('templates.bodyEn') }}<textarea v-model="form.body_en" class="ltr" rows="7" /><small v-if="errors.body_en" class="field-error">{{ errors.body_en[0] }}</small></label>
        </div>
        <p v-if="form.category === 'decision'" class="hint placeholders-hint">
          {{ t('templates.placeholdersHint') }}
          <code v-for="token in decisionPlaceholders" :key="token" class="ltr">{{ braced(token) }}</code>
        </p>
        <label class="checkbox"><input v-model="form.is_active" type="checkbox" />{{ t('common.active') }}</label>
        <div class="modal-actions">
          <button class="ghost" type="button" @click="cancelForm">{{ t('common.cancel') }}</button>
          <button class="primary" type="submit" :disabled="saving">{{ saving ? t('common.saving') : t('common.save') }}</button>
        </div>
      </form>
    </AppModal>
    <div class="card card-flat card-pad list"><p v-if="loading" class="state">{{ t('common.loading') }}</p><p v-else-if="loadError" class="alert">{{ t('nav.error') }} <button class="ghost" @click="load">{{ t('common.retry') }}</button></p><p v-else-if="templates.length === 0" class="state">{{ t('templates.empty') }}</p><table v-else class="data-table"><thead><tr><th>{{ t('templates.code') }}</th><th>{{ t('templates.nameAr') }}</th><th></th></tr></thead><tbody><tr v-for="template in templates" :key="template.id" :class="{ dimmed: !template.is_active }"><td><code class="ltr">{{ template.code }}</code></td><td>{{ label(template) }} <span v-if="template.category === 'decision'" class="pill">{{ t('templates.categoryDecision') }}</span> <span v-if="!template.is_active" class="pill">{{ t('common.inactive') }}</span></td><td class="row-actions"><button v-can="'templates.edit'" class="ghost" @click="startEdit(template)">{{ t('common.edit') }}</button><button v-can="'templates.delete'" class="ghost danger" @click="remove(template)">{{ t('common.delete') }}</button></td></tr></tbody></table></div>
  </section>
</template>

<style scoped>
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); gap: var(--space-4); }
.bodies { margin-top: var(--space-4); }
label { display: flex; flex-direction: column; gap: .3rem; font-size: var(--text-base); color: var(--color-black-700); }
label.checkbox { flex-direction: row; align-items: center; gap: var(--space-2); margin-top: var(--space-4); }
input, select, textarea { padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); color: var(--color-foreground); }
textarea { resize: vertical; }
.placeholders-hint { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem; margin-top: .35rem; }
.placeholders-hint code { padding: .1rem .4rem; background: var(--color-surface-hover); border-radius: var(--radius-full); font-size: var(--text-xs); }
.field-error { color: var(--color-danger-fg); }
.list { overflow-x: auto; }
tr.dimmed { opacity: .55; }
.row-actions { display: flex; justify-content: flex-end; gap: var(--space-2); white-space: nowrap; }
</style>
