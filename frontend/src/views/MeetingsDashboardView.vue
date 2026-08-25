<script setup>
// Stage 32 — the meetings-unit command dashboard: live KPIs, the committee
// request funnel, and a lightweight next-meeting preview. Bars are plain CSS,
// same precedent as the Stage 24 general dashboard — no chart dependency for
// a handful of small breakdowns.
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import api from '../lib/api'

const { t, locale } = useI18n()

const kpis = ref(null)
const funnel = ref(null)
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

const FUNNEL_KEYS = [
  ['candidates', 'candidates'],
  ['onAgenda', 'on_agenda'],
  ['inDiscussion', 'in_discussion'],
  ['decided', 'decided'],
  ['closed', 'closed'],
]

const funnelRows = computed(() => {
  if (!funnel.value) return []
  return FUNNEL_KEYS.map(([labelKey, apiKey]) => ({ key: labelKey, total: funnel.value[apiKey] }))
})

function funnelShare(total) {
  if (!funnelRows.value.length) return '0%'
  const max = Math.max(...funnelRows.value.map((row) => row.total), 1)
  return `${Math.round((total / max) * 100)}%`
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get('/meetings/dashboard')
    kpis.value = data.data.kpis
    funnel.value = data.data.funnel
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
        <section class="card panel">
          <h3>{{ t('meetingsUnit.dashboard.funnel.title') }}</h3>
          <ul class="bars">
            <li v-for="row in funnelRows" :key="row.key">
              <span class="bar-label">{{ t(`meetingsUnit.dashboard.funnel.${row.key}`) }}</span>
              <span class="bar-track">
                <span class="bar-fill" :style="{ inlineSize: funnelShare(row.total) }" />
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
