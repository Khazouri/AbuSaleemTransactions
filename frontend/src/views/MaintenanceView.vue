<script setup>
/**
 * Maintenance & Deployment (الصيانة والنشر).
 *
 * The reason this screen exists: on cPanel shared hosting there is usually no
 * SSH, so after uploading a release there is no way to run `php artisan
 * migrate` — and a migration that never ran reaches users as "column not
 * found". This is that missing step, plus the cache and dependency commands
 * that go with a deployment.
 *
 * Every command comes from a fixed server-side allowlist; this screen only ever
 * sends a code. There is deliberately no free-text command box — see
 * MaintenanceCommandCatalog for why that boundary is the whole security model.
 *
 * The diagnostics panel is not decoration. On this kind of host the common
 * failure is not an error but silence: proc_open disabled, a 30-second
 * execution limit, composer at a path nobody guessed right. Each of those is
 * reported by name so a greyed-out button explains itself.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'

const { t, locale } = useI18n()

const info = ref(null)
const loading = ref(false)
const loadError = ref(null)

const runs = ref([])
const runsPage = ref({ current_page: 1, last_page: 1, total: 0 })
const historyLoading = ref(false)

/** The command currently executing, so only its own button shows a spinner. */
const runningCode = ref(null)
const runError = ref(null)
const lastRun = ref(null)

/** Destructive commands open this instead of running straight away. */
const confirmTarget = ref(null)
const confirmText = ref('')

/** Which history rows the reader has expanded, keyed by run id. */
const expanded = ref({})

const enabled = computed(() => info.value?.enabled === true)
const env = computed(() => info.value?.environment ?? null)
const shellAvailable = computed(() => env.value?.shell?.available === true)
const phrase = computed(() => info.value?.confirmation_phrase ?? 'CONFIRM')
const canRunDestructive = computed(() => info.value?.can_run_destructive === true)

const isArabic = computed(() => locale.value === 'ar')
const label = (row) => (isArabic.value ? row.label_ar : row.label_en) || row.label_en || row.label_ar
const describe = (row) => (isArabic.value ? row.description_ar : row.description_en)

/** Commands in the server's own group order; unknown groups fall to the end. */
const grouped = computed(() => {
  const commands = info.value?.commands ?? []
  const order = info.value?.groups ?? []
  const seen = [...new Set(commands.map((c) => c.group))]
  const ordered = [...order.filter((g) => seen.includes(g)), ...seen.filter((g) => !order.includes(g))]
  return ordered.map((group) => ({ group, items: commands.filter((c) => c.group === group) }))
})

/**
 * Why a command cannot be run right now, or null if it can.
 *
 * Computed here as well as enforced on the server so the reason is visible
 * before clicking, rather than arriving as a validation error afterwards.
 */
function blockedReason(command) {
  if (!enabled.value) return t('maintenance.blocked.disabled')
  if (command.kind === 'shell' && !shellAvailable.value) return t('maintenance.blocked.noShell')
  if (command.kind === 'shell') {
    const binary = command.preview.split(' ')[0]
    const probe = Object.values(env.value?.binaries ?? {}).find((b) => b.path === binary)
    if (probe && !probe.available) return t('maintenance.blocked.binaryMissing')
  }
  if (command.destructive && !canRunDestructive.value) return t('maintenance.blocked.needsApproval')
  return null
}

function dateTime(value) {
  if (!value) return t('common.none')
  return new Intl.DateTimeFormat(isArabic.value ? 'ar-LY' : 'en-GB', {
    year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit',
  }).format(new Date(value))
}

function duration(ms) {
  if (ms === null || ms === undefined) return t('common.none')
  return ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`
}

function bytes(value) {
  if (value === null || value === undefined) return t('common.none')
  const mb = value / (1024 * 1024)
  if (mb < 1024) return `${mb.toFixed(0)} MB`
  return `${(mb / 1024).toFixed(1)} GB`
}

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const { data } = await api.get('/maintenance')
    info.value = data.data
  } catch (error) {
    loadError.value = error
  } finally {
    loading.value = false
  }
}

async function loadHistory(page = 1) {
  historyLoading.value = true
  try {
    const { data } = await api.get('/maintenance/runs', { params: { page } })
    runs.value = data.data ?? []
    runsPage.value = data.meta ?? runsPage.value
  } catch {
    // The history is supporting information; a failure to load it must not
    // hide the commands, which are the point of the screen.
    runs.value = []
  } finally {
    historyLoading.value = false
  }
}

function start(command) {
  runError.value = null
  if (command.destructive) {
    confirmTarget.value = command
    confirmText.value = ''
    return
  }
  execute(command)
}

async function execute(command, confirmation = null) {
  runningCode.value = command.code
  runError.value = null
  lastRun.value = null
  try {
    const { data } = await api.post('/maintenance/run', {
      command: command.code,
      ...(confirmation ? { confirmation } : {}),
    })
    lastRun.value = data.data
  } catch (error) {
    const errors = error?.response?.data?.errors
    runError.value =
      errors?.command?.[0] ??
      errors?.confirmation?.[0] ??
      error?.response?.data?.message ??
      t('maintenance.runFailed')
  } finally {
    runningCode.value = null
    confirmTarget.value = null
    // Both refreshed regardless of outcome: a failed run still recorded a row,
    // and a migration that ran changes the pending count the panel above shows.
    await Promise.all([load(), loadHistory(1)])
  }
}

function confirmRun() {
  if (!confirmTarget.value) return
  execute(confirmTarget.value, confirmText.value.trim())
}

async function clearHistory() {
  if (!window.confirm(t('maintenance.confirmClear'))) return
  try {
    await api.delete('/maintenance/runs')
    await loadHistory(1)
  } catch (error) {
    runError.value = error?.response?.data?.message ?? t('maintenance.clearFailed')
  }
}

async function toggleRow(row) {
  if (expanded.value[row.id]) {
    expanded.value = { ...expanded.value, [row.id]: null }
    return
  }
  // The list carries only a tail preview, so the full output is fetched on
  // demand — twenty rows of capped output would be a megabyte of JSON nobody
  // asked for.
  try {
    const { data } = await api.get(`/maintenance/runs/${row.id}`)
    expanded.value = { ...expanded.value, [row.id]: data.data }
  } catch {
    expanded.value = { ...expanded.value, [row.id]: { output: null } }
  }
}

onMounted(() => {
  load()
  loadHistory()
})
</script>

<template>
  <section class="maintenance">
    <div class="heading">
      <div>
        <h2>{{ t('maintenance.title') }}</h2>
        <p class="subtitle">{{ t('maintenance.subtitle') }}</p>
      </div>
      <button class="ghost" type="button" :disabled="loading" @click="load">
        {{ t('maintenance.refresh') }}
      </button>
    </div>

    <p v-if="loadError" class="alert">
      {{ t('nav.error') }}
      <button class="ghost" type="button" @click="load">{{ t('common.retry') }}</button>
    </p>
    <p v-if="loading && !info" class="state">{{ t('common.loading') }}</p>

    <template v-if="info">
      <p v-if="!enabled" class="alert">{{ t('maintenance.disabledBanner') }}</p>

      <!-- ── Diagnostics ─────────────────────────────────────────────── -->
      <div class="card diagnostics">
        <h3>{{ t('maintenance.diagnostics.title') }}</h3>
        <p class="hint">{{ t('maintenance.diagnostics.hint') }}</p>

        <div class="facts">
          <div class="fact" :class="{ warn: env.migrations.pending > 0 }">
            <span class="key">{{ t('maintenance.diagnostics.pendingMigrations') }}</span>
            <span class="value">{{ env.migrations.pending ?? t('common.none') }}</span>
            <small v-if="env.migrations.pending > 0" class="note ltr">
              {{ (env.migrations.pending_names || []).slice(0, 4).join(', ') }}
            </small>
            <small v-if="env.migrations.error" class="note danger">{{ env.migrations.error }}</small>
          </div>

          <div class="fact" :class="{ bad: !env.database.connected }">
            <span class="key">{{ t('maintenance.diagnostics.database') }}</span>
            <span class="value ltr">{{ env.database.driver }} · {{ env.database.database }}</span>
            <small class="note">
              {{ env.database.connected ? t('maintenance.diagnostics.connected') : t('maintenance.diagnostics.notConnected') }}
            </small>
          </div>

          <div class="fact" :class="{ warn: !shellAvailable }">
            <span class="key">{{ t('maintenance.diagnostics.shell') }}</span>
            <span class="value">
              {{ shellAvailable ? t('maintenance.diagnostics.shellOn') : t('maintenance.diagnostics.shellOff') }}
            </span>
            <small v-if="!shellAvailable" class="note">
              {{ env.shell.reason === 'proc_open_disabled'
                ? t('maintenance.diagnostics.procOpenDisabled')
                : t('maintenance.diagnostics.shellDisabledByConfig') }}
            </small>
          </div>

          <div class="fact" :class="{ warn: env.php.max_execution_time > 0 && env.php.max_execution_time < 120 }">
            <span class="key">{{ t('maintenance.diagnostics.executionLimit') }}</span>
            <span class="value ltr">
              {{ env.php.max_execution_time === 0 ? '∞' : `${env.php.max_execution_time}s` }}
            </span>
            <small v-if="env.php.max_execution_time > 0 && env.php.max_execution_time < 120" class="note">
              {{ t('maintenance.diagnostics.executionLimitWarning') }}
            </small>
          </div>

          <div class="fact">
            <span class="key">{{ t('maintenance.diagnostics.php') }}</span>
            <span class="value ltr">{{ env.php.version }}</span>
            <small class="note ltr">{{ env.php.sapi }} · {{ env.php.memory_limit }}</small>
          </div>

          <div class="fact" :class="{ warn: env.app.debug && env.app.environment === 'production' }">
            <span class="key">{{ t('maintenance.diagnostics.environment') }}</span>
            <span class="value ltr">{{ env.app.environment }}</span>
            <small v-if="env.app.debug" class="note">{{ t('maintenance.diagnostics.debugOn') }}</small>
          </div>

          <div class="fact">
            <span class="key">{{ t('maintenance.diagnostics.caches') }}</span>
            <span class="value">
              {{ env.caches.config ? t('maintenance.diagnostics.cached') : t('maintenance.diagnostics.notCached') }}
            </span>
            <small v-if="env.caches.config" class="note">{{ t('maintenance.diagnostics.configCachedNote') }}</small>
          </div>

          <div class="fact" :class="{ warn: env.queue.pending > 0 }">
            <span class="key">{{ t('maintenance.diagnostics.queue') }}</span>
            <span class="value">{{ env.queue.pending ?? t('common.none') }}</span>
            <small v-if="env.queue.failed" class="note danger">
              {{ t('maintenance.diagnostics.failedJobs', { count: env.queue.failed }) }}
            </small>
          </div>

          <div
            class="fact"
            :class="{ bad: !env.filesystem.storage_writable || !env.filesystem.bootstrap_cache_writable }"
          >
            <span class="key">{{ t('maintenance.diagnostics.writable') }}</span>
            <span class="value">
              {{ env.filesystem.storage_writable && env.filesystem.bootstrap_cache_writable
                ? t('maintenance.diagnostics.ok')
                : t('maintenance.diagnostics.notWritable') }}
            </span>
            <small class="note">
              {{ t('maintenance.diagnostics.freeSpace', { size: bytes(env.filesystem.free_bytes) }) }}
            </small>
          </div>

          <div v-for="(probe, name) in env.binaries" :key="name" class="fact" :class="{ warn: !probe.available }">
            <span class="key ltr">{{ name }}</span>
            <span class="value">
              {{ probe.available ? t('maintenance.diagnostics.found') : t('maintenance.diagnostics.notFound') }}
            </span>
            <small class="note ltr">{{ probe.version || probe.path }}</small>
          </div>
        </div>
      </div>

      <!-- ── Commands ────────────────────────────────────────────────── -->
      <p v-if="runError" class="alert">{{ runError }}</p>

      <div v-for="block in grouped" :key="block.group" class="card commands">
        <h3>{{ t(`maintenance.groups.${block.group}`) }}</h3>
        <ul class="command-list">
          <li v-for="command in block.items" :key="command.code" :class="{ destructive: command.destructive }">
            <div class="command-body">
              <div class="command-head">
                <strong>{{ label(command) }}</strong>
                <span v-if="command.destructive" class="pill danger">{{ t('maintenance.destructive') }}</span>
                <span v-else class="pill">{{ t(`maintenance.kind.${command.kind}`) }}</span>
              </div>
              <code class="preview ltr">{{ command.preview }}</code>
              <p class="description">{{ describe(command) }}</p>
              <p v-if="blockedReason(command)" class="blocked">{{ blockedReason(command) }}</p>
            </div>
            <button
              v-can="'maintenance.add'"
              class="primary"
              :class="{ danger: command.destructive }"
              type="button"
              :disabled="!!runningCode || !!blockedReason(command)"
              @click="start(command)"
            >
              {{ runningCode === command.code ? t('maintenance.running') : t('maintenance.run') }}
            </button>
          </li>
        </ul>
      </div>

      <p v-if="runningCode" class="notice">{{ t('maintenance.runningHint') }}</p>

      <!-- ── The last run's output ───────────────────────────────────── -->
      <div v-if="lastRun" class="card result" :class="lastRun.status">
        <h3>
          {{ isArabic ? lastRun.label_ar : lastRun.label_en }}
          —
          {{ t(`maintenance.status.${lastRun.status}`) }}
        </h3>
        <p class="meta ltr">
          {{ t('maintenance.exitCode') }}: {{ lastRun.exit_code ?? '—' }} · {{ duration(lastRun.duration_ms) }}
        </p>
        <p v-if="lastRun.error" class="alert">{{ lastRun.error }}</p>
        <pre v-if="lastRun.output" class="output ltr">{{ lastRun.output }}</pre>
        <p v-else-if="!lastRun.error" class="state">{{ t('maintenance.noOutput') }}</p>
      </div>

      <!-- ── History ─────────────────────────────────────────────────── -->
      <div class="card history">
        <div class="history-head">
          <h3>{{ t('maintenance.history.title') }}</h3>
          <button v-can="'maintenance.delete'" class="ghost danger" type="button" @click="clearHistory">
            {{ t('maintenance.history.clear') }}
          </button>
        </div>
        <p v-if="historyLoading" class="state">{{ t('common.loading') }}</p>
        <p v-else-if="runs.length === 0" class="state">{{ t('maintenance.history.empty') }}</p>
        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{{ t('maintenance.history.command') }}</th>
                <th>{{ t('maintenance.history.status') }}</th>
                <th>{{ t('maintenance.history.duration') }}</th>
                <th>{{ t('maintenance.history.ranBy') }}</th>
                <th>{{ t('maintenance.history.at') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="row in runs" :key="row.id">
                <tr :class="row.status">
                  <td>
                    <strong>{{ isArabic ? row.label_ar : row.label_en }}</strong>
                    <small class="note ltr">{{ row.preview }}</small>
                  </td>
                  <td>
                    <span class="pill" :class="row.status">
                      {{ row.is_stale ? t('maintenance.status.stale') : t(`maintenance.status.${row.status}`) }}
                    </span>
                  </td>
                  <td class="nowrap ltr">{{ duration(row.duration_ms) }}</td>
                  <td>{{ row.ran_by?.name ?? t('common.none') }}</td>
                  <td class="nowrap">{{ dateTime(row.created_at) }}</td>
                  <td>
                    <button v-if="row.has_output || row.error" class="ghost" type="button" @click="toggleRow(row)">
                      {{ expanded[row.id] ? t('maintenance.history.hide') : t('maintenance.history.show') }}
                    </button>
                  </td>
                </tr>
                <tr v-if="expanded[row.id]" class="output-row">
                  <td colspan="6">
                    <p v-if="row.error" class="alert">{{ row.error }}</p>
                    <pre class="output ltr">{{ expanded[row.id].output || row.output_preview }}</pre>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
        <nav v-if="runsPage.last_page > 1" class="pagination">
          <button class="ghost" :disabled="runsPage.current_page <= 1" @click="loadHistory(runsPage.current_page - 1)">
            {{ t('requests.previous') }}
          </button>
          <span>{{ t('requests.page', { current: runsPage.current_page, last: runsPage.last_page }) }}</span>
          <button
            class="ghost"
            :disabled="runsPage.current_page >= runsPage.last_page"
            @click="loadHistory(runsPage.current_page + 1)"
          >
            {{ t('requests.next') }}
          </button>
        </nav>
      </div>

      <p class="footnote">{{ t('maintenance.footnote') }}</p>
    </template>

    <!-- ── Destructive confirmation ──────────────────────────────────── -->
    <div v-if="confirmTarget" class="overlay" @click.self="confirmTarget = null">
      <div class="modal">
        <h3>{{ t('maintenance.confirm.title') }}</h3>
        <p class="modal-command">
          <strong>{{ label(confirmTarget) }}</strong>
          <code class="preview ltr">{{ confirmTarget.preview }}</code>
        </p>
        <p class="alert">{{ describe(confirmTarget) }}</p>
        <label>
          {{ t('maintenance.confirm.prompt', { phrase }) }}
          <input v-model="confirmText" type="text" class="ltr" autocomplete="off" />
        </label>
        <div class="modal-actions">
          <button class="ghost" type="button" @click="confirmTarget = null">{{ t('common.cancel') }}</button>
          <button
            class="primary danger"
            type="button"
            :disabled="confirmText.trim() !== phrase || !!runningCode"
            @click="confirmRun"
          >
            {{ t('maintenance.confirm.go') }}
          </button>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.heading { display: flex; align-items: end; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; }
h2 { margin: 0; color: var(--color-brand-text); font-size: 1.2rem; }
h3 { margin: 0 0 .5rem; color: var(--color-brand-text); font-size: .95rem; }
.subtitle { margin: .15rem 0 0; color: var(--color-muted); font-size: .82rem; max-width: 60ch; }
.hint { margin: 0 0 .9rem; color: var(--color-muted); font-size: .78rem; }

.card { padding: 1.1rem 1.25rem; margin-bottom: 1rem; }
button { cursor: pointer; border-radius: var(--radius-lg); font-size: .85rem; }
.primary { padding: .45rem .9rem; border: 0; color: var(--color-on-brand); background: var(--color-brand); white-space: nowrap; }
.primary.danger { background: var(--color-danger-fg); color: var(--color-surface); }
.ghost { padding: .35rem .6rem; border: 1px solid var(--color-border-hover); background: var(--color-surface); color: var(--color-black-700); }
.ghost:hover:not(:disabled) { background: var(--color-surface-hover); }
.ghost.danger { color: var(--color-danger-fg); border-color: var(--color-danger-border); }
button:disabled { cursor: not-allowed; opacity: .5; }

.alert { padding: .6rem .8rem; margin: 0 0 .9rem; border: 1px solid var(--color-danger-border); border-radius: var(--radius-lg); color: var(--color-danger-fg); background: var(--color-danger-bg); font-size: .82rem; }
.notice { padding: .6rem .8rem; margin: 0 0 1rem; border: 1px solid var(--color-warning-border); border-radius: var(--radius-lg); color: var(--color-warning-fg); background: var(--color-warning-bg); font-size: .82rem; }
.state { padding: .4rem; margin: 0; color: var(--color-muted); font-size: .84rem; }
/* Command lines and log output are LTR whatever the page direction is —
   Arabic-mirroring a shell command makes it unreadable and uncopyable. */
.ltr { direction: ltr; unicode-bidi: isolate; text-align: start; }

.facts { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: .6rem; }
.fact { padding: .55rem .7rem; border: 1px solid var(--color-border); border-radius: var(--radius-lg); background: var(--color-surface); }
.fact.warn { border-color: var(--color-warning-border); background: var(--color-warning-bg); }
.fact.bad { border-color: var(--color-danger-border); background: var(--color-danger-bg); }
.key { display: block; color: var(--color-muted); font-size: .7rem; }
.value { display: block; margin-top: .1rem; font-size: .95rem; font-weight: 600; }
.note { display: block; margin-top: .15rem; color: var(--color-muted); font-size: .7rem; word-break: break-word; }
.note.danger { color: var(--color-danger-fg); }

.command-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .5rem; }
.command-list li { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .65rem .8rem; border: 1px solid var(--color-border); border-radius: var(--radius-lg); }
.command-list li.destructive { border-color: var(--color-danger-border); background: var(--color-danger-bg); }
.command-body { min-width: 0; }
.command-head { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.command-head strong { font-size: .88rem; }
.preview { display: inline-block; margin-top: .25rem; padding: .1rem .4rem; border-radius: var(--radius-sm, 4px); background: var(--color-surface-hover); font-family: var(--font-mono); font-size: .72rem; word-break: break-all; }
.description { margin: .35rem 0 0; color: var(--color-muted); font-size: .76rem; max-width: 78ch; }
.blocked { margin: .3rem 0 0; color: var(--color-warning-fg); font-size: .74rem; }
.pill { display: inline-block; padding: .08rem .45rem; border: 1px solid var(--color-border-hover); border-radius: 999px; font-size: .68rem; white-space: nowrap; }
.pill.danger { border-color: var(--color-danger-border); color: var(--color-danger-fg); }
.pill.completed { border-color: var(--color-success-border); color: var(--color-success-fg); }
.pill.failed { border-color: var(--color-danger-border); color: var(--color-danger-fg); }
.pill.running { border-color: var(--color-warning-border); color: var(--color-warning-fg); }

.result.completed { border-inline-start: 3px solid var(--color-success-fg); }
.result.failed { border-inline-start: 3px solid var(--color-danger-fg); }
.meta { margin: 0 0 .6rem; color: var(--color-muted); font-size: .76rem; }
.output { max-height: 22rem; overflow: auto; padding: .7rem .8rem; margin: 0; border-radius: var(--radius-lg); background: var(--color-black-900, #111); color: #e6e6e6; font-family: var(--font-mono); font-size: .74rem; line-height: 1.5; white-space: pre-wrap; word-break: break-word; }

.history-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: .5rem; }
.table-wrap { overflow-x: auto; }
table { width: 100%; min-width: 720px; border-collapse: collapse; }
th, td { padding: .55rem .5rem; text-align: start; border-bottom: 1px solid var(--color-border); vertical-align: top; }
th { color: var(--color-muted); font-size: .72rem; font-weight: 600; white-space: nowrap; }
td { font-size: .8rem; }
.nowrap { white-space: nowrap; }
.output-row td { background: var(--color-surface-hover); }
.pagination { display: flex; align-items: center; justify-content: center; gap: .75rem; margin-top: .75rem; color: var(--color-muted); font-size: .82rem; }

.overlay { position: fixed; inset: 0; display: grid; place-items: center; padding: 1rem; background: var(--color-overlay); z-index: 50; }
.modal { width: min(34rem, 100%); padding: 1.25rem; border-radius: var(--radius-lg); background: var(--color-surface); box-shadow: 0 12px 40px rgb(0 0 0 / .25); }
.modal-command { margin: 0 0 .75rem; display: flex; flex-direction: column; gap: .25rem; }
.modal label { display: block; color: var(--color-black-700); font-size: .82rem; }
.modal input { width: 100%; margin-top: .3rem; padding: .5rem .6rem; border: 1px solid var(--color-border-hover); border-radius: var(--radius-lg); background: var(--color-surface); color: var(--color-foreground); font-family: var(--font-mono); }
.modal-actions { display: flex; justify-content: flex-end; gap: .5rem; margin-top: 1rem; }

.footnote { margin-top: 1.25rem; padding: .75rem .9rem; border: 1px dashed var(--color-border-hover); border-radius: var(--radius-lg); color: var(--color-muted); font-size: .78rem; max-width: 90ch; }
</style>
