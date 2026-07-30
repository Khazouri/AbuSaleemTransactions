<script setup>
/** Bilingual reusable text-template editor — Stage 10. */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'

const { t, locale } = useI18n()
const templates = ref([])
const loading = ref(false)
const saving = ref(false)
const loadError = ref(null)
const formError = ref(null)
const errors = ref({})
const editingId = ref(null)
const showForm = ref(false)
const blankForm = () => ({ code: '', name_ar: '', name_en: '', subject_ar: '', subject_en: '', body_ar: '', body_en: '', is_active: true })
const form = ref(blankForm())
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
    for (const key of ['name_en', 'subject_ar', 'subject_en', 'body_en']) payload[key] = payload[key] || null
    if (editingId.value === null) await api.post('/templates', payload)
    else await api.put(`/templates/${editingId.value}`, payload)
    cancelForm(); await load()
  } catch (e) { if (e?.response?.status === 422) { errors.value = e.response.data.errors ?? {}; formError.value = e.response.data.message ?? null } else formError.value = 'تعذّر الحفظ. حاول مرة أخرى.' } finally { saving.value = false }
}
async function remove(template) { if (!window.confirm(t('common.confirmDelete'))) return; formError.value = null; try { await api.delete(`/templates/${template.id}`); await load() } catch (e) { formError.value = e?.response?.data?.message ?? 'تعذّر الحذف.' } }
onMounted(load)
</script>

<template>
  <section>
    <div class="toolbar"><button v-can="'templates.add'" class="primary" @click="startCreate">+ {{ t('templates.add') }}</button></div>
    <p v-if="formError" class="alert">{{ formError }}</p>
    <form v-if="showForm" class="card form" @submit.prevent="save">
      <h3>{{ editingId === null ? t('templates.add') : t('templates.edit') }}</h3>
      <div class="grid">
        <label>{{ t('templates.code') }} *<input v-model="form.code" class="ltr" required /><small class="hint">{{ t('templates.codeHint') }}</small><small v-if="errors.code" class="field-error">{{ errors.code[0] }}</small></label>
        <label>{{ t('templates.nameAr') }} *<input v-model="form.name_ar" required /><small v-if="errors.name_ar" class="field-error">{{ errors.name_ar[0] }}</small></label>
        <label>{{ t('templates.nameEn') }}<input v-model="form.name_en" class="ltr" /><small v-if="errors.name_en" class="field-error">{{ errors.name_en[0] }}</small></label>
        <label>{{ t('templates.subjectAr') }}<input v-model="form.subject_ar" /><small v-if="errors.subject_ar" class="field-error">{{ errors.subject_ar[0] }}</small></label>
        <label>{{ t('templates.subjectEn') }}<input v-model="form.subject_en" class="ltr" /><small v-if="errors.subject_en" class="field-error">{{ errors.subject_en[0] }}</small></label>
      </div>
      <div class="grid bodies">
        <label>{{ t('templates.bodyAr') }} *<textarea v-model="form.body_ar" rows="7" required /><small v-if="errors.body_ar" class="field-error">{{ errors.body_ar[0] }}</small></label>
        <label>{{ t('templates.bodyEn') }}<textarea v-model="form.body_en" class="ltr" rows="7" /><small v-if="errors.body_en" class="field-error">{{ errors.body_en[0] }}</small></label>
      </div>
      <label class="checkbox"><input v-model="form.is_active" type="checkbox" />{{ t('common.active') }}</label>
      <div class="actions"><button class="primary" type="submit" :disabled="saving">{{ saving ? t('common.saving') : t('common.save') }}</button><button class="ghost" type="button" @click="cancelForm">{{ t('common.cancel') }}</button></div>
    </form>
    <div class="card list"><p v-if="loading" class="state">{{ t('common.loading') }}</p><p v-else-if="loadError" class="state error">{{ t('nav.error') }} <button class="ghost" @click="load">{{ t('common.retry') }}</button></p><p v-else-if="templates.length === 0" class="state">{{ t('templates.empty') }}</p><table v-else><thead><tr><th>{{ t('templates.code') }}</th><th>{{ t('templates.nameAr') }}</th><th></th></tr></thead><tbody><tr v-for="template in templates" :key="template.id" :class="{ dimmed: !template.is_active }"><td><code class="ltr">{{ template.code }}</code></td><td>{{ label(template) }} <span v-if="!template.is_active" class="pill">{{ t('common.inactive') }}</span></td><td class="row-actions"><button v-can="'templates.edit'" class="ghost" @click="startEdit(template)">{{ t('common.edit') }}</button><button v-can="'templates.delete'" class="ghost danger" @click="remove(template)">{{ t('common.delete') }}</button></td></tr></tbody></table></div>
  </section>
</template>

<style scoped>
.toolbar { margin-bottom: 1rem; }.card { padding: 1.25rem; margin-bottom: 1rem; }.form h3 { margin: 0 0 1rem; font-size: 1rem; }.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; }.bodies { margin-top: 1rem; }label { display: flex; flex-direction: column; gap: .3rem; font-size: .875rem; }label.checkbox { flex-direction: row; align-items: center; gap: .5rem; margin-top: 1rem; }input, textarea { padding: .5rem .6rem; border: 1px solid var(--color-border); border-radius: var(--radius-lg); background: var(--color-surface); color: var(--color-foreground); }textarea { resize: vertical; }.hint, .state { color: var(--color-muted); font-size: .8rem; }.field-error, .state.error { color: var(--color-red); }.actions { display: flex; gap: .5rem; margin-top: 1.25rem; }.alert { padding: .65rem .8rem; color: var(--color-red); border: 1px solid var(--color-red); border-radius: var(--radius-lg); margin: 0 0 1rem; }.list { overflow-x: auto; }table { width: 100%; border-collapse: collapse; }th { text-align: start; color: var(--color-muted); font-size: .78rem; }th, td { padding: .6rem .5rem; border-bottom: 1px solid var(--color-border); }tr:last-child td { border-bottom: 0; }tr.dimmed { opacity: .55; }.pill { margin-inline-start: .5rem; padding: .1rem .5rem; background: var(--color-black-100); color: var(--color-muted); border-radius: var(--radius-full); font-size: .72rem; }.row-actions { text-align: end; white-space: nowrap; }button { cursor: pointer; border-radius: var(--radius-lg); font-size: .85rem; }.primary { padding: .5rem .9rem; border: 0; background: var(--color-primary); color: var(--color-on-primary); }.ghost { padding: .35rem .6rem; border: 1px solid var(--color-border); background: var(--color-surface); color: var(--color-foreground); margin-inline-start: .3rem; }.ghost.danger { color: var(--color-red); border-color: var(--color-red); }
</style>
