<script setup>
/** Stage 13 — single-submit request intake, including private attachments. */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
// Stage 72 — [D] Appendix 57's grouped document matrix, shared with the
// request workspace so both screens read the same list the same way.
import { documentCondition, documentLabel, groupDocuments } from '../lib/requiredDocuments'
// Stage 83 — [D] Appendix 16 classifies a new request raised after an
// earlier one on the same subject; the two it routes elsewhere are greyed out.
import { PRIOR_RELATIONS } from '../lib/lifecycle'

const { t, locale } = useI18n()
const options = ref({ departments: [], types: [] })
const form = ref(blankForm())
const files = ref([])
const loadingOptions = ref(false)
const submitting = ref(false)
const errors = ref({})
const error = ref('')
const created = ref(null)
// Stage 83 — Appendix 16's own search, run before the form is submitted so
// the employee is shown the open file rather than a refusal after the fact.
const duplicate = ref(null)
const duplicateChecking = ref(false)

const acceptedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png']
const maxBytes = 20 * 1024 * 1024
const isBusy = computed(() => loadingOptions.value || submitting.value)
const selectedType = computed(() => options.value.types.find(
  (type) => String(type.id) === String(form.value.request_type_id),
))
// Stage 72 — Appendix 57's groups for the chosen type; empty until one is picked.
const documentSections = computed(() => groupDocuments(selectedType.value?.required_documents))

function blankForm() {
  return { title: '', description: '', department_id: '', request_type_id: '', decision_grade: '', prior_relation: '' }
}

/** Appendix 16 searches "برقم الموظف وموضوع المعاملة" — the type is the subject. */
async function checkDuplicates() {
  duplicate.value = null
  form.value.prior_relation = ''
  if (!form.value.request_type_id) return
  duplicateChecking.value = true
  try {
    const { data } = await api.get('/requests/duplicate-check', {
      params: { request_type_id: form.value.request_type_id },
    })
    duplicate.value = data.data
  } catch {
    // A failed lookup must not block intake: the server refuses a genuine
    // duplicate on its own, so this panel is a courtesy, not the gate.
    duplicate.value = null
  } finally {
    duplicateChecking.value = false
  }
}

function name(item) {
  return locale.value === 'ar' ? item.name_ar || item.name_en : item.name_en || item.name_ar
}

function docLabel(doc) {
  return documentLabel(doc, locale.value)
}

function docCondition(doc) {
  return documentCondition(doc, locale.value)
}

function chooseFiles(event) {
  const selected = Array.from(event.target.files ?? [])
  const invalid = selected.find((file) => {
    const extension = file.name.split('.').pop()?.toLowerCase()
    return !acceptedExtensions.includes(extension) || file.size > maxBytes
  })

  if (invalid) {
    error.value = t(!acceptedExtensions.includes(invalid.name.split('.').pop()?.toLowerCase())
      ? 'attachments.invalidType'
      : 'attachments.fileTooLarge')
    return
  }

  const next = [...files.value, ...selected]
  if (next.length > 10) {
    error.value = t('intake.tooManyFiles')
    return
  }

  files.value = next.map((file) => ({ file, label: '' }))
  error.value = ''
  event.target.value = ''
}

function removeFile(index) {
  files.value.splice(index, 1)
}

async function loadOptions() {
  loadingOptions.value = true
  error.value = ''
  try {
    const { data } = await api.get('/requests/intake-options')
    options.value = data.data ?? options.value
  } catch (requestError) {
    error.value = requestError.response?.data?.message ?? t('intake.loadFailed')
  } finally {
    loadingOptions.value = false
  }
}

async function submit() {
  submitting.value = true
  errors.value = {}
  error.value = ''
  const payload = new FormData()
  payload.append('title', form.value.title)
  payload.append('description', form.value.description)
  payload.append('department_id', form.value.department_id)
  payload.append('request_type_id', form.value.request_type_id)
  if (form.value.decision_grade !== '') payload.append('decision_grade', form.value.decision_grade)
  if (form.value.prior_relation !== '') payload.append('prior_relation', form.value.prior_relation)
  files.value.forEach(({ file, label }, index) => {
    payload.append(`attachments[${index}][file]`, file)
    if (label.trim()) payload.append(`attachments[${index}][label]`, label.trim())
  })

  try {
    const { data } = await api.post('/requests', payload)
    created.value = data.data
  } catch (requestError) {
    errors.value = requestError.response?.data?.errors ?? {}
    error.value = requestError.response?.data?.message ?? t('intake.submitFailed')
  } finally {
    submitting.value = false
  }
}

function startAnother() {
  form.value = blankForm()
  files.value = []
  errors.value = {}
  error.value = ''
  created.value = null
  duplicate.value = null
}

onMounted(loadOptions)
</script>

<template>
  <section class="intake">
    <div class="heading">
      <div>
        <h2>{{ t('intake.title') }}</h2>
        <p>{{ t('intake.subtitle') }}</p>
      </div>
    </div>

    <section v-if="created" class="card success" role="status">
      <h3>{{ t('intake.successTitle') }}</h3>
      <p>{{ t('intake.successBody') }}</p>
      <strong class="reference ltr">{{ created.intake_receipt_number }}</strong>
      <!-- Stage 70 — [D] Art. 15: handing a request to the direct manager is
           explicitly not a قيد, so the screen says so rather than letting the
           receipt read as the committee reference it is not. -->
      <p class="receipt-notice">{{ t("intake.receiptNotice") }}</p>
      <div class="actions">
        <button class="primary" type="button" @click="startAnother">{{ t('intake.createAnother') }}</button>
        <RouterLink class="ghost link-button" :to="{ name: 'requests' }">{{ t('intake.viewQueue') }}</RouterLink>
      </div>
    </section>

    <form v-else class="card form" @submit.prevent="submit">
      <p v-if="error" class="alert" role="alert">{{ error }}</p>
      <p v-if="loadingOptions" class="state">{{ t('common.loading') }}</p>

      <fieldset :disabled="isBusy">
        <legend>{{ t('intake.basicData') }}</legend>
        <div class="grid">
          <label class="wide">
            {{ t('intake.subject') }}
            <input v-model="form.title" type="text" maxlength="255" required />
            <small v-if="errors.title">{{ errors.title[0] }}</small>
          </label>
          <label>
            {{ t('requests.department') }}
            <select v-model="form.department_id" required>
              <option disabled value="">{{ t('intake.chooseDepartment') }}</option>
              <option v-for="department in options.departments" :key="department.id" :value="department.id">{{ name(department) }}</option>
            </select>
            <small v-if="errors.department_id">{{ errors.department_id[0] }}</small>
          </label>
          <label>
            {{ t('requests.type') }}
            <select v-model="form.request_type_id" required @change="checkDuplicates">
              <option disabled value="">{{ t('intake.chooseType') }}</option>
              <option v-for="type in options.types" :key="type.id" :value="type.id">{{ name(type) }}</option>
            </select>
            <small v-if="errors.request_type_id">{{ errors.request_type_id[0] }}</small>
          </label>
          <!-- Stage 83 — [D] Appendix 16. An open file on the same subject is
               shown before the form is filled: "لا تنشأ معاملة جديدة، بل تلحق
               المستندات بالمعاملة القائمة". -->
          <div v-if="duplicate?.open_prior" class="wide alert" role="alert">
            {{ t('lifecycle.duplicate.openPrior', { title: duplicate.open_prior.title }) }}
          </div>
          <label v-else-if="duplicate?.prior_requests?.length" class="wide">
            {{ t('lifecycle.duplicate.classify') }}
            <select v-model="form.prior_relation" required>
              <option disabled value="">{{ t('lifecycle.duplicate.choose') }}</option>
              <option
                v-for="relation in PRIOR_RELATIONS"
                :key="relation"
                :value="relation"
                :disabled="duplicate.relations.find((entry) => entry.code === relation)?.redirected"
              >
                {{ t(`lifecycle.duplicate.relations.${relation}`) }}
              </option>
            </select>
            <small>{{ t('lifecycle.duplicate.redirectedNote') }}</small>
          </label>
          <p v-else-if="duplicateChecking" class="wide state">{{ t('common.loading') }}</p>
          <label>
            {{ t('intake.decisionGrade') }}
            <input
              v-model="form.decision_grade"
              type="number"
              min="1"
              max="100"
              :required="selectedType?.decision_grade_threshold != null"
            />
            <small class="field-hint">
              {{ t('intake.decisionGradeHint', { threshold: selectedType?.decision_grade_threshold ?? '—' }) }}
            </small>
            <small v-if="errors.decision_grade">{{ errors.decision_grade[0] }}</small>
          </label>
          <label class="wide">
            {{ t('intake.description') }}
            <textarea v-model="form.description" rows="5" />
            <small v-if="errors.description">{{ errors.description[0] }}</small>
          </label>
        </div>
      </fieldset>

      <!--
        Stage 72 — [D] Appendix 57's document matrix for the chosen type, grouped
        by the appendix's own أساسية مشتركة / خاصة بالنوع split. Soft and
        informational: nothing here is validated on submit.
      -->
      <fieldset v-if="documentSections.length" class="checklist" :disabled="isBusy">
        <legend>{{ t('intake.requiredDocuments.title') }}</legend>
        <p class="hint">{{ t('intake.requiredDocuments.hint') }}</p>
        <div v-for="section in documentSections" :key="section.group" class="doc-group">
          <h3>{{ t(`intake.requiredDocuments.groups.${section.group}`) }}</h3>
          <ul>
            <li v-for="(doc, index) in section.items" :key="index">
              {{ docLabel(doc) }}
              <span v-if="docCondition(doc)" class="doc-condition">({{ docCondition(doc) }})</span>
            </li>
          </ul>
        </div>
      </fieldset>

      <fieldset :disabled="isBusy">
        <legend>{{ t('attachments.title') }}</legend>
        <p class="hint">{{ t('attachments.acceptedHint') }}</p>
        <input class="file-input" type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" @change="chooseFiles" />
        <small v-if="errors.attachments">{{ errors.attachments[0] }}</small>

        <div v-if="files.length" class="files">
          <article v-for="(attachment, index) in files" :key="`${attachment.file.name}-${index}`" class="file-row">
            <span class="file-name ltr">{{ attachment.file.name }}</span>
            <input v-model="attachment.label" type="text" :placeholder="t('attachments.label')" maxlength="255" />
            <button class="ghost" type="button" @click="removeFile(index)">{{ t('intake.removeFile') }}</button>
          </article>
        </div>
      </fieldset>

      <div class="actions">
        <button v-can="'request_intake.add'" class="primary" type="submit" :disabled="isBusy">{{ submitting ? t('intake.submitting') : t('intake.submit') }}</button>
      </div>
    </form>
  </section>
</template>

<style scoped>
.heading { margin-bottom: 1rem; }.heading h2 { margin: 0; color: var(--color-brand-text); font-size: 1.2rem; }.heading p { margin: .25rem 0 0; color: var(--color-muted); font-size: .88rem; }
.card { padding: 1.25rem; }.form { max-inline-size: 52rem; }.form fieldset { min-inline-size: 0; padding: 0; margin: 0 0 1.5rem; border: 0; }.form legend { margin-bottom: .85rem; color: var(--color-brand-text); font-weight: 700; }.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }.wide { grid-column: 1 / -1; }
label { display: grid; gap: .35rem; color: var(--color-black-700); font-size: .85rem; }input, select, textarea { min-inline-size: 0; padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); font: inherit; }textarea { resize: vertical; }small { color: var(--color-danger-fg); font-size: .78rem; }.field-hint, .hint, .state { color: var(--color-muted); font-size: .78rem; }.hint, .state { margin: 0 0 .75rem; }.file-input { max-inline-size: 100%; }
.checklist ul { display: grid; gap: .35rem; padding-inline-start: 1.2rem; margin: 0; color: var(--color-black-700); font-size: .85rem; }
.doc-group + .doc-group { margin-block-start: .85rem; }.doc-group h3 { margin: 0 0 .35rem; color: var(--color-black-700); font-size: .8rem; font-weight: 600; }.doc-condition { color: var(--color-black-500); font-size: .75rem; }
.files { display: grid; gap: .6rem; margin-top: .85rem; }.file-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(10rem, 1fr) auto; gap: .5rem; align-items: center; padding: .6rem; border: 1px solid var(--color-border); border-radius: var(--radius-lg); }.file-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .8rem; }.actions { display: flex; gap: .5rem; }.primary, .ghost { padding: .5rem .9rem; border-radius: var(--radius-lg); font-size: .85rem; cursor: pointer; }.primary { border: 0; color: var(--color-on-brand); background: var(--color-brand); }.ghost { border: 1px solid var(--color-border-hover); color: var(--color-black-700); background: var(--color-surface); }.link-button { text-decoration: none; }.primary:disabled, fieldset:disabled { cursor: not-allowed; opacity: .65; }.alert { padding: .65rem .8rem; margin: 0 0 1rem; border: 1px solid var(--color-danger-border); border-radius: var(--radius-lg); color: var(--color-danger-fg); background: var(--color-danger-bg); }.success { max-inline-size: 38rem; }.success h3 { margin: 0; color: var(--color-brand-text); }.success p { color: var(--color-black-700); }.reference { display: block; margin: 1rem 0; color: var(--color-primary); font-size: 1.15rem; }.receipt-notice { padding: .6rem .7rem; border: 1px solid var(--color-info-border); border-radius: var(--radius-lg); color: var(--color-info-fg); background: var(--color-info-bg); font-size: .8rem; }
@media (max-width: 640px) { .grid { grid-template-columns: 1fr; }.wide { grid-column: auto; }.file-row { grid-template-columns: 1fr; } }
</style>
