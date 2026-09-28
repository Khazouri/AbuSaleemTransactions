<script setup>
/**
 * Decision wizard — sub-project 3. Filing an appeal: pick the request, state
 * the grounds, confirm it as a تأشيرة, then attach documents to the appeal
 * that now exists — an upload needs its id, so documents come last.
 * Eligibility (own request, a final result, no repeat without new facts) is
 * the endpoint's, answered as a 422 on Confirm.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import FileUpload from './FileUpload.vue'
import WizardShell from './WizardShell.vue'
import WizardSlip from './WizardSlip.vue'
import api, { firstError } from '../lib/api'

const emit = defineEmits(['filed', 'close'])
const { t } = useI18n()

const appealId = ref(null)
const steps = computed(() => (appealId.value ? ['documents'] : ['request', 'grounds', 'confirm']))
const step = ref('request')

const search = ref('')
const results = ref([])
const searching = ref(false)
const picked = ref(null)
let timer = null
watch(search, (value) => {
  clearTimeout(timer)
  if (!value.trim()) {
    results.value = []
    return
  }
  timer = setTimeout(async () => {
    searching.value = true
    try {
      const { data } = await api.get('/requests', { params: { search: value.trim(), per_page: 5 } })
      results.value = data.data ?? []
    } catch {
      results.value = []
    } finally {
      searching.value = false
    }
  }, 300)
})

const grounds = ref({
  known_at: '',
  original_decision_reference: '',
  original_decision_date: '',
  appeal_reasons: '',
  final_request: '',
  new_facts_declaration: '',
})
const groundsReady = computed(() => Boolean(grounds.value.known_at && grounds.value.appeal_reasons.trim() && grounds.value.final_request.trim()))
const canAdvance = computed(() => (step.value === 'request' ? Boolean(picked.value) : step.value !== 'grounds' || groundsReady.value))

const submitting = ref(false)
const error = ref('')

async function submit() {
  if (appealId.value) {
    emit('close')
    return
  }
  submitting.value = true
  error.value = ''
  try {
    const { data } = await api.post('/appeals', {
      original_request_id: picked.value.id,
      ...Object.fromEntries(Object.entries(grounds.value).map(([key, value]) => [key, value || null])),
    })
    appealId.value = data.data.id
    emit('filed')
  } catch (requestError) {
    error.value = firstError(requestError, t('appeals.create.failed'))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <WizardShell
    v-model:step="step"
    :title="t('appeals.create.heading')"
    :steps="steps"
    :can-advance="canAdvance"
    :submit-label="appealId ? t('appeals.create.finish') : t('appeals.create.submit')"
    :submit-disabled="!appealId && (!picked || !groundsReady)"
    :submitting="submitting"
    @submit="submit"
    @close="emit('close')"
  >
    <template #request>
      <p v-if="picked" class="lede">
        <span class="ltr">{{ picked.reference_number }}</span> {{ picked.title }}
        <button class="link" type="button" @click="picked = null">{{ t('appeals.create.change') }}</button>
      </p>
      <template v-else>
        <label class="field">
          {{ t('appeals.create.searchLabel') }}
          <input v-model="search" type="text" :placeholder="t('appeals.create.searchPlaceholder')" />
        </label>
        <p v-if="searching" class="state">{{ t('common.loading') }}</p>
        <p v-else-if="search.trim() && !results.length" class="state">{{ t('appeals.create.noResults') }}</p>
        <fieldset v-else class="options">
          <legend class="sr-only">{{ t('appeals.create.searchLabel') }}</legend>
          <label v-for="result in results" :key="result.id" class="option">
            <input type="radio" name="appeal-request" @change="picked = result" />
            <span class="option-text"><strong class="ltr">{{ result.reference_number }}</strong><span>{{ result.title }}</span></span>
          </label>
        </fieldset>
      </template>
    </template>

    <template #grounds>
      <div class="gate-form">
        <div class="field-grid">
          <label><span>{{ t('appeals.create.knownAt') }} *</span><input v-model="grounds.known_at" type="date" required /></label>
          <label>
            <span>{{ t('appeals.create.decisionReference') }}</span>
            <input v-model="grounds.original_decision_reference" type="text" :placeholder="t('appeals.create.decisionReferencePlaceholder')" />
          </label>
          <label><span>{{ t('appeals.create.decisionDate') }}</span><input v-model="grounds.original_decision_date" type="date" /></label>
        </div>
        <label><span>{{ t('appeals.create.appealReasons') }} *</span><textarea v-model="grounds.appeal_reasons" rows="3" required /></label>
        <label><span>{{ t('appeals.create.finalRequest') }} *</span><textarea v-model="grounds.final_request" rows="2" required /></label>
        <label>
          <span>{{ t('appeals.create.newFactsDeclaration') }}</span>
          <textarea v-model="grounds.new_facts_declaration" rows="2" :placeholder="t('appeals.create.newFactsDeclarationHint')" />
        </label>
      </div>
    </template>

    <template #confirm>
      <WizardSlip kind="tashira" :reference="picked?.reference_number ?? ''">
        <p class="slip-action">{{ t('appeals.create.heading') }}</p>
        <p class="slip-destination">{{ grounds.final_request }}</p>
      </WizardSlip>
      <p v-if="error" class="alert" role="alert">{{ error }}</p>
    </template>

    <template #documents>
      <p class="alert success">{{ t('appeals.create.success') }}</p>
      <FileUpload :upload-url="`/appeals/${appealId}/attachments`" :require-section="false" />
    </template>
  </WizardShell>
</template>

<style scoped>
.field { display: grid; gap: 0.3rem; }
.ltr { direction: ltr; unicode-bidi: isolate; }
</style>
