<script setup>
/**
 * Stage 89 — «متابعة طلباتي»: the employee's own view of the files they filed.
 *
 * Packaging, not new data. Every value here already existed on a payload the
 * person who filed the request could already fetch; what did not exist was a
 * place that says it in their terms. So this screen deliberately answers three
 * questions and no others — where is my file, who has it now, and when is this
 * step expected to finish — and leaves the committee's own machinery (control
 * gates, jurisdiction test, closure audit, approval returns) on the request
 * workspace, which is unchanged.
 *
 * One screen rather than a list plus a second detail route: tracking is one
 * place, and /requests/:id is still there for anyone who wants the full file.
 * The per-row panel fetches its registers lazily, so opening the screen costs
 * one list query no matter how many files the employee has.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import RequestStageRail from '../components/RequestStageRail.vue'
import TimelineDocuments from '../components/TimelineDocuments.vue'
import api from '../lib/api'

const { t, locale } = useI18n()

const requests = ref([])
const loading = ref(false)
const loadError = ref(null)
const page = ref({ current_page: 1, last_page: 1, total: 0 })
const search = ref('')
const scope = ref('open')

/** The row whose tracking panel is open, and that panel's own state. */
const openId = ref(null)
const tracked = ref(null)
const trackedLoading = ref(false)
const trackedError = ref(null)

const SCOPES = ['open', 'concluded', 'all']

const name = (item) => {
  if (!item) return t('common.none')
  return locale.value === 'ar' ? item.name_ar || item.name_en : item.name_en || item.name_ar
}
const date = (value) => value
  ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium' }).format(new Date(value))
  : t('common.none')
const dateTime = (value) => value
  ? new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
  : t('common.none')
const actionLabel = (action) => t(`workflow.actions.${action}`)
// Stage 79 — a notice stores both languages in its payload, so the register
// reads back the one the viewer is in rather than re-deriving the text.
const noticeText = (notice, field) => (locale.value === 'ar'
  ? notice[`${field}_ar`] || notice[`${field}_en`]
  : notice[`${field}_en`] || notice[`${field}_ar`]) ?? '—'
const timelineMovement = (entry) => entry.from_stage
  ? `${name(entry.from_stage)} ${locale.value === 'ar' ? '←' : '→'} ${name(entry.to_stage)}`
  : name(entry.to_stage)

/** The tracking number the employee actually holds — receipt before the قيد. */
const trackingNumber = (request) =>
  request.reference_number || request.intake_receipt_number || t('requests.noReference')

/**
 * Stage 89 — the same measurement the internal queue shows as a RAG dot, said
 * as a sentence instead. A concluded file has no next step, and a stage with no
 * sourced Appendix 37 duration has no expected date — both are stated rather
 * than filled in with something plausible.
 */
function expectedLine(request, concluded) {
  if (concluded) return { tone: 'done', text: t('tracking.expected.concluded') }
  const timeliness = request.stage_timeliness
  if (!timeliness?.expected_by) return { tone: 'unknown', text: t('tracking.expected.noTarget') }
  const expected = t('tracking.expected.by', { date: date(timeliness.expected_by) })
  // Late is stated plainly, without the internal escalation vocabulary: the
  // employee is owed the date and whether it has passed, not a rung number.
  return timeliness.level === 'red' || timeliness.level === 'critical'
    ? { tone: 'late', text: `${expected} — ${t('tracking.expected.overdue')}` }
    : { tone: 'ontime', text: expected }
}

const rows = computed(() => requests.value.map((request) => ({
  request,
  expected: expectedLine(request, Boolean(request.is_concluded)),
})))

function queryFor(requestedPage) {
  const query = { page: requestedPage, scope: scope.value }
  if (search.value.trim() !== '') query.search = search.value.trim()
  return query
}

async function load(requestedPage = 1) {
  loading.value = true
  loadError.value = null
  try {
    const { data } = await api.get('/my-requests', { params: queryFor(requestedPage) })
    requests.value = data.data ?? []
    page.value = data.meta ?? page.value
  } catch (error) {
    loadError.value = error
  } finally {
    loading.value = false
  }
}

async function toggle(request) {
  if (openId.value === request.id) {
    openId.value = null
    tracked.value = null
    return
  }

  openId.value = request.id
  tracked.value = null
  trackedError.value = null
  trackedLoading.value = true
  try {
    const { data } = await api.get(`/my-requests/${request.id}`)
    tracked.value = data.data ?? null
  } catch (error) {
    trackedError.value = error
  } finally {
    trackedLoading.value = false
  }
}

function applySearch() { load(1) }
function setScope(value) {
  if (scope.value === value) return
  scope.value = value
  load(1)
}

// A row that is no longer in the list must not leave its panel open behind it.
watch(requests, () => {
  if (openId.value !== null && !requests.value.some((request) => request.id === openId.value)) {
    openId.value = null
    tracked.value = null
  }
})

onMounted(() => load())
</script>

<template>
  <section class="page tracking">
    <div class="heading">
      <div>
        <h2>{{ t('tracking.title') }}</h2>
        <p class="subtitle intro">{{ t('tracking.intro') }}</p>
      </div>
      <RouterLink v-can="'request_intake.add'" class="primary" :to="{ name: 'request_intake' }">{{ t('intake.open') }}</RouterLink>
    </div>

    <form class="card card-flat card-pad controls" @submit.prevent="applySearch">
      <label class="search">
        {{ t('tracking.search') }}
        <input v-model="search" type="search" :placeholder="t('tracking.searchPlaceholder')" />
      </label>
      <div class="scopes">
        <button
          v-for="value in SCOPES"
          :key="value"
          type="button"
          class="scope"
          :class="{ active: scope === value }"
          :aria-pressed="scope === value"
          @click="setScope(value)"
        >{{ t(`tracking.scopes.${value}`) }}</button>
      </div>
      <button class="primary" type="submit" :disabled="loading">{{ t('tracking.apply') }}</button>
    </form>

    <p v-if="loadError" class="alert">
      {{ t('nav.error') }}
      <button class="ghost" type="button" @click="load(page.current_page)">{{ t('common.retry') }}</button>
    </p>

    <div class="card card-flat card-pad list">
      <p v-if="loading" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="!loadError && requests.length === 0" class="state">{{ t('tracking.empty') }}</p>
      <ul v-else-if="!loadError" class="files">
        <li v-for="row in rows" :key="row.request.id" class="card card-flat file">
          <div class="file-head">
            <div class="identity">
              <span class="reference ltr">{{ trackingNumber(row.request) }}</span>
              <strong>{{ row.request.title }}</strong>
              <small>{{ name(row.request.request_type) }} · {{ t('requests.createdAt') }} {{ date(row.request.created_at) }}</small>
            </div>
            <div class="state-block">
              <span v-if="row.request.status" class="status" :style="{ '--status-color': row.request.status.color || 'var(--color-muted)' }">
                {{ name(row.request.status) }}
              </span>
              <!-- Stage 93 — the system's own twelve `workflow_stages` rows
                   are the reconciled denominator (see RequestResource's own
                   comment); every request screen states this same "N of
                   TOTAL", tracking included. -->
              <small class="stage">{{ t('tracking.currentStep') }}: {{ name(row.request.current_stage) }}</small>
              <RequestStageRail
                v-if="row.request.stage_progress"
                variant="compact"
                :stage-progress="row.request.stage_progress"
                :stage-timeliness="row.request.stage_timeliness"
              />
            </div>
          </div>

          <dl class="answers">
            <div>
              <dt>{{ t('lifecycle.responsibility.label') }}</dt>
              <dd>{{ row.request.responsibility?.responsible?.[locale === 'ar' ? 'ar' : 'en'] || t('common.none') }}</dd>
            </div>
            <div>
              <dt>{{ t('lifecycle.responsibility.nextAction') }}</dt>
              <dd>{{ row.request.responsibility?.next_action?.[locale === 'ar' ? 'ar' : 'en'] || t('common.none') }}</dd>
            </div>
            <div>
              <dt>{{ t('tracking.expected.label') }}</dt>
              <dd :class="`expected ${row.expected.tone}`">{{ row.expected.text }}</dd>
            </div>
          </dl>

          <div class="file-actions">
            <button class="ghost" type="button" @click="toggle(row.request)">
              {{ openId === row.request.id ? t('tracking.hideDetail') : t('tracking.showDetail') }}
            </button>
            <RouterLink class="ghost" :to="{ name: 'request_details', params: { id: row.request.id } }">
              {{ t('requests.viewDetails') }}
            </RouterLink>
          </div>

          <div v-if="openId === row.request.id" class="panel">
            <p v-if="trackedLoading" class="state">{{ t('common.loading') }}</p>
            <p v-else-if="trackedError" class="alert">{{ t('nav.error') }}</p>
            <template v-else-if="tracked">
              <p v-if="!tracked.documents_complete" class="alert warning">{{ t('tracking.documentsIncomplete') }}</p>

              <!-- [D] Art. 100's السجل الزمني, the same six columns the
                   workspace renders. -->
              <section class="block">
                <h4>{{ t('requestDetail.timeline') }}</h4>
                <p v-if="!tracked.timeline?.length" class="state">{{ t('requestDetail.noTimeline') }}</p>
                <ol v-else class="timeline">
                  <li v-for="entry in tracked.timeline" :key="entry.id">
                    <span class="dot" />
                    <div>
                      <strong>{{ actionLabel(entry.action) }}</strong>
                      <p v-if="entry.to_stage">{{ timelineMovement(entry) }}</p>
                      <p v-if="entry.comment" class="entry-comment">{{ entry.comment }}</p>
                      <small>
                        <template v-if="entry.body">{{ name(entry.body) }} · </template>{{ dateTime(entry.acted_at) }}
                      </small>
                      <TimelineDocuments v-if="entry.documents?.length" :documents="entry.documents" />
                    </div>
                  </li>
                </ol>
              </section>

              <!-- [D] Art. 101's register: what I was actually told, and when. -->
              <section class="block">
                <h4>{{ t('employeeNotices.title') }}</h4>
                <p class="hint">{{ t('employeeNotices.intro') }}</p>
                <p v-if="!tracked.employee_notices?.length" class="state">{{ t('tracking.noNotices') }}</p>
                <ul v-else class="notices">
                  <li v-for="notice in tracked.employee_notices" :key="notice.id">
                    <div class="notice-head">
                      <strong>{{ noticeText(notice, 'title') }}</strong>
                      <span class="notice-date">{{ dateTime(notice.sent_at) }}</span>
                    </div>
                    <p>{{ noticeText(notice, 'body') }}</p>
                  </li>
                </ul>
              </section>

              <!-- [D] Appendix 71 — where the time actually went, which is the
                   appendix's own stated purpose. -->
              <section v-if="tracked.time_card?.length" class="block">
                <h4>{{ t('requestDetail.timeCard.title') }}</h4>
                <p class="hint">{{ t('requestDetail.timeCard.source') }}</p>
                <ul class="segments">
                  <li v-for="segment in tracked.time_card" :key="segment.key">
                    <span class="segment-label">{{ segment.label }}</span>
                    <span class="segment-days" :class="{ pending: segment.days === null }">
                      {{ segment.days === null
                        ? t('requestDetail.timeCard.pending')
                        : t('requestDetail.timeCard.days', { days: segment.days }) }}
                    </span>
                  </li>
                </ul>
              </section>
            </template>
          </div>
        </li>
      </ul>

      <div v-if="!loading && !loadError && page.last_page > 1" class="pager">
        <button class="ghost" type="button" :disabled="page.current_page <= 1" @click="load(page.current_page - 1)">{{ t('requests.previous') }}</button>
        <span>{{ t('requests.page', { current: page.current_page, last: page.last_page }) }}</span>
        <button class="ghost" type="button" :disabled="page.current_page >= page.last_page" @click="load(page.current_page + 1)">{{ t('requests.next') }}</button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.controls, .list { margin-bottom: var(--space-4); }
.controls { display: flex; flex-wrap: wrap; align-items: end; gap: var(--space-3); }
.search { display: grid; gap: .3rem; flex: 1 1 18rem; font-size: var(--text-sm); color: var(--color-black-700); }
.search input { padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); color: var(--color-foreground); font: inherit; }
.scopes { display: flex; gap: .3rem; }
.scope { padding: .5rem .8rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); color: var(--color-black-700); background: var(--color-surface); cursor: pointer; font: inherit; font-size: var(--text-sm); }
.scope.active { color: var(--color-on-brand); background: var(--color-brand); border-color: var(--color-brand); }
.state, .hint { color: var(--color-muted); font-size: var(--text-sm); }
.files { display: grid; gap: var(--space-4); padding: 0; margin: 0; list-style: none; }
.file { padding: var(--space-4); }
.file-head { display: flex; flex-wrap: wrap; align-items: start; justify-content: space-between; gap: var(--space-3); }
.identity { display: grid; gap: .2rem; }
.identity strong { color: var(--color-black-700); font-size: var(--text-lg); }
.identity small, .stage { color: var(--color-muted); font-size: var(--text-xs); }
.reference { color: var(--color-muted); font-family: var(--font-mono); font-size: var(--text-xs); }
.state-block { display: grid; gap: .25rem; justify-items: end; }
.answers { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: .75rem; margin: .85rem 0 0; }
.answers div { display: grid; gap: .2rem; }
.answers dt { color: var(--color-muted); font-size: .74rem; }
.answers dd { margin: 0; color: var(--color-black-700); font-size: .85rem; }
.expected.ontime { color: var(--color-success-fg); }
.expected.late { color: var(--color-danger-fg); font-weight: 600; }
.expected.unknown, .expected.done { color: var(--color-muted); }
.file-actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .85rem; }
.panel { padding-top: .9rem; margin-top: .9rem; border-top: 1px solid var(--color-border); display: grid; gap: 1rem; }
.block h4 { margin: 0 0 .35rem; color: var(--color-brand-text); font-size: .92rem; }
.timeline { display: grid; gap: 0; padding: 0; margin: .6rem 0 0; list-style: none; }
.timeline > li { position: relative; display: grid; grid-template-columns: 1.1rem minmax(0, 1fr); gap: .6rem; padding-bottom: .9rem; }
.timeline > li:not(:last-child)::before { content: ''; position: absolute; inset-inline-start: .4rem; inset-block-start: .8rem; inline-size: 1px; block-size: calc(100% - .25rem); background: var(--color-border); }
.dot { position: relative; z-index: 1; inline-size: .8rem; block-size: .8rem; margin-top: .2rem; border: 3px solid var(--color-surface); border-radius: 50%; background: var(--color-primary); box-shadow: 0 0 0 1px var(--color-border-hover); }
.timeline strong { color: var(--color-black-700); font-size: .85rem; }
.timeline p { margin: .15rem 0; color: var(--color-black-700); font-size: .82rem; }
.timeline small { color: var(--color-muted); font-size: .74rem; }
.entry-comment { white-space: pre-wrap; }
.notices { display: grid; gap: .7rem; padding: 0; margin: .6rem 0 0; list-style: none; }
.notices li { padding-bottom: .6rem; border-bottom: 1px solid var(--color-border); }
.notice-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: .5rem; }
.notice-head strong { color: var(--color-black-700); font-size: .85rem; }
.notice-date { color: var(--color-muted); font-size: .74rem; }
.notices p { margin: .25rem 0 0; color: var(--color-black-700); font-size: .82rem; line-height: 1.7; }
.segments { display: grid; gap: .3rem; padding: 0; margin: .6rem 0 0; list-style: none; }
.segments li { display: flex; justify-content: space-between; gap: .75rem; font-size: .8rem; }
.segment-label { color: var(--color-black-700); }
.segment-days { color: var(--color-black-700); font-variant-numeric: tabular-nums; }
.segment-days.pending { color: var(--color-muted); }
.pager { display: flex; align-items: center; justify-content: center; gap: .75rem; margin-top: 1rem; color: var(--color-muted); font-size: .82rem; }
@media (max-width: 640px) {
  .heading { flex-direction: column; }
  .state-block { justify-items: start; }
}
</style>
