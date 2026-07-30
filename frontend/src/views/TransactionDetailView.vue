<script setup>
/** Stage 15 — the workflow workspace for a single transaction. */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import FileUpload from '../components/FileUpload.vue'
import TransactionNotes from '../components/TransactionNotes.vue'
import api from '../lib/api'

const route = useRoute()
const { t, locale } = useI18n()
const transaction = ref(null)
const loading = ref(false)
const error = ref('')
const actionError = ref('')
const comment = ref('')
const acting = ref(false)

const name = (item) => {
  if (!item) return t('common.none')
  return locale.value === 'ar' ? item.name_ar || item.name_en : item.name_en || item.name_ar
}
const dateTime = (value) => value
  ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
  : t('common.none')
const date = (value) => value
  ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium' }).format(new Date(value))
  : t('common.none')
const actionLabel = (action) => t(`workflow.actions.${action}`)
const canAct = computed(() => (transaction.value?.available_actions ?? []).length > 0)

function fileSize(bytes) {
  if (!Number.isFinite(bytes)) return t('common.none')
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 ** 2).toFixed(1)} MB`
}

async function load() {
  loading.value = true
  error.value = ''
  actionError.value = ''
  try {
    const { data } = await api.get(`/transactions/${route.params.id}`)
    transaction.value = data.data
  } catch (requestError) {
    error.value = requestError.response?.data?.message ?? t('transactionDetail.loadFailed')
  } finally {
    loading.value = false
  }
}

async function transition(action) {
  if (acting.value) return
  acting.value = true
  actionError.value = ''
  try {
    const { data } = await api.post(`/transactions/${transaction.value.id}/transition`, {
      action,
      comment: comment.value.trim() || null,
    })
    transaction.value = data.data
    comment.value = ''
  } catch (requestError) {
    actionError.value = requestError.response?.data?.errors?.action?.[0]
      ?? requestError.response?.data?.message
      ?? t('transactionDetail.actionFailed')
  } finally {
    acting.value = false
  }
}

watch(() => route.params.id, load)
onMounted(load)
</script>

<template>
  <section class="detail">
    <RouterLink class="back" :to="{ name: 'transactions' }">{{ t('transactionDetail.back') }}</RouterLink>

    <p v-if="loading" class="state">{{ t('common.loading') }}</p>
    <div v-else-if="error" class="alert" role="alert">
      {{ error }} <button class="ghost" type="button" @click="load">{{ t('common.retry') }}</button>
    </div>

    <template v-else-if="transaction">
      <header class="heading">
        <div>
          <p class="reference ltr">{{ transaction.reference_number || `#${transaction.id}` }}</p>
          <h2>{{ transaction.title }}</h2>
        </div>
        <span v-if="transaction.status" class="status" :style="{ '--status-color': transaction.status.color || 'var(--color-muted)' }">
          {{ name(transaction.status) }}
        </span>
      </header>

      <section class="card summary">
        <div>
          <span>{{ t('transactionDetail.currentStage') }}</span>
          <strong>{{ name(transaction.current_stage) }}</strong>
        </div>
        <div>
          <span>{{ t('transactions.department') }}</span>
          <strong>{{ name(transaction.department) }}</strong>
        </div>
        <div>
          <span>{{ t('transactions.type') }}</span>
          <strong>{{ name(transaction.transaction_type) }}</strong>
        </div>
        <div>
          <span>{{ t('transactionDetail.submittedAt') }}</span>
          <strong>{{ dateTime(transaction.submitted_at || transaction.created_at) }}</strong>
        </div>
        <div v-if="transaction.due_date">
          <span>{{ t('transactionDetail.dueDate') }}</span>
          <strong>{{ date(transaction.due_date) }}</strong>
        </div>
      </section>

      <section v-if="canAct" class="card action-panel">
        <h3>{{ t('transactionDetail.actions') }}</h3>
        <p>{{ t('transactionDetail.actionHint') }}</p>
        <label>
          {{ t('transactionDetail.comment') }}
          <textarea v-model="comment" rows="2" maxlength="5000" :disabled="acting" />
        </label>
        <p v-if="actionError" class="action-error" role="alert">{{ actionError }}</p>
        <div class="action-buttons">
          <button v-for="action in transaction.available_actions" :key="action" class="primary" type="button" :disabled="acting" @click="transition(action)">
            {{ acting ? t('transactionDetail.processing') : actionLabel(action) }}
          </button>
        </div>
      </section>

      <div class="columns">
        <div class="main-column">
          <section class="card description">
            <h3>{{ t('transactionDetail.description') }}</h3>
            <p>{{ transaction.description || t('transactionDetail.noDescription') }}</p>
          </section>

          <section class="card timeline">
            <h3>{{ t('transactionDetail.timeline') }}</h3>
            <p v-if="!transaction.timeline?.length" class="state">{{ t('transactionDetail.noTimeline') }}</p>
            <ol v-else>
              <li v-for="entry in transaction.timeline" :key="entry.id">
                <span class="dot" />
                <div>
                  <strong>{{ actionLabel(entry.action) }}</strong>
                  <p v-if="entry.to_stage">{{ entry.from_stage ? `${name(entry.from_stage)} → ${name(entry.to_stage)}` : name(entry.to_stage) }}</p>
                  <p v-if="entry.comment" class="entry-comment">{{ entry.comment }}</p>
                  <small>{{ entry.acted_by?.name || t('common.none') }} · {{ dateTime(entry.acted_at) }}</small>
                </div>
              </li>
            </ol>
          </section>
        </div>

        <aside class="side-column">
          <section class="card attachments">
            <h3>{{ t('attachments.title') }}</h3>
            <p v-if="!transaction.attachments?.length" class="state">{{ t('transactionDetail.noAttachments') }}</p>
            <ul v-else>
              <li v-for="attachment in transaction.attachments" :key="attachment.id">
                <strong class="file-name ltr">{{ attachment.original_name }}</strong>
                <small>{{ attachment.label || attachment.mime_type }} · {{ fileSize(attachment.size_bytes) }}</small>
              </li>
            </ul>
            <FileUpload v-can="'notes_attachments.add'" :transaction-id="transaction.id" @uploaded="load" />
          </section>

          <section class="card"><TransactionNotes :transaction-id="transaction.id" /></section>
        </aside>
      </div>
    </template>
  </section>
</template>

<style scoped>
.detail { max-inline-size: 82rem; }.back { display: inline-block; margin-bottom: .85rem; color: var(--color-nav); font-size: .85rem; text-decoration: none; }.back:hover { text-decoration: underline; }.heading { display: flex; align-items: start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }.heading h2 { margin: .15rem 0 0; color: var(--color-nav); font-size: clamp(1.25rem, 3vw, 1.7rem); }.reference { margin: 0; color: var(--color-muted); font-family: var(--font-mono); font-size: .8rem; }.status { display: inline-flex; align-items: center; gap: .4rem; flex: none; padding: .35rem .55rem; border-radius: var(--radius-full); color: var(--color-black-700); background: var(--color-surface-hover); font-size: .82rem; }.status::before { content: ''; inline-size: .55rem; block-size: .55rem; border-radius: 50%; background: var(--status-color); }.card { padding: 1.1rem; }.summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 1rem; margin-bottom: 1rem; }.summary div { display: grid; gap: .2rem; }.summary span { color: var(--color-muted); font-size: .76rem; }.summary strong { color: var(--color-black-700); font-size: .88rem; }.action-panel { margin-bottom: 1rem; }.action-panel h3, .description h3, .timeline h3, .attachments h3 { margin: 0 0 .45rem; color: var(--color-nav); font-size: 1rem; }.action-panel > p { margin: 0 0 .75rem; color: var(--color-muted); font-size: .83rem; }.action-panel label { display: grid; gap: .3rem; max-inline-size: 40rem; font-size: .85rem; }.action-panel textarea { padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); resize: vertical; font: inherit; }.action-buttons { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .75rem; }.primary { padding: .5rem .9rem; border: 0; border-radius: var(--radius-lg); color: #fff; background: var(--color-nav); cursor: pointer; }.primary:disabled { cursor: not-allowed; opacity: .6; }.action-error, .alert { color: #b91c1c; }.action-error { margin: .6rem 0 0; font-size: .84rem; }.alert { padding: .75rem; border: 1px solid #fecaca; border-radius: var(--radius-lg); background: #fef2f2; }.ghost { margin-inline-start: .5rem; padding: .35rem .55rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); cursor: pointer; }.columns { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(18rem, .85fr); gap: 1rem; align-items: start; }.main-column, .side-column { display: grid; gap: 1rem; }.description p { margin: 0; color: var(--color-black-700); line-height: 1.75; white-space: pre-wrap; }.timeline ol { display: grid; gap: 0; padding: 0; margin: .9rem 0 0; list-style: none; }.timeline li { position: relative; display: grid; grid-template-columns: 1.2rem minmax(0, 1fr); gap: .6rem; padding-bottom: 1rem; }.timeline li:not(:last-child)::before { content: ''; position: absolute; inset-inline-start: .45rem; inset-block-start: .85rem; inline-size: 1px; block-size: calc(100% - .25rem); background: var(--color-border); }.dot { position: relative; z-index: 1; inline-size: .9rem; block-size: .9rem; margin-top: .15rem; border: 3px solid var(--color-surface); border-radius: 50%; background: var(--color-primary); box-shadow: 0 0 0 1px var(--color-border-hover); }.timeline p { margin: .2rem 0; color: var(--color-black-700); font-size: .85rem; }.timeline small, .attachments small, .state { color: var(--color-muted); font-size: .78rem; }.entry-comment { white-space: pre-wrap; }.attachments ul { display: grid; gap: .65rem; padding: 0; margin: .85rem 0; list-style: none; }.attachments li { display: grid; gap: .15rem; padding-bottom: .65rem; border-bottom: 1px solid var(--color-border); }.file-name { overflow-wrap: anywhere; color: var(--color-black-700); font-size: .83rem; }@media (max-width: 720px) { .columns { grid-template-columns: 1fr; }.heading { flex-direction: column; }.summary { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
