<script setup>
/**
 * Sidebar navigation — dark nav rail, ported from the SCCO template.
 *
 * Entries come from the `screens` table via the API — nothing here is
 * hard-coded, so adding a screen to the database adds it to the menu. Each row
 * carries no icon of its own, so `iconFor` maps a screen's stable `code` to a
 * glyph from AppIcon, falling back to a neutral dot for anything unmapped.
 *
 * Two display states, both driven by props from the layout:
 *   collapsed  desktop rail — icons only, labels hidden (width handled in CSS)
 *   mobileOpen off-canvas drawer slid into view on small screens
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useScreensStore } from '../stores/screens'
import AppIcon from './AppIcon.vue'

const props = defineProps({
  /** Desktop rail mode: show icons only, hide text labels. */
  collapsed: { type: Boolean, default: false },
  /** Small-screen flag: switches the sidebar to a fixed off-canvas drawer. */
  isMobile: { type: Boolean, default: false },
  /** When mobile, whether the drawer is currently slid into view. */
  mobileOpen: { type: Boolean, default: false },
})

const screensStore = useScreensStore()
const { t, locale } = useI18n()

// Load the menu when the shell first mounts. The store caches it, so
// navigating between screens doesn't re-request it.
onMounted(() => {
  screensStore.fetchScreens().catch(() => {
    // Swallowed on purpose: the error is already in the store and rendered
    // below. Letting it escape would surface an unhandled rejection.
  })
})

/**
 * Screen `code` → AppIcon glyph name. Codes not listed fall through to the
 * neutral dot, so a new screen still gets a sensible menu entry before anyone
 * assigns it an icon here.
 */
const ICON_BY_CODE = {
  dashboard: 'grid',
  departments: 'git-branch',
  requests: 'file-text',
  request_intake: 'file-plus',
  // Stage 89 — the employee's own view of the files they filed.
  my_tasks: 'inbox',
  request_tracking: 'compass',
  meetings: 'calendar',
  decisions: 'check-circle',
  appeals: 'flag',
  // Stage 28 — meetings management group.
  meetings_dashboard: 'gauge',
  committee_candidates: 'list-checks',
  legal_review: 'scale',
  meeting_agenda: 'clipboard-list',
  users: 'users',
  request_types: 'layers',
  workflow_designer: 'route',
  roles_permissions: 'shield',
  committee_roles: 'award',
  settings: 'settings',
  reports: 'bar-chart',
  // Stage 80 — Art. 98's official registers.
  registers: 'book',
  audit_log: 'history',
  notifications: 'bell',
  backup: 'database',
  maintenance: 'terminal',
  user_guide: 'help-circle',
}
const iconFor = (code) => ICON_BY_CODE[code] || 'dot'

/**
 * Screen label in the active language.
 *
 * The API sends both names, so switching language relabels the menu instantly
 * with no extra request. Arabic falls back to English if a translation is
 * missing, and vice versa.
 */
const label = (screen) =>
  locale.value === 'ar' ? screen.name_ar || screen.name_en : screen.name_en || screen.name_ar
const labelled = (screen) => ({ ...screen, label: label(screen) })

/**
 * Stage 28 — groups the user has explicitly collapsed. Empty by default, so
 * every group starts expanded; tracking the exception (collapsed) rather
 * than the rule (expanded) means a newly-seeded group needs no extra state.
 * Remembered per browser: it is a viewing preference, and storage may be
 * blocked (private windows), in which case the menu simply starts open.
 */
const COLLAPSED_KEY = 'sidebar.collapsedGroups'
function readCollapsed() {
  try {
    return new Set(JSON.parse(localStorage.getItem(COLLAPSED_KEY) || '[]'))
  } catch {
    return new Set()
  }
}
const collapsedGroups = ref(readCollapsed())
const isExpanded = (key) => !collapsedGroups.value.has(key)
function setCollapsed(next) {
  collapsedGroups.value = next
  try {
    localStorage.setItem(COLLAPSED_KEY, JSON.stringify([...next]))
  } catch {
    // Not persisted; the in-memory state still applies for this visit.
  }
}
function toggleGroup(key) {
  const next = new Set(collapsedGroups.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  setCollapsed(next)
}

/**
 * The group holding the current page. Matched by path prefix rather than by
 * RouterLink's exact match, so a context-only page (/requests/51) still opens
 * the section its list lives in.
 */
const route = useRoute()
const activeGroup = computed(() => {
  const here = route.path
  const screen = screensStore.navItems.find(
    (s) => s.group && (here === s.route || here.startsWith(`${s.route}/`)),
  )
  return screen?.group ?? null
})
// Arriving in a collapsed section opens it, so the current page is never
// hidden; the user can still fold it again while there.
watch(activeGroup, (key) => {
  if (key && collapsedGroups.value.has(key)) toggleGroup(key)
}, { immediate: true })

/**
 * One ordered list of render entries — either a flat `{ type: 'item' }` or
 * a `{ type: 'group' }` — built by walking navItems in sort_order and
 * dropping in each group's block at the position of its FIRST member. That
 * keeps a group sitting exactly where its sort_order puts it instead of
 * being pinned to the top or bottom of the menu, and a grouped screen is
 * never also rendered as a flat entry.
 *
 * A group the user can see only one screen of renders as that plain link: a
 * heading over a single entry costs a row and a click for nothing.
 *
 * `divided` marks where a new section starts. Rail mode (collapsed) has no
 * room for headings, so it draws those as hairlines between icon runs.
 */
const entries = computed(() => {
  const groupsByKey = new Map(screensStore.navGroups.map((g) => [g.key, g.items]))
  const placed = new Set()
  const list = []
  for (const screen of screensStore.navItems) {
    if (!screen.group) {
      list.push({ type: 'item', screen: labelled(screen) })
      continue
    }
    if (placed.has(screen.group)) continue
    placed.add(screen.group)
    const items = groupsByKey.get(screen.group)
    list.push(
      items.length === 1
        ? { type: 'item', screen: labelled(items[0]), grouped: true }
        : { type: 'group', key: screen.group, items: items.map(labelled) },
    )
  }
  return list.map((entry, i) => {
    const isSection = (e) => e.type === 'group' || e.grouped
    return { ...entry, divided: i > 0 && (isSection(entry) || isSection(list[i - 1])) }
  })
})
</script>

<template>
  <aside
    class="sidebar scrollbar-thin"
    :class="{ collapsed, mobile: isMobile, open: mobileOpen }"
  >
    <div class="brand" :class="{ center: collapsed }">
      <span class="mark">أ س</span>
      <div v-if="!collapsed" class="brand-text">
        <strong>{{ t('app.name') }}</strong>
        <small>{{ t('app.subtitle') }}</small>
      </div>
    </div>

    <nav class="nav">
      <p v-if="screensStore.loading" class="state">{{ t('nav.loading') }}</p>

      <p v-else-if="screensStore.error" class="state error">
        {{ t('nav.error') }}
      </p>

      <ul v-else>
        <template v-for="entry in entries" :key="entry.type === 'item' ? entry.screen.code : entry.key">
          <!-- Expanded, a group's heading already starts its section, so only
               a flat entry after a section needs the hairline. -->
          <li
            v-if="entry.divided && (collapsed || entry.type === 'item')"
            class="nav-divider"
            role="separator"
          ></li>
          <!--
            RouterLink applies .router-link-active automatically, which is what
            highlights the current screen without any manual tracking. In rail
            mode the label collapses away, so `title` keeps it discoverable on
            hover.
          -->
          <li v-if="entry.type === 'item'">
            <RouterLink
              :to="entry.screen.route"
              class="nav-link"
              :class="{ center: collapsed }"
              :title="collapsed ? entry.screen.label : null"
            >
              <AppIcon :name="iconFor(entry.screen.code)" class="nav-icon" />
              <span v-if="!collapsed" class="nav-label">{{ entry.screen.label }}</span>
            </RouterLink>
          </li>

          <!-- Rail mode has no room for a heading: the group's icons follow
               its hairline as plain entries. -->
          <template v-else-if="collapsed">
            <li v-for="screen in entry.items" :key="screen.code">
              <RouterLink :to="screen.route" class="nav-link center" :title="screen.label">
                <AppIcon :name="iconFor(screen.code)" class="nav-icon" />
              </RouterLink>
            </li>
          </template>

          <!-- Stage 28 — a collapsible group, e.g. "إدارة الاجتماعات". -->
          <li v-else class="nav-group">
            <button
              type="button"
              class="nav-group-toggle"
              :aria-expanded="isExpanded(entry.key)"
              :aria-controls="`nav-group-${entry.key}`"
              @click="toggleGroup(entry.key)"
            >
              <span class="nav-group-label">{{ t(`nav.groups.${entry.key}`) }}</span>
              <AppIcon
                name="chevron-down"
                class="nav-group-chevron"
                :class="{ collapsed: !isExpanded(entry.key) }"
              />
            </button>
            <ul v-show="isExpanded(entry.key)" :id="`nav-group-${entry.key}`" class="nav-group-items">
              <li v-for="screen in entry.items" :key="screen.code">
                <RouterLink :to="screen.route" class="nav-link">
                  <AppIcon :name="iconFor(screen.code)" class="nav-icon" />
                  <span class="nav-label">{{ screen.label }}</span>
                </RouterLink>
              </li>
            </ul>
          </li>
        </template>
      </ul>
    </nav>
  </aside>
</template>

<style scoped>
/* The literal whites below are deliberate, and the only ones left in a view:
   --color-nav is a dark surface in BOTH themes, so everything drawn on it is a
   translucent white regardless of the page theme. Swapping these for the
   neutral scale would invert them to near-black in dark mode — on a still-dark
   sidebar. */
.sidebar {
  width: 16rem;
  flex-shrink: 0;
  background: var(--color-nav);
  color: rgba(255, 255, 255, 0.7);
  display: flex;
  flex-direction: column;
  overflow-y: auto;
  /* Width animates when toggling between expanded and rail. */
  transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.sidebar.collapsed {
  width: 5rem;
}

/* -- Brand ---------------------------------------------------------------- */
.brand {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  height: 4rem;
  padding: 0 var(--space-4);
  flex-shrink: 0;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}
.brand.center {
  justify-content: center;
  padding: 0;
}
.mark {
  width: 2.5rem;
  height: 2.5rem;
  flex-shrink: 0;
  display: grid;
  place-items: center;
  border-radius: var(--radius-lg);
  background: var(--color-primary);
  color: var(--color-on-primary);
  font-weight: 700;
  font-size: var(--text-sm);
}
.brand-text {
  display: flex;
  flex-direction: column;
  line-height: var(--leading-tight);
  min-width: 0;
}
.brand-text strong {
  font-size: var(--text-lg);
  color: #fff;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.brand-text small {
  font-size: var(--text-xs);
  opacity: 0.55;
}

/* -- Nav ------------------------------------------------------------------ */
.nav {
  flex: 1;
  padding: var(--space-3);
  overflow-y: auto;
}
ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

.nav-link {
  position: relative;
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: 0.55rem var(--space-3);
  border-radius: var(--radius-xl);
  color: rgba(255, 255, 255, 0.7);
  text-decoration: none;
  font-size: var(--text-base);
  font-weight: 500;
  transition: background-color 0.2s ease, color 0.2s ease;
}
.nav-link.center {
  justify-content: center;
}
.nav-icon {
  color: rgba(255, 255, 255, 0.6);
  transition: color 0.2s ease;
}
.nav-label {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  /* Logical: labels read from the start edge — right in Arabic, left in
     English — so the same rule mirrors with dir. */
  text-align: start;
}

.nav-link:hover {
  background: rgba(255, 255, 255, 0.08);
  color: #fff;
}
.nav-link:hover .nav-icon {
  color: #fff;
}

/* Applied by vue-router to the entry matching the current URL. */
.nav-link.router-link-active {
  background: rgba(255, 255, 255, 0.1);
  color: var(--color-primary);
}
.nav-link.router-link-active .nav-icon {
  color: var(--color-primary);
}
/* A start-edge bar, so the current page reads by shape as well as colour. */
.nav-link.router-link-active::before {
  content: '';
  position: absolute;
  inset-block: 25%;
  inset-inline-start: 0;
  inline-size: 3px;
  border-radius: var(--radius-full);
  background: var(--color-primary);
}

/* Where one section of the menu ends and the next begins. */
.nav-divider {
  block-size: 1px;
  margin: var(--space-2) var(--space-3);
  background: rgba(255, 255, 255, 0.1);
}

.state {
  padding: var(--space-3);
  font-size: var(--text-base);
  opacity: 0.75;
  margin: 0;
}
.state.error {
  color: #fecaca;
}

/* -- Stage 28: collapsible group ------------------------------------------ */
.nav-group {
  margin-block-start: var(--space-2);
}
.nav-group-toggle {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  padding: 0.5rem var(--space-3);
  background: none;
  border: none;
  border-radius: var(--radius-xl);
  /* 0.62, not lower: ~4.6:1 on the light theme's nav green. */
  color: rgba(255, 255, 255, 0.62);
  font-size: var(--text-sm);
  font-weight: 600;
  cursor: pointer;
  transition: background-color 0.2s ease, color 0.2s ease;
}
.nav-group-toggle:hover {
  background: rgba(255, 255, 255, 0.06);
  color: #fff;
}
.nav-group-label {
  /* Logical: reads from the start edge, mirrors with dir like .nav-label. */
  text-align: start;
}
.nav-group-chevron {
  flex-shrink: 0;
  /* Pure vertical rotation (down <-> up) — no left/right involved, so this
     needs no RTL-specific override, unlike the mobile drawer's translateX. */
  transition: transform 0.2s ease;
}
.nav-group-chevron.collapsed {
  transform: rotate(-180deg);
}
/* No indent: the heading already says where the section starts, and an
   indent would cost the long labels their width. */
.nav-group-items {
  margin-block: 0.15rem var(--space-1);
}

/* -- Mobile: off-canvas drawer -------------------------------------------
   Below 1024px the sidebar leaves the flow and slides in from the start edge.
   translateX has no logical form, so the RTL direction is handled with an
   explicit html[dir] override (the app sets dir on <html> via i18n). */
@media (max-width: 1023px) {
  .sidebar.mobile {
    position: fixed;
    inset-block: 0;
    inset-inline-start: 0;
    z-index: 50;
    width: 16rem;
    box-shadow: var(--shadow-2xl);
    transform: translateX(-100%);
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  }
  /* Not `:global(html[dir='rtl']) .sidebar.mobile`: Vue compiles that to a bare
     `html[dir='rtl']` and translated the whole document off-screen in Arabic.
     :not(.open) keeps this from outranking the open rule below. */
  :root[dir='rtl'] .sidebar.mobile:not(.open) {
    transform: translateX(100%);
  }
  .sidebar.mobile.open {
    transform: translateX(0);
  }
}
</style>
