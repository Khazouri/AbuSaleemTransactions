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
 *
 * Role management (user request 2026-10-02): roles are created and renamed
 * here in a modal, and each screen row has one «مفعّل» switch over its seven
 * actions. Off clears all seven; on grants view only, so switching a screen on
 * never hands out a write the admin did not tick.
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import AppModal from '../components/AppModal.vue'
import { useAuthStore } from '../stores/auth'
import { useScreensStore } from '../stores/screens'

const { t, locale } = useI18n()
const auth = useAuthStore()
const screensStore = useScreensStore()

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
const activeRole = computed(() => roles.value.find((role) => role.id === activeRoleId.value) ?? null)

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

/** A screen is enabled for the active role when any of its actions is on. */
function screenEnabled(screenId) {
  const current = cell(screenId, activeRoleId.value)
  return ACTIONS.some((action) => current[action])
}

function toggleScreen(screenId) {
  const next = !screenEnabled(screenId)
  const current = cell(screenId, activeRoleId.value)
  for (const action of ACTIONS) current[action] = false
  current.can_view = next
  dirty.value = true
  saveSuccess.value = false
}

const showRoleForm = ref(false)
const editingRoleId = ref(null)
const roleForm = ref({ name_ar: '', name_en: '', description: '' })
const roleErrors = ref({})
const roleFormError = ref(null)
const roleSaving = ref(false)

function openRoleForm(role = null) {
  editingRoleId.value = role?.id ?? null
  roleForm.value = {
    name_ar: role?.name_ar ?? '',
    name_en: role?.name_en ?? '',
    description: role?.description ?? '',
  }
  roleErrors.value = {}
  roleFormError.value = null
  showRoleForm.value = true
}

function cancelRoleForm() {
  showRoleForm.value = false
}

/**
 * Patches `roles` in place rather than calling load(): load() rebuilds the
 * whole matrix, which would throw away unsaved ticks on every other tab.
 */
async function saveRole() {
  roleSaving.value = true
  roleErrors.value = {}
  roleFormError.value = null
  try {
    if (editingRoleId.value === null) {
      const { data } = await api.post('/roles', roleForm.value)
      roles.value.push(data.data)
      for (const screen of screens.value) {
        matrix.value[cellKey(screen.id, data.data.id)] = blankCell()
      }
      activeRoleId.value = data.data.id
    } else {
      const { data } = await api.put(`/roles/${editingRoleId.value}`, roleForm.value)
      roles.value = roles.value.map((role) => (role.id === data.data.id ? data.data : role))
    }
    showRoleForm.value = false
  } catch (e) {
    roleErrors.value = e?.response?.data?.errors ?? {}
    roleFormError.value = e?.response?.data?.message ?? t('rolesPermissions.roleSaveFailed')
  } finally {
    roleSaving.value = false
  }
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
    await Promise.all([
      auth.fetchMe(),
      screensStore.fetchScreens(true),
    ])
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
  <section class="page">
    <div class="heading">
      <div>
        <h2>{{ t('rolesPermissions.title') }}</h2>
      </div>
      <div v-if="!loading && !loadError" class="heading-actions">
        <button
          v-if="activeRole"
          v-can="'roles_permissions.edit'"
          class="ghost"
          type="button"
          @click="openRoleForm(activeRole)"
        >
          {{ t('rolesPermissions.editRole') }}
        </button>
        <button v-can="'roles_permissions.add'" class="primary" type="button" @click="openRoleForm()">
          + {{ t('rolesPermissions.newRole') }}
        </button>
      </div>
    </div>

    <AppModal
      v-if="showRoleForm"
      :title="editingRoleId === null ? t('rolesPermissions.newRole') : t('rolesPermissions.editRole')"
      @close="cancelRoleForm"
    >
      <form class="role-form" @submit.prevent="saveRole">
        <p v-if="roleFormError" class="alert">{{ roleFormError }}</p>
        <label>
          {{ t('rolesPermissions.nameAr') }} *
          <input v-model="roleForm.name_ar" required />
          <small v-if="roleErrors.name_ar" class="field-error">{{ roleErrors.name_ar[0] }}</small>
        </label>
        <label>
          {{ t('rolesPermissions.nameEn') }}
          <input v-model="roleForm.name_en" dir="ltr" />
          <small v-if="roleErrors.name_en" class="field-error">{{ roleErrors.name_en[0] }}</small>
        </label>
        <label>
          {{ t('rolesPermissions.description') }}
          <input v-model="roleForm.description" />
          <small v-if="roleErrors.description" class="field-error">{{ roleErrors.description[0] }}</small>
        </label>
        <p v-if="editingRoleId === null" class="hint">{{ t('rolesPermissions.customRoleHint') }}</p>
        <div class="modal-actions">
          <button class="ghost" type="button" @click="cancelRoleForm">{{ t('common.cancel') }}</button>
          <button class="primary" type="submit" :disabled="roleSaving">
            {{ roleSaving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </form>
    </AppModal>

    <p v-if="formError" class="alert">{{ formError }}</p>
    <p v-if="saveSuccess" class="alert success">{{ t('rolesPermissions.saveSuccess') }}</p>

    <p v-if="loading" class="state">{{ t('common.loading') }}</p>
    <p v-else-if="loadError" class="alert">
      {{ t('nav.error') }}
      <button class="ghost" @click="load">{{ t('common.retry') }}</button>
    </p>

    <template v-else>
      <div class="role-tabs">
        <button
          v-for="role in roles"
          :key="role.id"
          type="button"
          class="chip"
          :class="{ active: role.id === activeRoleId }"
          :title="role.description ?? ''"
          @click="selectRole(role.id)"
        >
          {{ role.code }} — {{ label(role) }}
        </button>
      </div>

      <div class="card card-flat card-pad">
        <table>
          <thead>
            <tr>
              <th scope="col">{{ t('rolesPermissions.screen') }}</th>
              <th scope="col" class="action-col">{{ t('rolesPermissions.enabled') }}</th>
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
              <td class="action-col">
                <input
                  type="checkbox"
                  role="switch"
                  :aria-label="`${t('rolesPermissions.enabled')} — ${label(screen)}`"
                  :checked="screenEnabled(screen.id)"
                  @change="toggleScreen(screen.id)"
                />
              </td>
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
        <button v-can="'roles_permissions.edit'" class="primary" type="button" :disabled="saving || !dirty" @click="save">
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
        <span v-if="dirty" class="hint dirty-hint">{{ t('rolesPermissions.unsavedChanges') }}</span>
      </div>
    </template>
  </section>
</template>

<style scoped>
.heading-actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.role-form { display: flex; flex-direction: column; gap: var(--space-4); }
.role-form label { display: flex; flex-direction: column; gap: .3rem; font-size: var(--text-base); }

.role-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin-bottom: var(--space-4);
}

.card { margin-bottom: var(--space-4); overflow-x: auto; }

table { width: 100%; border-collapse: collapse; }
th {
  padding: .5rem;
  text-align: center;
  font-size: var(--text-xs);
  font-weight: 600;
  color: var(--color-muted);
  border-bottom: 1px solid var(--color-border);
  white-space: nowrap;
}
th:first-child { text-align: start; }
td { padding: .55rem .5rem; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
tr:last-child td { border-bottom: 0; }
.name { font-size: var(--text-lg); }
.action-col { text-align: center; }
.select-all { display: flex; justify-content: center; margin-top: .3rem; }

.actions { display: flex; align-items: center; gap: var(--space-3); margin-top: var(--space-4); }
.dirty-hint { margin: 0; }
</style>
