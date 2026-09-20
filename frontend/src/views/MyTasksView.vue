<script setup>
/**
 * «المهام المعلقة» — everything waiting on the signed-in user, in one place.
 *
 * Replaces the five per-role approval queues, each of which lived on its own
 * screen and was visible only to the one role that held it, so nobody had an
 * answer to "what is waiting for me" that spanned their whole job.
 *
 * This screen LISTS; it does not act. Every row links to the screen that
 * already owns that action — the request workspace for an approval, the live
 * runner for a vote, the legal-review screen for a verdict — so each action
 * keeps exactly one implementation and one set of guards. The server decides
 * which sources a reader gets, by checking the grant that lets them act, so
 * there is nothing to filter here and no row that cannot be acted on.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import AppIcon from '../components/AppIcon.vue'

const { t, locale } = useI18n()

const loading = ref(true)
const error = ref('')
const sources = ref([])
const total = ref(0)
const truncated = ref(false)
const activeSource = ref('all')

const ICON_BY_SOURCE = {
  approval: 'user-check',
  vote: 'check-square',
  candidate: 'file-plus',
  legal_review: 'scale',
  minutes_signature: 'book',
  completion: 'inbox',
}

const visibleSources = computed(() =>
  activeSource.value === 'all'
    ? sources.value
    : sources.value.filter((source) => source.code === activeSource.value),
)

const overdueCount = computed(() =>
  sources.value.reduce(
    (sum, source) => sum + source.tasks.filter((task) => task.is_overdue).length,
    0,
  ),
)

function sourceLabel(code) {
  return t(`myTasks.sources.${code}`)
}

/**
 * Days a task has been waiting, computed here rather than sent: it is just
 * elapsed time, and a number baked server-side would be stale the moment the
 * page sat open.
 */
function waitingDays(task) {
  if (!task.waiting_since) return null
  const since = new Date(task.waiting_since)
  if (Number.isNaN(since.getTime())) return null
  return Math.max(0, Math.floor((Date.now() - since.getTime()) / 86400000))
}

function formatDate(value) {
  if (!value) return null
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return null
  return date.toLocaleDateString(locale.value === 'ar' ? 'ar-LY' : 'en-GB')
}

async function load() {
  loading.value = true
  error.value = ''

  try {
    const { data } = await api.get('/my-tasks')
    sources.value = data.data?.sources ?? []
    total.value = data.data?.total ?? 0
    truncated.value = Boolean(data.data?.truncated)
  } catch (e) {
    error.value = e.response?.data?.message || t('myTasks.loadFailed')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="page tasks">
    <header class="head">
      <div>
        <h1>{{ t('myTasks.title') }}</h1>
        <p class="lede">{{ t('myTasks.lede') }}</p>
      </div>
      <button type="button" class="ghost" :disabled="loading" @click="load">
        {{ t('myTasks.refresh') }}
      </button>
    </header>

    <p v-if="loading" class="muted">{{ t('common.loading') }}</p>
    <p v-else-if="error" class="alert danger">{{ error }}</p>

    <template v-else>
      <div v-if="!sources.length" class="card card-flat empty">
        <AppIcon name="check-square" />
        <p>{{ t('myTasks.empty') }}</p>
      </div>

      <template v-else>
        <div class="summary">
          <span class="count">{{ t('myTasks.total', { count: total }) }}</span>
          <span v-if="overdueCount" class="count overdue">
            {{ t('myTasks.overdue', { count: overdueCount }) }}
          </span>
        </div>

        <!-- A source with nothing in it is never sent, so every chip has rows. -->
        <div class="filters">
          <button
            type="button"
            :class="['chip', { active: activeSource === 'all' }]"
            @click="activeSource = 'all'"
          >
            {{ t('myTasks.all') }} ({{ total }})
          </button>
          <button
            v-for="source in sources"
            :key="source.code"
            type="button"
            :class="['chip', { active: activeSource === source.code }]"
            @click="activeSource = source.code"
          >
            {{ sourceLabel(source.code) }} ({{ source.count }})
          </button>
        </div>

        <p v-if="truncated" class="alert warning">{{ t('myTasks.truncated') }}</p>

        <section v-for="source in visibleSources" :key="source.code" class="group">
          <h2>
            <AppIcon :name="ICON_BY_SOURCE[source.code] || 'dot'" />
            {{ sourceLabel(source.code) }}
            <span class="badge">{{ source.count }}</span>
          </h2>

          <ul class="rows">
            <li v-for="task in source.tasks" :key="task.id" :class="{ overdue: task.is_overdue }">
              <RouterLink :to="task.route" class="row">
                <span class="main">
                  <strong>{{ task.title }}</strong>
                  <small v-if="task.reference_number" class="ref">{{ task.reference_number }}</small>
                </span>

                <span class="meta">
                  <span v-if="task.subject" class="subject">{{ task.subject }}</span>
                  <span v-if="waitingDays(task) !== null" class="waiting">
                    {{ t('myTasks.waiting', { days: waitingDays(task) }) }}
                  </span>
                  <span v-if="task.is_overdue" class="flag">{{ t('myTasks.late') }}</span>
                  <span v-else-if="formatDate(task.due_at)" class="due">
                    {{ t('myTasks.due', { date: formatDate(task.due_at) }) }}
                  </span>
                </span>
              </RouterLink>
            </li>
          </ul>
        </section>
      </template>
    </template>
  </section>
</template>

<style scoped>
.tasks {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
}

h1 {
  margin: 0;
  font-size: 1.35rem;
  color: var(--color-brand-text);
}

.lede {
  margin: 0.25rem 0 0;
  color: var(--color-muted);
  font-size: var(--text-base);
}

.muted {
  color: var(--color-muted);
}

.card.empty {
  padding: var(--space-6) var(--space-4);
  text-align: center;
  color: var(--color-muted);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-3);
}

.summary {
  display: flex;
  gap: var(--space-2);
  flex-wrap: wrap;
}

.count {
  font-size: var(--text-sm);
  color: var(--color-muted);
  font-variant-numeric: tabular-nums;
}

.count.overdue {
  color: var(--color-danger-fg);
  font-weight: 600;
}

.filters {
  display: flex;
  gap: 0.4rem;
  flex-wrap: wrap;
}

.group h2 {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin: 0 0 0.5rem;
  font-size: 1rem;
  color: var(--color-foreground);
}

.badge {
  background: var(--color-surface-hover);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-full);
  padding: 0 0.5rem;
  font-size: var(--text-sm);
  color: var(--color-muted);
  font-variant-numeric: tabular-nums;
}

.rows {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  /* Logical property: the accent sits on the reading-start edge, so it
     mirrors with the document direction rather than staying on the left. */
  border-inline-start: 3px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: 0.7rem 0.9rem;
  text-decoration: none;
  color: inherit;
}

.row:hover {
  border-color: var(--color-border-hover);
  background: var(--color-surface-hover);
}

li.overdue .row {
  border-inline-start-color: var(--color-danger-fg);
}

.main {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  min-width: 12rem;
}

.ref {
  color: var(--color-muted);
  font-size: 0.8rem;
}

.meta {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
  font-size: 0.82rem;
  color: var(--color-muted);
  text-align: start;
}

.flag {
  color: var(--color-danger-fg);
  font-weight: 600;
}
</style>
