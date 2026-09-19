<script setup>
/**
 * Dashboard (لوحة التحكم) — Stage 24.
 *
 * Live KPIs from /api/dashboard, which reads the same service the reports
 * screen and its exports read, so a tile here and a spreadsheet sent upstairs
 * always agree. The bars are plain CSS on purpose: a charting library would be
 * the SPA's heaviest dependency by far, for four small breakdowns.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import { useAuthStore } from '../stores/auth'

const { t, locale } = useI18n()
const auth = useAuthStore()

const kpis = ref(null)
const breakdowns = ref({ by_status: [], by_stage: [], by_department: [], by_month: [] })
const loading = ref(false)
const loadError = ref(null)

/** Prefers the active language's name, falling back to the other one. */
function localName(row) {
  if (!row) return t('common.none')
  return locale.value === 'ar'
    ? row.name_ar || row.name_en
    : row.name_en || row.name_ar
}

/** Numbers read right to left in Arabic too, so let Intl place the separators. */
function number(value) {
  if (value === null || value === undefined) return t('common.none')
  return new Intl.NumberFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB').format(value)
}

/** Short month label for the trend axis (2026-08 → أغسطس / Aug). */
function monthLabel(month) {
  const [year, monthNumber] = month.split('-')
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-LY' : 'en-GB', { month: 'short' })
    .format(new Date(Number(year), Number(monthNumber) - 1, 1))
}

/**
 * The six headline tiles. Built as data rather than as markup so the grid stays
 * one v-for and adding a KPI is a single entry.
 */
const tiles = computed(() => {
  if (!kpis.value) return []
  const k = kpis.value

  return [
    { key: 'total', value: number(k.total), tone: 'neutral' },
    { key: 'pending', value: number(k.pending), tone: 'info' },
    { key: 'completed', value: number(k.completed), tone: 'good' },
    { key: 'completionRate', value: `${k.completion_rate}%`, tone: 'good' },
    { key: 'overdue', value: number(k.overdue), tone: k.overdue > 0 ? 'bad' : 'neutral' },
    {
      key: 'cycleTime',
      value: k.average_cycle_days === null ? t('common.none') : number(k.average_cycle_days),
      tone: 'neutral',
    },
  ]
})

/** Statuses nothing is sitting in would just be a column of zeroes. */
const statusRows = computed(() => breakdowns.value.by_status.filter((row) => row.total > 0))

/** Bars are drawn relative to the busiest row, not to the grand total. */
function share(rows, total) {
  const max = Math.max(...rows.map((row) => row.total), 1)
  return `${Math.round((total / max) * 100)}%`
}

const monthMax = computed(
  () => Math.max(...breakdowns.value.by_month.flatMap((m) => [m.created, m.completed]), 1),
)

function monthHeight(value) {
  // A non-zero month always shows something, so an empty bar unambiguously
  // means zero rather than "too small to see".
  return value === 0 ? '2px' : `${Math.max(Math.round((value / monthMax.value) * 100), 6)}%`
}

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const { data } = await api.get('/dashboard')
    kpis.value = data.data.kpis
    breakdowns.value = data.data.breakdowns
  } catch (error) {
    loadError.value = error
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="dashboard">
    <div class="heading">
      <div>
        <h2>{{ t('dashboard.title') }}</h2>
        <p class="subtitle">{{ t('auth.welcome') }}، {{ auth.user?.name }}</p>
      </div>
      <RouterLink v-if="auth.can('reports', 'view')" class="ghost" :to="{ name: 'reports' }">
        {{ t('dashboard.openReports') }}
      </RouterLink>
    </div>

    <p v-if="loadError" class="alert">
      {{ t('nav.error') }}
      <button class="ghost" type="button" @click="load">{{ t('common.retry') }}</button>
    </p>

    <p v-if="loading" class="state">{{ t('common.loading') }}</p>

    <template v-else-if="!loadError && kpis">
      <div class="tiles">
        <article v-for="tile in tiles" :key="tile.key" class="tile" :class="tile.tone">
          <span class="tile-label">{{ t(`dashboard.tiles.${tile.key}`) }}</span>
          <strong class="tile-value">{{ tile.value }}</strong>
          <span v-if="tile.key === 'cycleTime'" class="tile-unit">{{ t('dashboard.days') }}</span>
        </article>
      </div>

      <div class="panels">
        <section class="card panel">
          <h3>{{ t('dashboard.byStatus') }}</h3>
          <p v-if="statusRows.length === 0" class="state">{{ t('dashboard.empty') }}</p>
          <ul v-else class="bars">
            <li v-for="row in statusRows" :key="row.code">
              <span class="bar-label">{{ localName(row) }}</span>
              <span class="bar-track">
                <span
                  class="bar-fill"
                  :style="{ inlineSize: share(statusRows, row.total), background: row.color }"
                />
              </span>
              <span class="bar-value">{{ number(row.total) }}</span>
            </li>
          </ul>
        </section>

        <section class="card panel">
          <!-- Stage 93 — the total names itself once here, on the panel
               rather than per bar: this is a distribution over every
               request, not one request's own progress, so "of 12" repeated
               on each row would be noise. RequestDetailView/RequestsView/
               RequestTrackingView/ReportsView say it per row instead,
               because there it IS one request's own position. -->
          <h3>{{ t('dashboard.byStage', { total: kpis.total_stages }) }}</h3>
          <p v-if="breakdowns.by_stage.length === 0" class="state">{{ t('dashboard.empty') }}</p>
          <ul v-else class="bars">
            <li v-for="row in breakdowns.by_stage" :key="row.code">
              <span class="bar-label">{{ localName(row) }}</span>
              <span class="bar-track">
                <span class="bar-fill nav" :style="{ inlineSize: share(breakdowns.by_stage, row.total) }" />
              </span>
              <span class="bar-value">{{ number(row.total) }}</span>
            </li>
          </ul>
        </section>

        <section class="card panel">
          <h3>{{ t('dashboard.byDepartment') }}</h3>
          <p v-if="breakdowns.by_department.length === 0" class="state">{{ t('dashboard.empty') }}</p>
          <ul v-else class="bars">
            <li v-for="row in breakdowns.by_department" :key="row.id">
              <span class="bar-label">{{ localName(row) }}</span>
              <span class="bar-track">
                <span class="bar-fill gold" :style="{ inlineSize: share(breakdowns.by_department, row.total) }" />
              </span>
              <span class="bar-value">{{ number(row.total) }}</span>
            </li>
          </ul>
        </section>

        <section class="card panel wide">
          <h3>{{ t('dashboard.trend') }}</h3>
          <p class="legend">
            <span class="key created" />{{ t('dashboard.created') }}
            <span class="key completed" />{{ t('dashboard.completedShort') }}
          </p>
          <ul class="trend">
            <li v-for="month in breakdowns.by_month" :key="month.month">
              <span class="columns">
                <span
                  class="column created"
                  :style="{ blockSize: monthHeight(month.created) }"
                  :title="`${t('dashboard.created')}: ${month.created}`"
                />
                <span
                  class="column completed"
                  :style="{ blockSize: monthHeight(month.completed) }"
                  :title="`${t('dashboard.completedShort')}: ${month.completed}`"
                />
              </span>
              <span class="month">{{ monthLabel(month.month) }}</span>
            </li>
          </ul>
        </section>
      </div>
    </template>
  </section>
</template>

<style scoped>
.heading { display: flex; align-items: end; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
h2 { margin: 0; color: var(--color-brand-text); font-size: 1.2rem; }
.subtitle { margin: .15rem 0 0; color: var(--color-muted); font-size: .82rem; }
.state { padding: .5rem; margin: 0; color: var(--color-muted); }
.alert { padding: .65rem .8rem; margin: 0 0 1rem; border: 1px solid var(--color-danger-border); border-radius: var(--radius-lg); color: var(--color-danger-fg); background: var(--color-danger-bg); }
.alert .ghost { margin-inline-start: .5rem; }
.ghost {
  padding: .4rem .65rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg);
  background: var(--color-surface); color: var(--color-black-700); font-size: .85rem;
  cursor: pointer; text-decoration: none;
}
.ghost:hover { background: var(--color-surface-hover); }

.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: .85rem; margin-bottom: 1rem; }
.tile {
  display: flex; flex-direction: column; gap: .3rem; padding: 1rem 1.1rem;
  background: var(--color-surface); border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  /* Logical property so the accent stripe sits on the leading edge in both
     directions rather than jumping sides when the locale flips. */
  border-inline-start: 4px solid var(--color-border-hover);
}
.tile.good { border-inline-start-color: var(--color-emerald); }
.tile.info { border-inline-start-color: var(--color-blue); }
.tile.bad { border-inline-start-color: var(--color-red); }
.tile-label { color: var(--color-muted); font-size: .78rem; }
.tile-value { color: var(--color-brand-text); font-size: 1.6rem; line-height: 1.1; }
.tile-unit { color: var(--color-muted); font-size: .72rem; }

.panels { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem; }
.panel { padding: 1.25rem; }
.panel.wide { grid-column: 1 / -1; }
.panel h3 { margin: 0 0 1rem; font-size: 1rem; color: var(--color-black-800); }

.bars { list-style: none; margin: 0; padding: 0; display: grid; gap: .55rem; }
.bars li { display: grid; grid-template-columns: minmax(90px, 34%) 1fr auto; align-items: center; gap: .6rem; }
.bar-label { font-size: .8rem; color: var(--color-black-700); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.bar-track { block-size: 9px; background: var(--color-surface-hover); border-radius: var(--radius-full); overflow: hidden; }
.bar-fill { display: block; block-size: 100%; border-radius: var(--radius-full); background: var(--color-brand); }
.bar-fill.nav { background: var(--color-brand); }
.bar-fill.gold { background: var(--color-primary); }
.bar-value { font-size: .8rem; color: var(--color-muted); font-variant-numeric: tabular-nums; }

.legend { display: flex; align-items: center; gap: .4rem; margin: -.5rem 0 .75rem; color: var(--color-muted); font-size: .75rem; }
.legend .key { inline-size: 10px; block-size: 10px; border-radius: 2px; }
.legend .key.completed { margin-inline-start: .75rem; }
.key.created, .column.created { background: var(--color-brand); }
.key.completed, .column.completed { background: var(--color-primary); }

.trend { list-style: none; margin: 0; padding: 0; display: flex; align-items: end; gap: .5rem; block-size: 170px; }
.trend li { flex: 1; display: flex; flex-direction: column; align-items: center; gap: .35rem; block-size: 100%; }
.columns { flex: 1; display: flex; align-items: end; justify-content: center; gap: 3px; inline-size: 100%; }
.column { inline-size: 42%; max-inline-size: 18px; border-radius: 3px 3px 0 0; }
.month { color: var(--color-muted); font-size: .68rem; white-space: nowrap; }
</style>
