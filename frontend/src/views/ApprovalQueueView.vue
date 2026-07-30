<script setup>
/** Stage 18 — shared work queue for each role-bound approval checkpoint. */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import api from '../lib/api'

const route = useRoute()
const { t, locale } = useI18n()
const transactions = ref([])
const comments = ref({})
const loading = ref(false)
const approvingId = ref(null)
const error = ref('')
const actionErrors = ref({})

const level = computed(() => route.meta.approvalLevel)
const permission = computed(() => `${route.meta.screenCode}.approve`)
const name = (item) => locale.value === 'ar'
  ? item?.name_ar || item?.name_en
  : item?.name_en || item?.name_ar
const date = (value) => value
  ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium' }).format(new Date(value))
  : t('common.none')

async function load() {
  loading.value = true
  error.value = ''
  actionErrors.value = {}
  try {
    const { data } = await api.get(`/approvals/${level.value}`)
    transactions.value = data.data ?? []
  } catch (requestError) {
    error.value = requestError.response?.data?.message ?? t('approvals.loadFailed')
  } finally {
    loading.value = false
  }
}

async function approve(transaction) {
  if (approvingId.value) return
  approvingId.value = transaction.id
  actionErrors.value[transaction.id] = ''
  try {
    await api.post(`/approvals/${level.value}/${transaction.id}`, {
      comment: comments.value[transaction.id]?.trim() || null,
    })
    transactions.value = transactions.value.filter((item) => item.id !== transaction.id)
    delete comments.value[transaction.id]
  } catch (requestError) {
    actionErrors.value[transaction.id] = requestError.response?.data?.errors?.transaction?.[0]
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
    <section v-else-if="!transactions.length" class="card empty">{{ t('approvals.empty') }}</section>

    <div v-else class="cards">
      <article v-for="transaction in transactions" :key="transaction.id" class="card transaction-card">
        <div class="transaction-heading">
          <div>
            <span class="reference ltr">{{ transaction.reference_number }}</span>
            <h3>{{ transaction.title }}</h3>
          </div>
          <span class="status">{{ name(transaction.status) }}</span>
        </div>

        <dl>
          <div><dt>{{ t('transactions.department') }}</dt><dd>{{ name(transaction.department) }}</dd></div>
          <div><dt>{{ t('transactions.type') }}</dt><dd>{{ name(transaction.transaction_type) }}</dd></div>
          <div><dt>{{ t('approvals.decisionGrade') }}</dt><dd>{{ transaction.decision_grade ?? t('common.none') }}</dd></div>
          <div><dt>{{ t('transactionDetail.dueDate') }}</dt><dd>{{ date(transaction.due_date) }}</dd></div>
        </dl>

        <p v-if="transaction.is_overdue" class="overdue">{{ t('approvals.overdue') }}</p>
        <label>
          {{ t('approvals.comment') }}
          <textarea v-model="comments[transaction.id]" rows="2" maxlength="5000" :disabled="approvingId === transaction.id" />
        </label>
        <p v-if="actionErrors[transaction.id]" class="action-error" role="alert">{{ actionErrors[transaction.id] }}</p>
        <div class="actions">
          <RouterLink class="ghost link" :to="{ name: 'transaction_details', params: { id: transaction.id } }">
            {{ t('transactions.viewDetails') }}
          </RouterLink>
          <button
            v-can="permission"
            class="primary"
            type="button"
            :disabled="approvingId !== null"
            @click="approve(transaction)"
          >
            {{ approvingId === transaction.id ? t('approvals.approving') : t('approvals.approve') }}
          </button>
        </div>
      </article>
    </div>
  </section>
</template>

<style scoped>
.approval-queue { max-inline-size: 78rem; }.heading { display: flex; align-items: start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }.heading h2 { margin: 0; color: var(--color-nav); font-size: 1.2rem; }.heading p { margin: .25rem 0 0; color: var(--color-muted); font-size: .86rem; }.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr)); gap: 1rem; }.card { padding: 1rem; }.transaction-heading { display: flex; align-items: start; justify-content: space-between; gap: .75rem; }.transaction-heading h3 { margin: .2rem 0 0; color: var(--color-nav); font-size: 1rem; }.reference { color: var(--color-muted); font-family: var(--font-mono); font-size: .76rem; }.status { padding: .25rem .5rem; border-radius: var(--radius-full); background: var(--color-surface-hover); font-size: .75rem; }.transaction-card dl { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .65rem; margin: 1rem 0; }.transaction-card dl div { display: grid; gap: .1rem; }.transaction-card dt { color: var(--color-muted); font-size: .72rem; }.transaction-card dd { margin: 0; color: var(--color-black-700); font-size: .82rem; }.transaction-card label { display: grid; gap: .3rem; color: var(--color-black-700); font-size: .82rem; }.transaction-card textarea { padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); resize: vertical; font: inherit; }.actions { display: flex; justify-content: flex-end; gap: .5rem; margin-top: .8rem; }.primary, .ghost { padding: .45rem .8rem; border-radius: var(--radius-lg); cursor: pointer; }.primary { border: 0; color: #fff; background: var(--color-nav); }.ghost { border: 1px solid var(--color-border-hover); color: var(--color-black-700); background: var(--color-surface); }.link { text-decoration: none; }.primary:disabled, .ghost:disabled { cursor: not-allowed; opacity: .6; }.state, .empty { color: var(--color-muted); }.alert, .action-error { color: #b91c1c; }.alert { padding: .75rem; border: 1px solid #fecaca; border-radius: var(--radius-lg); background: #fef2f2; }.action-error { margin: .5rem 0 0; font-size: .8rem; }.overdue { color: #92400e; font-size: .8rem; }.empty { text-align: center; }@media (max-width: 520px) { .cards { grid-template-columns: 1fr; }.transaction-card dl { grid-template-columns: 1fr; } }
</style>
