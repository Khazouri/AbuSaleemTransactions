<script setup>
/** Stage 18 — shared work queue for each role-bound approval checkpoint. */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import SignaturePad from '../components/SignaturePad.vue'
import api from '../lib/api'

const route = useRoute()
const { t, locale } = useI18n()
const requests = ref([])
const comments = ref({})
const loading = ref(false)
const approvingId = ref(null)
const error = ref('')
const actionErrors = ref({})
const signatureReady = ref({})
const signaturePads = new Map()

const level = computed(() => route.meta.approvalLevel)
const permission = computed(() => `${route.meta.screenCode}.approve`)
const name = (item) => locale.value === 'ar'
  ? item?.name_ar || item?.name_en
  : item?.name_en || item?.name_ar
const date = (value) => value
  ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium' }).format(new Date(value))
  : t('common.none')

function setSignaturePad(id, instance) {
  if (instance) signaturePads.set(id, instance)
  else signaturePads.delete(id)
}

async function load() {
  loading.value = true
  error.value = ''
  actionErrors.value = {}
  try {
    const { data } = await api.get(`/approvals/${level.value}`)
    requests.value = data.data ?? []
  } catch (requestError) {
    error.value = requestError.response?.data?.message ?? t('approvals.loadFailed')
  } finally {
    loading.value = false
  }
}

async function approve(request) {
  if (approvingId.value) return
  const signature = await signaturePads.get(request.id)?.toFile()
  if (!signature) {
    actionErrors.value[request.id] = t('signature.required')
    return
  }

  approvingId.value = request.id
  actionErrors.value[request.id] = ''
  try {
    const form = new FormData()
    const approvalComment = comments.value[request.id]?.trim()
    if (approvalComment) form.append('comment', approvalComment)
    form.append('signature', signature)
    await api.post(`/approvals/${level.value}/${request.id}`, form)
    requests.value = requests.value.filter((item) => item.id !== request.id)
    delete comments.value[request.id]
    delete signatureReady.value[request.id]
  } catch (requestError) {
    actionErrors.value[request.id] = requestError.response?.data?.errors?.request?.[0]
      ?? requestError.response?.data?.errors?.signature?.[0]
      ?? requestError.response?.data?.message
      ?? t('approvals.actionFailed')
  } finally {
    approvingId.value = null
  }
}

watch(level, load)
onMounted(load)
</script>

<template>
  <section class="approval-queue">
    <header class="heading">
      <div>
        <h2>{{ t(`approvals.levels.${level}`) }}</h2>
        <p>{{ t('approvals.subtitle') }}</p>
      </div>
      <button class="ghost" type="button" :disabled="loading" @click="load">{{ t('common.retry') }}</button>
    </header>

    <p v-if="loading" class="state">{{ t('common.loading') }}</p>
    <p v-else-if="error" class="alert" role="alert">{{ error }}</p>
    <section v-else-if="!requests.length" class="card empty">{{ t('approvals.empty') }}</section>

    <div v-else class="cards">
      <article v-for="request in requests" :key="request.id" class="card request-card">
        <div class="request-heading">
          <div>
            <span class="reference ltr">{{ request.reference_number }}</span>
            <h3>{{ request.title }}</h3>
          </div>
          <span class="status">{{ name(request.status) }}</span>
        </div>

        <dl>
          <div><dt>{{ t('requests.department') }}</dt><dd>{{ name(request.department) }}</dd></div>
          <div><dt>{{ t('requests.type') }}</dt><dd>{{ name(request.request_type) }}</dd></div>
          <div><dt>{{ t('approvals.decisionGrade') }}</dt><dd>{{ request.decision_grade ?? t('common.none') }}</dd></div>
          <div><dt>{{ t('requestDetail.dueDate') }}</dt><dd>{{ date(request.due_date) }}</dd></div>
        </dl>

        <p v-if="request.is_overdue" class="overdue">{{ t('approvals.overdue') }}</p>
        <label>
          {{ t('approvals.comment') }}
          <textarea v-model="comments[request.id]" rows="2" maxlength="5000" :disabled="approvingId === request.id" />
        </label>
        <SignaturePad
          :ref="(instance) => setSignaturePad(request.id, instance)"
          :disabled="approvingId === request.id"
          @change="signatureReady[request.id] = $event"
        />
        <p v-if="actionErrors[request.id]" class="action-error" role="alert">{{ actionErrors[request.id] }}</p>
        <div class="actions">
          <RouterLink class="ghost link" :to="{ name: 'request_details', params: { id: request.id } }">
            {{ t('requests.viewDetails') }}
          </RouterLink>
          <button
            v-can="permission"
            class="primary"
            type="button"
            :disabled="approvingId !== null || !signatureReady[request.id]"
            @click="approve(request)"
          >
            {{ approvingId === request.id ? t('approvals.approving') : t('approvals.approve') }}
          </button>
        </div>
      </article>
    </div>
  </section>
</template>

<style scoped>
.approval-queue { max-inline-size: 78rem; }.heading { display: flex; align-items: start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }.heading h2 { margin: 0; color: var(--color-brand-text); font-size: 1.2rem; }.heading p { margin: .25rem 0 0; color: var(--color-muted); font-size: .86rem; }.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr)); gap: 1rem; }.card { padding: 1rem; }.request-heading { display: flex; align-items: start; justify-content: space-between; gap: .75rem; }.request-heading h3 { margin: .2rem 0 0; color: var(--color-brand-text); font-size: 1rem; }.reference { color: var(--color-muted); font-family: var(--font-mono); font-size: .76rem; }.status { padding: .25rem .5rem; border-radius: var(--radius-full); background: var(--color-surface-hover); font-size: .75rem; }.request-card dl { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .65rem; margin: 1rem 0; }.request-card dl div { display: grid; gap: .1rem; }.request-card dt { color: var(--color-muted); font-size: .72rem; }.request-card dd { margin: 0; color: var(--color-black-700); font-size: .82rem; }.request-card label { display: grid; gap: .3rem; color: var(--color-black-700); font-size: .82rem; }.request-card textarea { padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); resize: vertical; font: inherit; }.actions { display: flex; justify-content: flex-end; gap: .5rem; margin-top: .8rem; }.primary, .ghost { padding: .45rem .8rem; border-radius: var(--radius-lg); cursor: pointer; }.primary { border: 0; color: var(--color-on-brand); background: var(--color-brand); }.ghost { border: 1px solid var(--color-border-hover); color: var(--color-black-700); background: var(--color-surface); }.link { text-decoration: none; }.primary:disabled, .ghost:disabled { cursor: not-allowed; opacity: .6; }.state, .empty { color: var(--color-muted); }.alert, .action-error { color: var(--color-danger-fg); }.alert { padding: .75rem; border: 1px solid var(--color-danger-border); border-radius: var(--radius-lg); background: var(--color-danger-bg); }.action-error { margin: .5rem 0 0; font-size: .8rem; }.overdue { color: var(--color-warning-fg); font-size: .8rem; }.empty { text-align: center; }@media (max-width: 520px) { .cards { grid-template-columns: 1fr; }.request-card dl { grid-template-columns: 1fr; } }
</style>
