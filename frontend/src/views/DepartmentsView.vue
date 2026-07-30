<script setup>
/**
 * Departments screen (الإدارات والأقسام) — Stage 6.
 *
 * Manages the municipality's org tree: list as a hierarchy, create, edit,
 * move between parents, activate/deactivate, and delete.
 *
 * The API returns a FLAT list; the tree is assembled here (see `tree` below),
 * which keeps the same data usable for both the hierarchy display and the
 * "parent department" dropdown.
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'

const { t, locale } = useI18n()

const departments = ref([])
const loading = ref(false)
const saving = ref(false)
const loadError = ref(null)

/** Field-level validation errors from Laravel, keyed by field name. */
const errors = ref({})
/** Single message for failures that aren't field-specific (e.g. delete blocked). */
const formError = ref(null)

/** The department being edited, or null when creating a new one. */
const editingId = ref(null)
const showForm = ref(false)

const blankForm = () => ({
  name_ar: '',
  name_en: '',
  code: '',
  parent_id: null,
  is_active: true,
})
const form = ref(blankForm())

/** Display a department's name in the active language. */
function label(dept) {
  return locale.value === 'ar'
    ? dept.name_ar || dept.name_en
    : dept.name_en || dept.name_ar
}

/**
 * Flatten the department list into display order, carrying a depth for
 * indentation.
 *
 * Walks from the roots down, so each department appears directly beneath its
 * parent. Built iteratively with an explicit stack rather than recursion —
 * and note that any department whose parent is missing is treated as a root,
 * so a broken parent link can never hide a row from the screen entirely.
 */
const tree = computed(() => {
  const byParent = new Map()
  for (const dept of departments.value) {
    const key = dept.parent_id ?? null
    if (!byParent.has(key)) byParent.set(key, [])
    byParent.get(key).push(dept)
  }

  const knownIds = new Set(departments.value.map((d) => d.id))
  // Roots: no parent, or a parent that isn't in the list (orphan safety net).
  const roots = departments.value.filter(
    (d) => d.parent_id === null || !knownIds.has(d.parent_id),
  )

  const rows = []
  // Reverse so that popping off the stack preserves the original order.
  const stack = roots.slice().reverse().map((d) => ({ dept: d, depth: 0 }))

  while (stack.length) {
    const { dept, depth } = stack.pop()
    rows.push({ ...dept, depth })

    const children = byParent.get(dept.id) ?? []
    for (let i = children.length - 1; i >= 0; i--) {
      stack.push({ dept: children[i], depth: depth + 1 })
    }
  }

  return rows
})

/**
 * Options for the "parent department" dropdown.
 *
 * When editing, the department itself and everything beneath it are removed:
 * choosing one would create a cycle. The API rejects that too, but filtering
 * here means the invalid option is never offered in the first place.
 */
const parentOptions = computed(() => {
  if (editingId.value === null) return tree.value

  const excluded = new Set([editingId.value])
  // tree order guarantees a parent is seen before its children, so one pass
  // is enough to collect the whole subtree.
  for (const row of tree.value) {
    if (row.parent_id !== null && excluded.has(row.parent_id)) {
      excluded.add(row.id)
    }
  }
  return tree.value.filter((row) => !excluded.has(row.id))
})

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const { data } = await api.get('/departments')
    departments.value = data.data ?? data
  } catch (e) {
    loadError.value = e
  } finally {
    loading.value = false
  }
}

function startCreate() {
  editingId.value = null
  form.value = blankForm()
  errors.value = {}
  formError.value = null
  showForm.value = true
}

function startEdit(dept) {
  editingId.value = dept.id
  // Copy the fields rather than binding the row directly, so cancelling
  // doesn't leave half-typed edits in the table.
  form.value = {
    name_ar: dept.name_ar ?? '',
    name_en: dept.name_en ?? '',
    code: dept.code ?? '',
    parent_id: dept.parent_id,
    is_active: dept.is_active,
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

  // Send empty optional text as null so the column stores NULL rather than an
  // empty string — otherwise two departments with "" codes would collide on
  // the unique index.
  const payload = {
    ...form.value,
    name_en: form.value.name_en || null,
    code: form.value.code || null,
  }

  try {
    if (editingId.value === null) {
      await api.post('/departments', payload)
    } else {
      await api.put(`/departments/${editingId.value}`, payload)
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

async function toggleActive(dept) {
  formError.value = null
  try {
    await api.patch(`/departments/${dept.id}/toggle-active`)
    await load()
  } catch {
    formError.value = 'تعذّر تغيير الحالة.'
  }
}

async function remove(dept) {
  if (!window.confirm(t('common.confirmDelete'))) return

  formError.value = null
  try {
    await api.delete(`/departments/${dept.id}`)
    await load()
  } catch (e) {
    // The API returns 422 with an explanatory message when the department
    // still has children or staff — show exactly that, since it tells the
    // user what to fix.
    formError.value = e?.response?.data?.message ?? 'تعذّر الحذف.'
  }
}

onMounted(load)
</script>

<template>
  <section>
    <div class="toolbar">
      <button v-can="'departments.add'" class="primary" @click="startCreate">+ {{ t('departments.add') }}</button>
    </div>

    <p v-if="formError" class="alert">{{ formError }}</p>

    <!-- Create / edit form -->
    <form v-if="showForm" class="card form" @submit.prevent="save">
      <h3>{{ editingId === null ? t('departments.add') : t('departments.edit') }}</h3>

      <div class="grid">
        <label>
          {{ t('departments.nameAr') }} *
          <input v-model="form.name_ar" type="text" required />
          <small v-if="errors.name_ar" class="field-error">{{ errors.name_ar[0] }}</small>
        </label>

        <label>
          {{ t('departments.nameEn') }}
          <input v-model="form.name_en" type="text" dir="ltr" />
          <small v-if="errors.name_en" class="field-error">{{ errors.name_en[0] }}</small>
        </label>

        <label>
          {{ t('departments.code') }}
          <input v-model="form.code" type="text" dir="ltr" />
          <small class="hint">{{ t('departments.codeHint') }}</small>
          <small v-if="errors.code" class="field-error">{{ errors.code[0] }}</small>
        </label>

        <label>
          {{ t('departments.parent') }}
          <select v-model="form.parent_id">
            <option :value="null">{{ t('departments.root') }}</option>
            <option v-for="opt in parentOptions" :key="opt.id" :value="opt.id">
              {{ '— '.repeat(opt.depth) }}{{ label(opt) }}
            </option>
          </select>
          <small v-if="errors.parent_id" class="field-error">{{ errors.parent_id[0] }}</small>
        </label>
      </div>

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

    <!-- Tree -->
    <div class="card">
      <p v-if="loading" class="state">{{ t('common.loading') }}</p>
      <p v-else-if="loadError" class="state error">
        {{ t('nav.error') }}
        <button class="ghost" @click="load">{{ t('common.retry') }}</button>
      </p>
      <p v-else-if="tree.length === 0" class="state">{{ t('departments.empty') }}</p>

      <table v-else>
        <tbody>
          <tr v-for="dept in tree" :key="dept.id" :class="{ dimmed: !dept.is_active }">
            <td>
              <!-- Indentation communicates depth. Padding is applied to the
                   INLINE-START edge so it mirrors correctly in LTR. -->
              <span
                class="name"
                :style="{ paddingInlineStart: `${dept.depth * 1.5}rem` }"
              >
                <span v-if="dept.depth > 0" class="branch">└</span>
                {{ label(dept) }}
              </span>
              <code v-if="dept.code" class="code">{{ dept.code }}</code>
              <span v-if="!dept.is_active" class="pill">{{ t('common.inactive') }}</span>
            </td>

            <td class="meta">
              <span v-if="dept.children_count">{{ dept.children_count }} {{ t('departments.children') }}</span>
              <span v-if="dept.users_count">{{ dept.users_count }} {{ t('departments.users') }}</span>
            </td>

            <td class="row-actions">
              <button v-can="'departments.edit'" class="ghost" @click="startEdit(dept)">{{ t('common.edit') }}</button>
              <button v-can="'departments.edit'" class="ghost" @click="toggleActive(dept)">
                {{ dept.is_active ? t('common.deactivate') : t('common.activate') }}
              </button>
              <button v-can="'departments.delete'" class="ghost danger" @click="remove(dept)">{{ t('common.delete') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.toolbar { margin-bottom: 1rem; }
.card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 1.25rem;
  margin-bottom: 1rem;
}
.form h3 { margin: 0 0 1rem; font-size: 1rem; color: #0f5132; }
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 1rem;
}
label { display: flex; flex-direction: column; gap: .3rem; font-size: .875rem; color: #374151; }
label.checkbox { flex-direction: row; align-items: center; gap: .5rem; margin-top: 1rem; }
input[type='text'], select {
  padding: .5rem .6rem;
  border: 1px solid #d1d5db;
  border-radius: 8px;
}
input:focus, select:focus { outline: 2px solid #0f5132; outline-offset: 1px; }
.hint { color: #9ca3af; font-size: .75rem; }
.field-error { color: #b91c1c; font-size: .78rem; }
.actions { display: flex; gap: .5rem; margin-top: 1.25rem; }

table { width: 100%; border-collapse: collapse; }
td { padding: .55rem .5rem; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
tr:last-child td { border-bottom: 0; }
tr.dimmed { opacity: .55; }
.name { font-size: .9rem; }
.branch { color: #9ca3af; margin-inline-end: .3rem; }
.code {
  margin-inline-start: .5rem;
  padding: .1rem .4rem;
  background: #f3f4f6;
  border-radius: 4px;
  font-size: .75rem;
  direction: ltr;
  display: inline-block;
}
.pill {
  margin-inline-start: .5rem;
  padding: .1rem .5rem;
  background: #f3f4f6;
  color: #6b7280;
  border-radius: 999px;
  font-size: .72rem;
}
.meta { color: #9ca3af; font-size: .78rem; white-space: nowrap; }
.meta span { margin-inline-end: .75rem; }
.row-actions { text-align: end; white-space: nowrap; }
.state { padding: .5rem; color: #6b7280; font-size: .9rem; margin: 0; }
.state.error { color: #b91c1c; }
.alert {
  padding: .65rem .8rem;
  background: #fef2f2;
  color: #b91c1c;
  border: 1px solid #fecaca;
  border-radius: 8px;
  font-size: .875rem;
  margin: 0 0 1rem;
}
button { cursor: pointer; border-radius: 8px; font-size: .85rem; }
.primary { padding: .5rem .9rem; border: 0; background: #0f5132; color: #fff; }
.ghost { padding: .35rem .6rem; border: 1px solid #d1d5db; background: #fff; margin-inline-start: .3rem; }
.ghost:hover { background: #f9fafb; }
.ghost.danger { color: #b91c1c; border-color: #fecaca; }
</style>
