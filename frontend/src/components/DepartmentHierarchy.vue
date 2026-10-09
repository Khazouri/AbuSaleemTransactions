<script setup>
/**
 * Department-first view of the staff roster — the Users screen's «Hierarchy»
 * view. The department tree on one side, the selected department's head and
 * staff on the other.
 *
 * Edit / activate / delete / add are emitted to UsersView so there is still one
 * form and one set of handlers for an account. The two actions only this view
 * offers — moving someone to another department and making them its head — are
 * partial PUTs against the existing endpoints (both Update requests accept a
 * single field), so the API gained no route for them.
 *
 * Moving is drag-and-drop onto a tree node, with a «move to» select on each row
 * as the keyboard and touch path: native HTML5 drag events never fire on touch.
 */
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../lib/api'
import AppIcon from './AppIcon.vue'
import { flattenDepartments } from '../lib/departmentTree'
import { useAuthStore } from '../stores/auth'

const props = defineProps({
  users: { type: Array, required: true },
  departments: { type: Array, required: true },
})
const emit = defineEmits(['edit', 'add', 'toggle', 'remove', 'changed'])

const { t, locale } = useI18n()
const auth = useAuthStore()

/** Stands in for "no department" wherever a department id is expected. */
const NONE = 'none'

const rows = computed(() => flattenDepartments(props.departments))
const selectedKey = ref(null)
const collapsed = ref(new Set())
const busy = ref(false)
const error = ref(null)

/** Which user is being dragged, and which tree node it is hovering over. */
const draggingId = ref(null)
const dropKey = ref(null)

const canMove = computed(() => auth.can('users', 'edit'))
const canMakeHead = computed(() => auth.can('departments', 'edit'))

function deptLabel(dept) {
  return locale.value === 'ar' ? dept.name_ar || dept.name_en : dept.name_en || dept.name_ar
}

function keyOf(user) {
  return user.department?.id ?? NONE
}

/** Staff per department key, counted from the user list itself so a move is
 *  reflected the moment the list reloads. */
const staffByKey = computed(() => {
  const map = new Map()
  for (const user of props.users) {
    const key = keyOf(user)
    if (!map.has(key)) map.set(key, [])
    map.get(key).push(user)
  }
  return map
})

/** Tree rows minus those under a collapsed ancestor. */
const visibleRows = computed(() => {
  const out = []
  let hideBelow = Infinity
  for (const row of rows.value) {
    if (row.depth > hideBelow) continue
    hideBelow = collapsed.value.has(row.id) ? row.depth : Infinity
    out.push(row)
  }
  return out
})

// Land on the first department once data arrives, and fall back to it if the
// selected one disappears (deleted elsewhere).
watch(
  rows,
  (list) => {
    const known = selectedKey.value === NONE || list.some((row) => row.id === selectedKey.value)
    if (!known) selectedKey.value = list[0]?.id ?? NONE
  },
  { immediate: true },
)

const selected = computed(() => rows.value.find((row) => row.id === selectedKey.value) ?? null)

const staff = computed(() => staffByKey.value.get(selectedKey.value) ?? [])

const head = computed(() => {
  const id = selected.value?.manager_user_id
  return id ? staff.value.find((user) => user.id === id) ?? null : null
})

const others = computed(() =>
  staff.value
    .filter((user) => user !== head.value)
    .slice()
    .sort((a, b) => Number(b.is_active) - Number(a.is_active) || a.name.localeCompare(b.name, 'ar')),
)

const subDepartments = computed(() =>
  selected.value ? rows.value.filter((row) => row.parent_id === selected.value.id) : [],
)

function select(key) {
  selectedKey.value = key
  error.value = null
}

function toggleCollapse(id) {
  const next = new Set(collapsed.value)
  next.has(id) ? next.delete(id) : next.add(id)
  collapsed.value = next
}

async function save(request) {
  busy.value = true
  error.value = null
  try {
    await request()
    emit('changed')
  } catch (e) {
    error.value = e?.response?.data?.message ?? t('users.hierarchy.saveFailed')
  } finally {
    busy.value = false
  }
}

function moveTo(user, key) {
  if (key === keyOf(user)) return
  return save(() => api.put(`/users/${user.id}`, { department_id: key === NONE ? null : key }))
}

function makeHead(user) {
  return save(() => api.put(`/departments/${selected.value.id}`, { manager_user_id: user.id }))
}

function onDragStart(event, user) {
  draggingId.value = user.id
  event.dataTransfer.effectAllowed = 'move'
  // Firefox refuses to start a drag with no data set.
  event.dataTransfer.setData('text/plain', String(user.id))
}

function onDragEnd() {
  draggingId.value = null
  dropKey.value = null
}

function onDrop(key) {
  const user = props.users.find((u) => u.id === draggingId.value)
  onDragEnd()
  if (user) moveTo(user, key)
}

function onMoveSelect(event, user) {
  const value = event.target.value
  event.target.value = ''
  if (value !== '') moveTo(user, value === NONE ? NONE : Number(value))
}
</script>

<template>
  <div class="hierarchy" :class="{ busy }">
    <!-- Department tree: also the drop targets. -->
    <nav class="tree card card-flat" :aria-label="t('departments.title')">
      <ul role="list">
        <li
          v-for="row in visibleRows"
          :key="row.id"
          class="node"
          :class="{ selected: row.id === selectedKey, dropping: dropKey === row.id, inactive: !row.is_active }"
          :style="{ '--depth': row.depth }"
          @dragover.prevent="draggingId && (dropKey = row.id)"
          @dragleave="dropKey === row.id && (dropKey = null)"
          @drop.prevent="onDrop(row.id)"
        >
          <button
            v-if="row.children_count"
            type="button"
            class="twisty"
            :class="{ shut: collapsed.has(row.id) }"
            :aria-expanded="!collapsed.has(row.id)"
            :aria-label="collapsed.has(row.id) ? t('users.hierarchy.expand') : t('users.hierarchy.collapse')"
            @click="toggleCollapse(row.id)"
          >
            <AppIcon name="chevron-down" :size="14" />
          </button>
          <span v-else class="twisty" aria-hidden="true"></span>
          <button
            type="button"
            class="node-name"
            :aria-current="row.id === selectedKey ? 'true' : undefined"
            @click="select(row.id)"
          >
            <span class="label">{{ deptLabel(row) }}</span>
            <span class="count">{{ staffByKey.get(row.id)?.length ?? 0 }}</span>
          </button>
        </li>
        <li
          class="node unassigned"
          :class="{ selected: selectedKey === NONE, dropping: dropKey === NONE }"
          @dragover.prevent="draggingId && (dropKey = NONE)"
          @dragleave="dropKey === NONE && (dropKey = null)"
          @drop.prevent="onDrop(NONE)"
        >
          <span class="twisty" aria-hidden="true"></span>
          <button type="button" class="node-name" :aria-current="selectedKey === NONE ? 'true' : undefined" @click="select(NONE)">
            <span class="label">{{ t('users.noDepartment') }}</span>
            <span class="count">{{ staffByKey.get(NONE)?.length ?? 0 }}</span>
          </button>
        </li>
      </ul>
      <p v-if="canMove" class="drag-hint">{{ t('users.hierarchy.dragHint') }}</p>
    </nav>

    <!-- Narrow screens: the tree becomes a picker above the panel. -->
    <label class="picker">
      <span class="sr-only">{{ t('departments.title') }}</span>
      <select :value="selectedKey" @change="select($event.target.value === NONE ? NONE : Number($event.target.value))">
        <option v-for="row in rows" :key="row.id" :value="row.id">{{ '— '.repeat(row.depth) }}{{ deptLabel(row) }}</option>
        <option :value="NONE">{{ t('users.noDepartment') }}</option>
      </select>
    </label>

    <section class="panel card card-flat card-pad">
      <header class="panel-head">
        <h3>{{ selected ? deptLabel(selected) : t('users.noDepartment') }}</h3>
        <button v-can="'users.add'" class="primary" type="button" @click="emit('add', selected?.id ?? null)">
          {{ t('users.hierarchy.addHere') }}
        </button>
      </header>

      <p v-if="error" class="alert" role="alert">{{ error }}</p>

      <!-- The head: the one emphasised element of this view. -->
      <div v-if="selected" class="head" :class="{ empty: !head }">
        <p class="head-label">{{ t('users.hierarchy.head') }}</p>
        <template v-if="head">
          <p class="head-name">{{ head.name }}</p>
          <p class="email ltr">{{ head.email }}</p>
          <div class="person-actions">
            <button v-can="'users.edit'" class="ghost" type="button" @click="emit('edit', head)">{{ t('common.edit') }}</button>
          </div>
        </template>
        <p v-else class="muted">{{ t('users.hierarchy.noHead') }}</p>
      </div>

      <p v-if="staff.length === 0" class="state">{{ t('users.hierarchy.empty') }}</p>

      <ul v-else-if="others.length" class="people" role="list">
        <li
          v-for="user in others"
          :key="user.id"
          class="person"
          :class="{ dimmed: !user.is_active, dragging: draggingId === user.id }"
          :draggable="canMove"
          @dragstart="onDragStart($event, user)"
          @dragend="onDragEnd"
        >
          <AppIcon v-if="canMove" name="grip-vertical" :size="16" class="grip" aria-hidden="true" />
          <div class="who">
            <span class="name">{{ user.name }}</span>
            <span v-if="!user.is_active" class="pill">{{ t('common.inactive') }}</span>
            <span class="email ltr">{{ user.email }}</span>
            <span v-if="user.manager" class="reports">{{ t('users.hierarchy.reportsTo', { name: user.manager.name }) }}</span>
          </div>
          <div class="roles">
            <span v-for="role in user.roles ?? []" :key="role.id" class="badge">{{ role.code }}</span>
          </div>
          <div class="person-actions">
            <button
              v-if="selected && canMakeHead && user.is_active"
              class="ghost"
              type="button"
              @click="makeHead(user)"
            >
              {{ t('users.hierarchy.makeHead') }}
            </button>
            <select v-if="canMove" class="move" :aria-label="t('users.hierarchy.moveTo')" @change="onMoveSelect($event, user)">
              <option value="">{{ t('users.hierarchy.moveTo') }}</option>
              <option v-for="row in rows" :key="row.id" :value="row.id" :disabled="row.id === selectedKey">
                {{ '— '.repeat(row.depth) }}{{ deptLabel(row) }}
              </option>
              <option :value="NONE" :disabled="selectedKey === NONE">{{ t('users.noDepartment') }}</option>
            </select>
            <button v-can="'users.edit'" class="ghost" type="button" @click="emit('edit', user)">{{ t('common.edit') }}</button>
            <button
              v-can="'users.edit'"
              class="ghost"
              type="button"
              :disabled="user.id === auth.user?.id"
              :title="user.id === auth.user?.id ? t('users.selfActionBlocked') : ''"
              @click="emit('toggle', user)"
            >
              {{ user.is_active ? t('common.deactivate') : t('common.activate') }}
            </button>
            <button
              v-can="'users.delete'"
              class="ghost danger"
              type="button"
              :disabled="user.id === auth.user?.id"
              :title="user.id === auth.user?.id ? t('users.selfActionBlocked') : ''"
              @click="emit('remove', user)"
            >
              {{ t('common.delete') }}
            </button>
          </div>
        </li>
      </ul>

      <nav v-if="subDepartments.length" class="subs" :aria-label="t('users.hierarchy.subDepartments')">
        <p class="subs-label">{{ t('users.hierarchy.subDepartments') }}</p>
        <button v-for="row in subDepartments" :key="row.id" type="button" class="chip" @click="select(row.id)">
          {{ deptLabel(row) }}
          <span class="count">{{ staffByKey.get(row.id)?.length ?? 0 }}</span>
        </button>
      </nav>
    </section>
  </div>
</template>

<style scoped>
.hierarchy {
  display: grid;
  grid-template-columns: minmax(14rem, 18rem) minmax(0, 1fr);
  gap: var(--space-4);
  align-items: start;
}
.hierarchy.busy { cursor: progress; }

/* -- Tree ---------------------------------------------------------------- */
.tree { padding: var(--space-2); position: sticky; top: var(--space-4); }
.tree ul { list-style: none; margin: 0; padding: 0; }
.node {
  display: flex;
  align-items: center;
  gap: var(--space-1);
  /* Depth indents from the inline-start edge, so the tree mirrors in RTL. */
  padding-inline-start: calc(var(--depth, 0) * 1.1rem);
  border-radius: var(--radius-md);
  border: 1px dashed transparent;
}
.node.unassigned { margin-top: var(--space-2); border-top: 1px solid var(--color-border); border-radius: 0; padding-top: var(--space-2); }
.node.inactive .label { color: var(--color-black-400); }
.node.selected { background: var(--color-surface-hover); }
.node.selected .node-name { color: var(--color-brand-text); font-weight: 600; }
.node.dropping { border-color: var(--color-brand-text); background: var(--color-success-bg); }

.twisty {
  flex: none;
  display: inline-grid;
  place-items: center;
  width: 1.5rem;
  height: 1.5rem;
  padding: 0;
  border: 0;
  background: none;
  color: var(--color-muted);
  cursor: pointer;
}
.twisty.shut :deep(svg) { transform: rotate(-90deg); }
:root[dir='rtl'] .twisty.shut :deep(svg) { transform: rotate(90deg); }
.node-name {
  flex: 1;
  min-width: 0;
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: var(--space-2);
  padding: .45rem .5rem;
  border: 0;
  background: none;
  color: var(--color-foreground);
  font: inherit;
  text-align: start;
  cursor: pointer;
}
.twisty:focus-visible, .node-name:focus-visible, .chip:focus-visible {
  outline: 2px solid var(--color-brand-text);
  outline-offset: -2px;
  border-radius: var(--radius-md);
}
.label { overflow-wrap: anywhere; }
.count { color: var(--color-muted); font-size: var(--text-sm); font-variant-numeric: tabular-nums; }
.drag-hint { margin: var(--space-3) var(--space-2) var(--space-1); color: var(--color-muted); font-size: var(--text-xs); }

.picker { display: none; }
.picker select { width: 100%; }
.sr-only { position: absolute; inline-size: 1px; block-size: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }

/* -- Panel --------------------------------------------------------------- */
.panel-head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: var(--space-3);
  margin-bottom: var(--space-4);
}
.panel-head h3 { margin: 0; font-size: var(--text-xl); }

.head {
  margin-bottom: var(--space-4);
  padding: var(--space-4) var(--space-5);
  border-inline-start: 4px solid var(--color-brand);
  border-radius: var(--radius-lg);
  background: var(--color-surface-hover);
}
.head.empty { border-inline-start-color: var(--color-border-hover); }
.head p { margin: 0; }
.head-label { color: var(--color-muted); font-size: var(--text-sm); }
.head .head-name { font-size: var(--text-xl); font-weight: 600; margin-top: var(--space-1); }
.head .person-actions { margin-top: var(--space-2); justify-content: flex-start; }

.people { list-style: none; margin: 0; padding: 0; }
.person {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  grid-template-areas: 'grip who roles' 'grip actions actions';
  column-gap: var(--space-3);
  row-gap: var(--space-2);
  align-items: center;
  padding: var(--space-3) 0;
  border-top: 1px solid var(--color-border);
}
.person[draggable='true'] { cursor: grab; }
.person.dragging { opacity: .4; }
.person.dimmed .who { opacity: .55; }
.grip { grid-area: grip; color: var(--color-black-400); }
.who { grid-area: who; display: flex; flex-direction: column; gap: .1rem; min-width: 0; }
.roles { grid-area: roles; display: flex; flex-wrap: wrap; gap: .3rem; justify-content: flex-end; }
.person > .person-actions { grid-area: actions; }
.person-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; align-items: center; gap: var(--space-2); }

.name { font-size: var(--text-lg); font-weight: 500; overflow-wrap: anywhere; }
.email { color: var(--color-muted); font-size: var(--text-sm); overflow-wrap: anywhere; }
.reports { color: var(--color-black-600); font-size: var(--text-sm); }
.muted { color: var(--color-muted); }
.badge {
  padding: .1rem .45rem;
  background: var(--color-surface);
  color: var(--color-black-700);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-full);
  font-size: var(--text-xs);
  direction: ltr;
}
.move { max-width: 12rem; font-size: var(--text-sm); }

.subs { margin-top: var(--space-5); display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
.subs-label { margin: 0; margin-inline-end: var(--space-2); color: var(--color-muted); font-size: var(--text-sm); }
.chip {
  display: inline-flex;
  gap: var(--space-2);
  padding: .3rem .7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-full);
  background: var(--color-surface);
  color: var(--color-foreground);
  font: inherit;
  cursor: pointer;
}
.chip:hover { border-color: var(--color-brand-text); }

@media (max-width: 767px) {
  .hierarchy { grid-template-columns: minmax(0, 1fr); }
  .tree { display: none; }
  .picker { display: block; }
}
/* Touch screens never fire HTML5 drag events; the «move to» select is the path there. */
@media (hover: none) {
  .grip { display: none; }
  .person[draggable='true'] { cursor: auto; }
}
@media (prefers-reduced-motion: no-preference) {
  .twisty :deep(svg) { transition: transform .15s; }
}
</style>
