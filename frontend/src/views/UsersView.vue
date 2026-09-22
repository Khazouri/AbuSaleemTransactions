<script setup>
/**
 * Users screen (المستخدمون) — Stage 7.
 *
 * CRUD for accounts: create, edit (including reassigning department and
 * roles), activate/deactivate, and delete. Departments and roles are fetched
 * alongside the user list purely to populate the form's dropdown/checkboxes —
 * neither is editable from here (Stage 6 and Stage 8 own that, respectively).
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import { useAuthStore } from '../stores/auth'

const { t, locale } = useI18n()
const auth = useAuthStore()

const users = ref([])
const departments = ref([])
const roles = ref([])
const loading = ref(false)
const saving = ref(false)
const loadError = ref(null)

/** Field-level validation errors from Laravel, keyed by field name. */
const errors = ref({})
/** Single message for failures that aren't field-specific (e.g. self-action blocked). */
const formError = ref(null)

/** The user being edited, or null when creating a new one. */
const editingId = ref(null)
const showForm = ref(false)

const blankForm = () => ({
  name: '',
  email: '',
  password: '',
  department_id: null,
  manager_id: null,
  role_ids: [],
  is_active: true,
})
const form = ref(blankForm())

/** Display a department's name in the active language. */
function deptLabel(dept) {
  return locale.value === 'ar'
    ? dept.name_ar || dept.name_en
    : dept.name_en || dept.name_ar
}

/** Display a role's name in the active language. */
function roleLabel(role) {
  return locale.value === 'ar'
    ? role.name_ar || role.name_en
    : role.name_en || role.name_ar
}

/** Departments sorted for the dropdown; inactive ones stay selectable so an
 *  already-assigned user doesn't lose their department out from under them. */
const departmentOptions = computed(() =>
  departments.value.slice().sort((a, b) => deptLabel(a).localeCompare(deptLabel(b), 'ar')),
)

/**
 * Manager picker options: active users only, excluding whoever is currently
 * being edited — a user can't be their own manager, and offering themselves
 * in the list would let someone create that loop from the UI even though
 * nothing downstream (WorkflowService's actor check) can safely resolve it.
 */
const managerOptions = computed(() =>
  users.value
    .filter((user) => user.is_active && user.id !== editingId.value)
    .slice()
    .sort((a, b) => a.name.localeCompare(b.name, 'ar')),
)

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const { data } = await api.get('/users')
    users.value = data.data ?? data
  } catch (e) {
    loadError.value = e
  } finally {
    loading.value = false
  }
}

async function loadRefs() {
  const [deptRes, roleRes] = await Promise.all([api.get('/departments'), api.get('/roles')])
  departments.value = deptRes.data.data ?? deptRes.data
  roles.value = roleRes.data.data ?? roleRes.data
}

function startCreate() {
  editingId.value = null
  form.value = blankForm()
  errors.value = {}
  formError.value = null
  showForm.value = true
}

function startEdit(user) {
  editingId.value = user.id
  // Copy the fields rather than binding the row directly, so cancelling
  // doesn't leave half-typed edits in the table.
  form.value = {
    name: user.name ?? '',
    email: user.email ?? '',
    password: '',
    department_id: user.department?.id ?? null,
    manager_id: user.manager?.id ?? null,
    role_ids: (user.roles ?? []).map((role) => role.id),
    is_active: user.is_active,
  }
  errors.value = {}
  formError.value = null
  showForm.value = true
}

function cancelForm() {
  showForm.value = false
  editingId.value = null
  errors.value = {}
  formError.value = null
}

async function save() {
  saving.value = true
  errors.value = {}
  formError.value = null

  const payload = {
    name: form.value.name,
    email: form.value.email,
    department_id: form.value.department_id,
    manager_id: form.value.manager_id,
    role_ids: form.value.role_ids,
    is_active: form.value.is_active,
  }
  // Blank means "keep the current password" — omit it entirely rather than
  // sending an empty string, which would fail the backend's min:8 rule.
  if (form.value.password) payload.password = form.value.password

  try {
    if (editingId.value === null) {
      await api.post('/users', payload)
    } else {
      await api.put(`/users/${editingId.value}`, payload)
    }
    cancelForm()
    await load()
  } catch (e) {
    if (e?.response?.status === 422) {
      errors.value = e.response.data.errors ?? {}
      formError.value = e.response.data.message ?? null
    } else {
      formError.value = 'تعذّر الحفظ. حاول مرة أخرى.'
    }
  } finally {
    saving.value = false
  }
}

async function toggleActive(user) {
  formError.value = null
  try {
    await api.patch(`/users/${user.id}/toggle-active`)
    await load()
  } catch (e) {
    formError.value = e?.response?.data?.message ?? 'تعذّر تغيير الحالة.'
  }
}

async function remove(user) {
  if (!window.confirm(t('common.confirmDelete'))) return

  formError.value = null
  try {
    await api.delete(`/users/${user.id}`)
    await load()
  } catch (e) {
    formError.value = e?.response?.data?.message ?? 'تعذّر الحذف.'
  }
}

onMounted(() => {
  load()
  loadRefs()
})
</script>

<template>
  <section class="page">
    <div class="heading">
      <div>
        <h2>{{ t('users.title') }}</h2>
        <p v-if="!loading && !loadError" class="subtitle">{{ users.length }}</p>
      </div>
      <button v-can="'users.add'" class="primary" type="button" @click="startCreate">{{ t('users.add') }}</button>
    </div>

    <p v-if="formError" class="alert">{{ formError }}</p>

    <!-- Create / edit form -->
    <form v-if="showForm" class="card card-flat card-pad form" @submit.prevent="save">
      <h3>{{ editingId === null ? t('users.add') : t('users.edit') }}</h3>

      <div class="grid">
        <label>
          {{ t('users.name') }} *
          <input v-model="form.name" type="text" required />
          <small v-if="errors.name" class="field-error">{{ errors.name[0] }}</small>
        </label>

        <label>
          {{ t('users.email') }} *
          <input v-model="form.email" type="email" dir="ltr" required />
          <small v-if="errors.email" class="field-error">{{ errors.email[0] }}</small>
        </label>

        <label>
          {{ t('users.password') }}{{ editingId === null ? ' *' : '' }}
          <input
            v-model="form.password"
            type="password"
            dir="ltr"
            autocomplete="new-password"
            :required="editingId === null"
          />
          <small v-if="editingId !== null" class="hint">{{ t('users.passwordHint') }}</small>
          <small v-if="errors.password" class="field-error">{{ errors.password[0] }}</small>
        </label>

        <label>
          {{ t('users.department') }}
          <select v-model="form.department_id">
            <option :value="null">{{ t('users.noDepartment') }}</option>
            <option v-for="dept in departmentOptions" :key="dept.id" :value="dept.id">
              {{ deptLabel(dept) }}
            </option>
          </select>
          <small v-if="errors.department_id" class="field-error">{{ errors.department_id[0] }}</small>
        </label>

        <label>
          {{ t('users.manager') }}
          <select v-model="form.manager_id">
            <option :value="null">{{ t('users.noManager') }}</option>
            <option v-for="manager in managerOptions" :key="manager.id" :value="manager.id">
              {{ manager.name }}
            </option>
          </select>
          <small v-if="errors.manager_id" class="field-error">{{ errors.manager_id[0] }}</small>
          <small v-else class="hint">{{ t('users.managerHint') }}</small>
        </label>
      </div>

      <fieldset class="roles-field">
        <legend>{{ t('users.roles') }}</legend>
        <label v-for="role in roles" :key="role.id" class="checkbox role-option">
          <input v-model="form.role_ids" type="checkbox" :value="role.id" />
          {{ role.code }} — {{ roleLabel(role) }}
        </label>
      </fieldset>

      <label class="checkbox">
        <input v-model="form.is_active" type="checkbox" />
        {{ t('common.active') }}
      </label>

      <div class="actions">
        <button class="primary" type="submit" :disabled="saving">
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
        <button class="ghost" type="button" @click="cancelForm">{{ t('common.cancel') }}</button>
      </div>
    </form>

    <!-- List -->
    <div class="card card-flat card-pad">
      <p v-if="loading" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="loadError" class="alert">
        {{ t('nav.error') }}
        <button class="ghost" @click="load">{{ t('common.retry') }}</button>
      </p>
      <p v-else-if="users.length === 0" class="state">{{ t('users.empty') }}</p>

      <div v-else class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th scope="col">{{ t('users.name') }}</th>
            <th scope="col">{{ t('users.department') }}</th>
            <th scope="col">{{ t('users.roles') }}</th>
            <th scope="col"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="user in users" :key="user.id" :class="{ dimmed: !user.is_active }">
            <td>
              <span class="name">{{ user.name }}</span>
              <span v-if="!user.is_active" class="pill">{{ t('common.inactive') }}</span>
              <br />
              <span class="email ltr">{{ user.email }}</span>
            </td>

            <td class="meta">
              <span v-if="user.department">{{ deptLabel(user.department) }}</span>
              <span v-else class="muted">{{ t('users.noDepartment') }}</span>
            </td>

            <td class="meta">
              <span v-for="role in user.roles ?? []" :key="role.id" class="badge">{{ role.code }}</span>
            </td>

            <td class="row-actions">
              <button v-can="'users.edit'" class="ghost" @click="startEdit(user)">{{ t('common.edit') }}</button>
              <button
                v-can="'users.edit'"
                class="ghost"
                :disabled="user.id === auth.user?.id"
                :title="user.id === auth.user?.id ? t('users.selfActionBlocked') : ''"
                @click="toggleActive(user)"
              >
                {{ user.is_active ? t('common.deactivate') : t('common.activate') }}
              </button>
              <button
                v-can="'users.delete'"
                class="ghost danger"
                :disabled="user.id === auth.user?.id"
                :title="user.id === auth.user?.id ? t('users.selfActionBlocked') : ''"
                @click="remove(user)"
              >
                {{ t('common.delete') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>
  </section>
</template>

<style scoped>
.form { margin-bottom: var(--space-4); }
.form h3 { margin: 0 0 var(--space-4); font-size: var(--text-lg); color: var(--color-brand-text); }
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr));
  gap: var(--space-4);
}
label {
  display: flex;
  flex-direction: column;
  gap: .3rem;
  font-size: var(--text-base);
  color: var(--color-black-700);
}
label.checkbox { flex-direction: row; align-items: center; gap: var(--space-2); margin-top: var(--space-4); }
input[type='text'], input[type='email'], input[type='password'], select {
  padding: .5rem .6rem;
  border: 1px solid var(--color-border-hover);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
  color: var(--color-foreground);
}
input:focus, select:focus { outline: 2px solid var(--color-brand-text); outline-offset: 1px; }
.field-error { color: var(--color-danger-fg); font-size: var(--text-sm); }

.roles-field {
  margin-top: var(--space-4);
  padding: .75rem .9rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}
.roles-field legend {
  padding: 0 .4rem;
  font-size: var(--text-sm);
  color: var(--color-muted);
}
.role-option {
  display: inline-flex;
  align-items: center;
  gap: .4rem;
  margin: .25rem .9rem .25rem 0;
  font-size: var(--text-base);
}

.actions { margin-top: var(--space-5); }

tr.dimmed { opacity: .55; }
.name { font-size: var(--text-lg); font-weight: 500; }
.email { color: var(--color-muted); font-size: var(--text-sm); }
.badge {
  display: inline-block;
  margin-inline-end: .3rem;
  padding: .1rem .45rem;
  background: var(--color-surface-hover);
  color: var(--color-black-700);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-full);
  font-size: var(--text-xs);
  direction: ltr;
}
.meta { color: var(--color-muted); font-size: var(--text-sm); }
.muted { color: var(--color-black-400); }
.row-actions { display: flex; justify-content: flex-end; gap: var(--space-2); white-space: nowrap; }
</style>
