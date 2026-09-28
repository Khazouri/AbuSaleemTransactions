<script setup>
/**
 * Decision wizard — sub-project 3. The one way to act on an appeal: read it,
 * pick what the server says is yours to do now, confirm it as a تأشيرة.
 * Hosted by AppealsView, since appeals have no page of their own.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import WizardShell from './WizardShell.vue'
import WizardSlip from './WizardSlip.vue'
import api, { firstError } from '../lib/api'
import { APPEAL_ACTS, COMPETENT_BODIES } from '../lib/appealActs'

const props = defineProps({
  appealId: { type: [Number, String], required: true },
  initial: { type: String, default: null },
})
const emit = defineEmits(['done', 'close'])
const { t, locale } = useI18n()

const appeal = ref(null)
const available = ref([])
const blocked = ref([])
const loadError = ref('')

const steps = ['review', 'choose', 'confirm']
const step = ref('review')
const selected = ref('')

// The jurisdiction test's five answers are the choice itself; every other
// act is one option.
const choices = computed(() => available.value.flatMap((item) => (item.action === 'jurisdiction_test'
  ? COMPETENT_BODIES.map((body) => ({
    key: `jurisdiction_test:${body}`,
    action: item.action,
    body,
    label: t(`appeals.jurisdiction.options.${body}`),
    meaning: body === 'committee' ? '' : t('appeals.jurisdiction.terminationWarning'),
  }))
  : [{ key: item.action, action: item.action, label: t(APPEAL_ACTS[item.action].labelKey), meaning: '' }])))
const choice = computed(() => choices.value.find((item) => item.key === selected.value) ?? null)
const spec = computed(() => (choice.value ? APPEAL_ACTS[choice.value.action] : null))
const destructive = computed(() => Boolean(choice.value && spec.value?.destructive?.(choice.value)))

const form = ref({})
watch(() => choice.value?.key, () => {
  form.value = spec.value ? spec.value.blank(appeal.value, choice.value) : {}
})
const notReady = computed(() => Boolean(spec.value) && !spec.value.ready(form.value, appeal.value))

const submitting = ref(false)
const error = ref('')
const reference = computed(() => appeal.value?.original_request?.reference_number || `#${props.appealId}`)
const date = (value) => (value ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium' }).format(new Date(value)) : '—')
const name = (item) => (locale.value === 'ar' ? item?.name_ar || item?.name_en : item?.name_en || item?.name_ar) ?? '—'

async function load() {
  try {
    const { data } = await api.get(`/appeals/${props.appealId}/acts`)
    appeal.value = data.data.appeal
    available.value = data.data.available
    blocked.value = data.data.blocked
  } catch (requestError) {
    loadError.value = firstError(requestError, t('requestDetail.loadFailed'))
  }
}

async function submit() {
  if (submitting.value || !choice.value || notReady.value) return
  submitting.value = true
  error.value = ''
  try {
    await api.request({
      method: spec.value.method,
      url: spec.value.url(appeal.value, form.value),
      data: spec.value.body(form.value, choice.value, appeal.value),
    })
    emit('done')
    emit('close')
  } catch (requestError) {
    error.value = firstError(requestError, t('requestDetail.actionFailed'))
  } finally {
    submitting.value = false
  }
}

// An inbox task names its act: open on its slip. The jurisdiction test and an
// act no longer offered land on Choose instead.
load().then(() => {
  if (!props.initial || props.initial === '1') return
  const match = props.initial === 'jurisdiction_test' ? null : choices.value.find((item) => item.action === props.initial)
  selected.value = match?.key ?? ''
  step.value = match ? 'confirm' : 'choose'
})
</script>

<template>
  <WizardShell
    v-model:step="step"
    :title="t('decisionWizard.appeal.title')"
    :steps="steps"
    :can-advance="step !== 'choose' || Boolean(choice)"
    :submit-label="t('decisionWizard.submit', { action: choice?.label ?? '' })"
    :submit-disabled="!choice || notReady"
    :submitting="submitting"
    :destructive="destructive"
    @submit="submit"
    @close="emit('close')"
  >
    <template #review>
      <p v-if="loadError" class="alert" role="alert">{{ loadError }}</p>
      <p v-else-if="!appeal" class="state">{{ t('common.loading') }}</p>
      <template v-else>
        <p class="lede">{{ t('decisionWizard.appeal.lede') }}</p>
        <dl class="facts">
          <div><dt>{{ t('appeals.columns.request') }}</dt><dd><span class="ltr">{{ appeal.original_request?.reference_number }}</span> {{ appeal.original_request?.title }}</dd></div>
          <div><dt>{{ t('decisionWizard.appeal.appellant') }}</dt><dd>{{ appeal.appellant?.name || '—' }}</dd></div>
          <div><dt>{{ t('appeals.columns.status') }}</dt><dd>{{ name(appeal.status) }}</dd></div>
          <div><dt>{{ t('appeals.create.knownAt') }}</dt><dd>{{ date(appeal.known_at) }}</dd></div>
          <div v-if="appeal.committee_decision"><dt>{{ t('appeals.execution.decidedOutcome') }}</dt><dd>{{ t(`decisions.outcome.${appeal.committee_decision.outcome}`) }}</dd></div>
        </dl>
        <h4>{{ t('appeals.create.appealReasons') }}</h4>
        <p class="description">{{ appeal.appeal_reasons }}</p>
        <h4>{{ t('appeals.create.finalRequest') }}</h4>
        <p class="description">{{ appeal.final_request }}</p>
        <p class="hint">{{ t('decisionWizard.appeal.more') }}</p>
      </template>
    </template>

    <template #choose>
      <p class="lede">{{ t('decisionWizard.appeal.chooseLede') }}</p>
      <fieldset class="options">
        <legend class="sr-only">{{ t('decisionWizard.steps.choose') }}</legend>
        <label v-for="item in choices" :key="item.key" class="option" :class="{ chosen: selected === item.key }">
          <input v-model="selected" type="radio" name="appeal-act" :value="item.key" />
          <span class="option-text">
            <strong>{{ item.label }}</strong>
            <span v-if="item.meaning">{{ item.meaning }}</span>
          </span>
        </label>
        <template v-if="blocked.length">
          <p class="group-label">{{ t('decisionWizard.choose.blocked') }}</p>
          <div v-for="item in blocked" :key="item.action" class="option blocked">
            <input type="radio" name="appeal-act" disabled :aria-label="t(APPEAL_ACTS[item.action].labelKey)" />
            <span class="option-text">
              <strong>{{ t(APPEAL_ACTS[item.action].labelKey) }}</strong>
              <span>{{ item.reason }}</span>
            </span>
          </div>
        </template>
      </fieldset>
      <p v-if="!choices.length && !blocked.length" class="state">{{ t('decisionWizard.appeal.nothing') }}</p>
    </template>

    <template #confirm>
      <component :is="spec.form" v-if="spec?.form" v-model="form" :appeal="appeal" />
      <WizardSlip kind="tashira" :reference="reference" :destructive="destructive">
        <p class="slip-action">{{ choice?.label }}</p>
      </WizardSlip>
      <p v-if="error" class="alert" role="alert">{{ error }}</p>
    </template>
  </WizardShell>
</template>

<style scoped>
.description { margin: 0; color: var(--color-black-700); white-space: pre-wrap; max-inline-size: 70ch; }
.ltr { direction: ltr; unicode-bidi: isolate; }
</style>
