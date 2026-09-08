<script setup>
// Stage 32 — the meetings-unit command dashboard: live KPIs, the committee
// board, and a lightweight next-meeting preview. Bars are plain CSS, same
// precedent as the Stage 24 general dashboard — no chart dependency for a
// handful of small breakdowns.
//
// Stage 81 replaced this screen's own six-bucket funnel with [D] Appendix
// 11's ten buckets, and added Appendix 10's early warnings — the appendix
// addresses those alerts to مقرر اللجنة, whose screen this is. Bucket names
// and warning labels come from the server, so switching locale re-fetches
// rather than re-formatting; see lib/performance.js.
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import api from '../lib/api'
import { bucketScopeClass, timelinessClass } from '../lib/performance'

const { t, locale } = useI18n()

const kpis = ref(null)
const board = ref([])
const warnings = ref({ summary: [], top: [] })
const nextMeeting = ref(null)
const loading = ref(false)
const loadError = ref('')

function number(value) {
  if (value === null || value === undefined) return t('common.none')
  return new Intl.NumberFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB').format(value)
}

function dateTime(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' })
    .format(new Date(value))
}

const name = (row) => {
  if (!row) return t('common.none')
  return locale.value === 'ar' ? row.name_ar || row.name_en : row.name_en || row.name_ar
}

const tiles = computed(() => {
  if (!kpis.value) return []
  const k = kpis.value
  return [
    { key: 'candidates', value: number(k.candidates), tone: 'neutral' },
    { key: 'upcomingMeetings', value: number(k.upcoming_meetings), tone: 'info' },
    { key: 'meetingsHeld', value: number(k.meetings_held), tone: 'neutral' },
    { key: 'pendingDecisions', value: number(k.pending_decisions), tone: 'info' },
    { key: 'overdueItems', value: number(k.overdue_committee_items), tone: k.overdue_committee_items > 0 ? 'bad' : 'neutral' },
    { key: 'decisionsThisMonth', value: number(k.decisions_this_month), tone: 'good' },
  ]
})

/**
 * The bar is scaled against the largest LIVE bucket only.
 *
 * Bucket 1 counts arrivals during the period and bucket 9 cross-cuts every
 * other bucket, so letting either set the scale would squash the buckets
 * that actually partition the pipeline — the appendix never claims all ten
 * are slices of one whole, and the bars must not imply it either.
 */
function bucketShare(row) {
  const live = board.value.filter((bucket) => bucket.scope === 'live')
  const max = Math.max(...live.map((bucket) => bucket.total), 1)
  return `${Math.min(100, Math.round((row.total / max) * 100))}%`
}

/** Ten zeroes say nothing; only the conditions actually firing are shown. */
const firingWarnings = computed(() => warnings.value.summary.filter((row) => row.total > 0))

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get('/meetings/dashboard', { params: { locale: locale.value } })
    kpis.value = data.data.kpis
    board.value = data.data.board ?? []
    warnings.value = data.data.early_warnings ?? { summary: [], top: [] }
    nextMeeting.value = data.data.next_meeting
  } catch (error) {
    loadError.value = error.response?.data?.message ?? t('meetingsUnit.dashboard.error')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="page">
    <h1>{{ t('meetingsUnit.dashboard.title') }}</h1>
    <p class="subtitle">{{ t('meetingsUnit.dashboard.subtitle') }}</p>

    <p v-if="loading" class="state">{{ t('common.loading') }}</p>
    <div v-else-if="loadError" class="alert" role="alert">
      {{ loadError }} <button class="ghost" type="button" @click="load">{{ t('common.retry') }}</button>
    </div>

    <template v-else>
      <div class="tiles">
        <article v-for="tile in tiles" :key="tile.key" class="tile" :class="tile.tone">
          <span class="tile-label">{{ t(`meetingsUnit.dashboard.kpis.${tile.key}`) }}</span>
          <strong class="tile-value">{{ tile.value }}</strong>
        </article>
      </div>

      <div class="panels">
        <!-- Stage 81 — [D] Appendix 11's ten buckets. The labels are the
             appendix's own, rendered server-side. -->
        <section class="card panel">
          <h3>{{ t('meetingsUnit.dashboard.board.title') }}</h3>
          <p class="source">{{ t('meetingsUnit.dashboard.board.source') }}</p>
          <ul class="bars">
            <li v-for="row in board" :key="row.key" :class="bucketScopeClass(row.scope)">
              <span class="bar-label">
                {{ row.label }}
                <!-- Bucket 1 is period-bounded and bucket 9 cross-cuts the
                     rest; both say so rather than reading as a slice. -->
                <em v-if="row.scope !== 'live'" class="scope-note">
                  {{ t(`meetingsUnit.dashboard.board.scope.${row.scope}`) }}
                </em>
                <em v-if="row.average_days !== undefined && row.average_days !== null" class="scope-note">
                  {{ t('meetingsUnit.dashboard.board.averageDays', { days: number(row.average_days) }) }}
                </em>
              </span>
              <span class="bar-track">
                <span class="bar-fill" :style="{ inlineSize: bucketShare(row) }" />
              </span>
              <span class="bar-value">{{ number(row.total) }}</span>
            </li>
          </ul>
        </section>

        <section class="card panel">
          <h3>{{ t('meetingsUnit.dashboard.nextMeeting.title') }}</h3>
          <p v-if="!nextMeeting" class="state">{{ t('meetingsUnit.dashboard.nextMeeting.none') }}</p>
          <div v-else class="next-meeting">
            <strong>{{ nextMeeting.title }}</strong>
            <span class="muted">{{ nextMeeting.committee ? name(nextMeeting.committee) : t('common.none') }}</span>
            <span class="muted">{{ dateTime(nextMeeting.scheduled_at) }}</span>
            <span v-if="nextMeeting.location" class="muted">{{ nextMeeting.location }}</span>
            <div class="counts">
              <span>{{ nextMeeting.agenda_items_count }} {{ t('meetingsUnit.dashboard.nextMeeting.items') }}</span>
              <span class="pill" :class="nextMeeting.readiness.ready ? 'good' : 'bad'">
                {{ nextMeeting.readiness.ready
                  ? t('meetingsUnit.dashboard.nextMeeting.ready')
                  : t('meetingsUnit.dashboard.nextMeeting.notReady', { count: nextMeeting.readiness.exceptions_count }) }}
              </span>
            </div>
            <div class="next-meeting-links">
              <RouterLink class="ghost" :to="{ name: 'meeting_details', params: { id: nextMeeting.id } }">
                {{ t('meetingsUnit.dashboard.nextMeeting.open') }}
              </RouterLink>
              <RouterLink class="ghost" :to="{ name: 'meeting_readiness', query: { meeting: nextMeeting.id } }">
                {{ t('meetingsUnit.dashboard.nextMeeting.checkReadiness') }}
              </RouterLink>
            </div>
          </div>
        </section>

        <!-- Stage 81 — [D] Appendix 10's early warnings. Every row names the
             party the file is waiting on, which the appendix requires
             outright: a delay report must say who owes the next action, not
             merely how many days have passed. -->
        <section class="card panel warnings">
          <h3>{{ t('meetingsUnit.dashboard.warnings.title') }}</h3>
          <p class="source">{{ t('meetingsUnit.dashboard.warnings.source') }}</p>
          <p v-if="firingWarnings.length === 0" class="state">{{ t('meetingsUnit.dashboard.warnings.none') }}</p>
          <template v-else>
            <ul class="warning-tally">
              <li v-for="row in firingWarnings" :key="row.key">
                <span>{{ row.label }}</span>
                <strong>{{ number(row.total) }}</strong>
              </li>
            </ul>
            <ul v-if="warnings.top.length" class="warning-files">
              <li v-for="row in warnings.top" :key="row.request_id">
                <RouterLink
                  class="reference"
                  :to="{ name: 'request_details', params: { id: row.request_id } }"
                >{{ row.reference_number ?? `#${row.request_id}` }}</RouterLink>
                <span class="muted">{{ row.responsible ?? t('common.none') }}</span>
                <span class="warning-count" :class="timelinessClass(row.timeliness_level)">
                  {{ row.warnings.length }}
                </span>
              </li>
            </ul>
          </template>
        </section>
      </div>
    </template>
  </section>
</template>

<style scoped>
.page { padding: 1.5rem; max-inline-size: 78rem; }
.page h1 { margin: 0 0 .3rem; color: var(--color-brand-text); font-size: clamp(1.25rem, 3vw, 1.7rem); }
.subtitle { margin: 0 0 1rem; color: var(--color-muted); font-size: .85rem; }

.state { color: var(--color-muted); font-size: .85rem; margin: 0; }
.alert { padding: .65rem .8rem; background: var(--color-danger-bg); color: var(--color-danger-fg); border: 1px solid var(--color-danger-border); border-radius: 8px; font-size: .875rem; margin: .5rem 0 0; }
.ghost {
  display: inline-block; padding: .4rem .65rem; border: 1px solid var(--color-border-hover); border-radius: 8px;
  background: var(--color-surface); color: var(--color-black-700); font-size: .85rem; cursor: pointer; text-decoration: none;
}
.ghost:hover { background: var(--color-surface-hover); }

.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: .85rem; margin-bottom: 1rem; }
.tile {
  display: flex; flex-direction: column; gap: .3rem; padding: 1rem 1.1rem;
  background: var(--color-surface); border: 1px solid var(--color-border);
  border-radius: 12px;
  border-inline-start: 4px solid var(--color-border-hover);
}
.tile.good { border-inline-start-color: var(--color-success-fg); }
.tile.info { border-inline-start-color: var(--color-info-fg); }
.tile.bad { border-inline-start-color: var(--color-danger-fg); }
.tile-label { color: var(--color-muted); font-size: .78rem; }
.tile-value { color: var(--color-brand-text); font-size: 1.6rem; line-height: 1.1; }

.card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 12px; padding: 1.1rem; }
.panels { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem; }
.panel h3 { margin: 0 0 1rem; font-size: 1rem; color: var(--color-black-800); }

.bars { list-style: none; margin: 0; padding: 0; display: grid; gap: .55rem; }
.bars li { display: grid; grid-template-columns: minmax(90px, 34%) 1fr auto; align-items: center; gap: .6rem; }
.bar-label { font-size: .8rem; color: var(--color-black-700); }
.bar-track { block-size: 9px; background: var(--color-surface-hover); border-radius: 999px; overflow: hidden; }
.bar-fill { display: block; block-size: 100%; border-radius: 999px; background: var(--color-brand); }
.bar-value { font-size: .8rem; color: var(--color-muted); font-variant-numeric: tabular-nums; }

.source { margin: -.6rem 0 .9rem; color: var(--color-muted); font-size: .74rem; }
.scope-note { display: block; color: var(--color-muted); font-size: .7rem; font-style: normal; }
.bars li.scope-cross .bar-fill { background: var(--color-danger-fg); }
.bars li.scope-period .bar-fill { background: var(--color-info-fg); }
.warnings { grid-column: 1 / -1; }
.warning-tally, .warning-files { list-style: none; margin: 0; padding: 0; display: grid; gap: .35rem; }
.warning-tally li { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; padding: .35rem .55rem; border-radius: 8px; background: var(--color-warning-bg); color: var(--color-warning-fg); font-size: .8rem; }
.warning-files { margin-top: .8rem; padding-top: .8rem; border-top: 1px solid var(--color-border); }
.warning-files li { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto; align-items: center; gap: .6rem; font-size: .8rem; }
.reference { font-family: var(--font-mono); font-size: .76rem; color: var(--color-brand-text); }
.warning-count { min-inline-size: 1.5rem; text-align: center; padding: .1rem .4rem; border-radius: 999px; background: var(--color-surface-hover); color: var(--color-muted); font-size: .74rem; }
.warning-count.level-red, .warning-count.level-critical { background: var(--color-danger-bg); color: var(--color-danger-fg); }
.warning-count.level-yellow { background: var(--color-warning-bg); color: var(--color-warning-fg); }
.next-meeting { display: grid; gap: .3rem; font-size: .88rem; }
.next-meeting strong { color: var(--color-brand-text); font-size: 1rem; }
.muted { color: var(--color-muted); font-size: .82rem; }
.counts { display: flex; align-items: center; gap: .6rem; margin: .4rem 0; color: var(--color-black-700); font-size: .82rem; }
.pill { display: inline-block; padding: .15rem .55rem; border-radius: 999px; font-size: .74rem; }
.pill.good { background: var(--color-success-bg); color: var(--color-success-fg); border: 1px solid var(--color-success-border); }
.pill.bad { background: var(--color-danger-bg); color: var(--color-danger-fg); border: 1px solid var(--color-danger-border); }
.next-meeting-links { display: flex; gap: .5rem; margin-top: .3rem; }
.next-meeting-links .ghost { justify-self: start; }
</style>
