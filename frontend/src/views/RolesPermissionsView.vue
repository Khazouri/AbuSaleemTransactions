<script setup>
/**
 * Roles & Permissions screen (الأدوار والصلاحيات) — Stage 8.
 *
 * Editor for `screen_role_permissions`, the table that is the source of
 * truth for both the Stage 5 sidebar (can_view) and Stage 9's enforcement.
 * The grid is screens (rows) x the seven can_* actions (columns) for ONE
 * role at a time — a role tab strip switches which role's column set is
 * showing, rather than rendering all 8 roles x 7 actions side by side, which
 * would be unreadable. The whole matrix is fetched and saved in one request
 * each way; 23 screens x 8 roles is small enough that per-cell round trips
 * would just be chattier for no benefit.
 */
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'

const { t, locale } = useI18n()

const ACTIONS = ['can_view', 'can_add', 'can_edit', 'can_delete', 'can_approve', 'can_print', 'can_export']

const screens = ref([])
const roles = ref([])
/** Keyed by `${screenId}:${roleId}` -> { can_view, can_add, ... }. */
const matrix = ref({})

const loading = ref(false)
const saving = ref(false)
const loadError = ref(null)
const formError = ref(null)
const saveSuccess = ref(false)
const dirty = ref(false)

const activeRoleId = ref(null)

function cellKey(screenId, roleId) {
  return `${screenId}:${roleId}`
}

function blankCell() {
  return Object.fromEntries(ACTIONS.map((action) => [action, false]))
}

/** Display a screen/role's name in the active language. */
function label(entity) {
  return locale.value === 'ar'
    ? entity.name_ar || entity.name_en
    : entity.name_en || entity.name_ar
}

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const { data } = await api.get('/screen-role-permissions')
    screens.value = data.screens
    roles.value = data.roles

    // Every (screen, role) pair gets a cell, defaulting to all-false when the
    // backend has no row yet (e.g. a screen added after the last seed run).
    const built = {}
    for (const screen of screens.value) {
      for (const role of roles.value) {
        built[cellKey(screen.id, role.id)] = blankCell()
      }
    }
    for (const perm of data.permissions) {
      built[cellKey(perm.screen_id, perm.role_id)] = Object.fromEntries(
        ACTIONS.map((action) => [action, perm[action]]),
      )
    }
    matrix.value = built
    dirty.value = false

    if (activeRoleId.value === null && roles.value.length > 0) {
      activeRoleId.value = roles.value[0].id
    }
  } catch (e) {
    loadError.value = e
  } finally {
    loading.value = false
  }
}

function cell(screenId, roleId) {
  return matrix.value[cellKey(screenId, roleId)]
}

function toggle(screenId, roleId, action) {
  cell(screenId, roleId)[action] = !cell(screenId, roleId)[action]
  dirty.value = true
  saveSuccess.value = false
}

/** Does every screen have `action` checked for the active role? Drives the
 *  column header's own checkbox, which toggles the whole column at once. */
function columnFullyChecked(action) {
  return screens.value.every((screen) => cell(screen.id, activeRoleId.value)[action])
}

function toggleColumn(action) {
  const next = !columnFullyChecked(action)
  for (const screen of screens.value) {
    cell(screen.id, activeRoleId.value)[action] = next
  }
  dirty.value = true
  saveSuccess.value = false
}

function selectRole(roleId) {
  activeRoleId.value = roleId
}

async function save() {
  saving.value = true
  formError.value = null
  saveSuccess.value = false

  const items = []
  for (const screen of screens.value) {
    for (const role of roles.value) {
      items.push({ screen_id: screen.id, role_id: role.id, ...cell(screen.id, role.id) })
    }
  }

  try {
    await api.put('/screen-role-permissions', { items })
    dirty.value = false
    saveSuccess.value = true
  } catch (e) {
    formError.value = e?.response?.data?.message ?? 'تعذّر الحفظ. حاول مرة أخرى.'
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <section>
    <p v-if="formError" class="alert">{{ formError }}</p>
    <p v-if="saveSuccess" class="alert success">{{ t('rolesPermissions.saveSuccess') }}</p>

    <p v-if="loading" class="state">{{ t('common.loading') }}</p>
    <p v-else-if="loadError" class="state error">
      {{ t('nav.error') }}
      <button class="ghost" @click="load">{{ t('common.retry') }}</button>
    </p>

    <template v-else>
      <div class="role-tabs">
        <button
          v-for="role in roles"
          :key="role.id"
          type="button"
          class="role-tab"
          :class="{ active: role.id === activeRoleId }"
          :title="role.description ?? ''"
          @click="selectRole(role.id)"
        >
          {{ role.code }} — {{ label(role) }}
        </button>
      </div>

      <div class="card">
        <table>
          <thead>
            <tr>
              <th scope="col">{{ t('rolesPermissions.screen') }}</th>
              <th v-for="action in ACTIONS" :key="action" scope="col" class="action-col">
                <span>{{ t(`rolesPermissions.${action}`) }}</span>
                <label class="checkbox select-all" :title="t('rolesPermissions.selectAllInColumn')">
                  <input
                    type="checkbox"
                    :aria-label="t('rolesPermissions.selectAllInColumn')"
                    :checked="columnFullyChecked(action)"
                    @change="toggleColumn(action)"
                  />
                </label>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="screen in screens" :key="screen.id">
              <td class="name">{{ label(screen) }}</td>
              <td v-for="action in ACTIONS" :key="action" class="action-col">
                <input
                  type="checkbox"
                  :aria-label="`${t(`rolesPermissions.${action}`)} — ${label(screen)}`"
                  :checked="cell(screen.id, activeRoleId)[action]"
                  @change="toggle(screen.id, activeRoleId, action)"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="actions">
        <button class="primary" type="button" :disabled="saving || !dirty" @click="save">
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
        <span v-if="dirty" class="hint">{{ t('rolesPermissions.unsavedChanges') }}</span>
      </div>
    </template>
  </section>
</template>

<style scoped>
.role-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: .4rem;
  margin-bottom: 1rem;
}
.role-tab {
  padding: .4rem .8rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-full);
  background: var(--color-surface);
  color: var(--color-foreground);
  font-size: .82rem;
  cursor: pointer;
}
.role-tab:hover { background: var(--color-surface-hover); }
.role-tab.active {
  background: var(--color-primary);
  color: var(--color-on-primary);
  border-color: var(--color-primary);
}

.card { padding: 1.25rem; margin-bottom: 1rem; overflow-x: auto; }

table { width: 100%; border-collapse: collapse; }
th {
  padding: .5rem;
  text-align: center;
  font-size: .78rem;
  font-weight: 600;
  color: var(--color-muted);
  border-bottom: 1px solid var(--color-border);
  white-space: nowrap;
}
th:first-child { text-align: start; }
td { padding: .55rem .5rem; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
tr:last-child td { border-bottom: 0; }
.name { font-size: .88rem; }
.action-col { text-align: center; }
.select-all { display: flex; justify-content: center; margin-top: .3rem; }

.state { padding: .5rem; color: var(--color-muted); font-size: .9rem; margin: 0 0 1rem; }
.state.error { color: var(--color-red); }
.alert {
  padding: .65rem .8rem;
  background: var(--color-surface);
  color: var(--color-red);
  border: 1px solid var(--color-red);
  border-radius: var(--radius-lg);
  font-size: .875rem;
  margin: 0 0 1rem;
}
.alert.success { color: var(--color-primary); border-color: var(--color-primary); }

.actions { display: flex; align-items: center; gap: .75rem; }
.hint { color: var(--color-muted); font-size: .8rem; }
button { cursor: pointer; border-radius: var(--radius-lg); font-size: .85rem; }
.primary { padding: .5rem .9rem; border: 0; background: var(--color-primary); color: var(--color-on-primary); }
.primary:disabled { opacity: .5; cursor: not-allowed; }
.ghost {
  padding: .35rem .6rem;
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  color: var(--color-foreground);
  margin-inline-start: .3rem;
}
.ghost:hover { background: var(--color-surface-hover); }
</style>
