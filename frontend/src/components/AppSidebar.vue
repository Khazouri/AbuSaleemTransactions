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
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
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
  request_tracking: 'compass',
  request_details: 'file-text',
  meetings: 'calendar',
  decisions: 'check-circle',
  appeals: 'flag',
  // Stage 28 — meetings management group.
  meetings_dashboard: 'grid',
  committee_candidates: 'file-plus',
  legal_review: 'scale',
  meeting_agenda: 'file-text',
  meeting_readiness: 'check-square',
  meeting_live: 'video',
  meeting_minutes: 'book',
  meeting_outputs: 'bar-chart',
  reviewer_approval: 'check-square',
  committee_head_approval: 'check-square',
  admin_manager_approval: 'check-square',
  ministry_approval: 'check-square',
  final_approval: 'check-square',
  users: 'users',
  request_types: 'layers',
  roles_permissions: 'shield',
  settings: 'settings',
  reports: 'bar-chart',
  // Stage 80 — Art. 98's official registers.
  registers: 'book',
  audit_log: 'history',
  notifications: 'bell',
  templates: 'copy',
  backup: 'database',
  maintenance: 'terminal',
  user_guide: 'book',
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
 */
const collapsedGroups = ref(new Set())
const isExpanded = (key) => !collapsedGroups.value.has(key)
function toggleGroup(key) {
  const next = new Set(collapsedGroups.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  collapsedGroups.value = next
}

/**
 * One ordered list of render entries — either a flat `{ type: 'item' }` or
 * a `{ type: 'group' }` — built by walking navItems in sort_order and
 * dropping in each group's block at the position of its FIRST member. That
 * keeps a group sitting exactly where its sort_order puts it instead of
 * being pinned to the top or bottom of the menu, and a grouped screen is
 * never also rendered as a flat entry.
 *
 * In rail mode (collapsed) there's no room for a group header, so every
 * screen — grouped or not — falls back to one flat icon list, identical to
 * pre-Stage-28 behaviour.
 */
const entries = computed(() => {
  if (props.collapsed) {
    return screensStore.navItems.map((screen) => ({ type: 'item', screen: labelled(screen) }))
  }

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
    list.push({
      type: 'group',
      key: screen.group,
      items: groupsByKey.get(screen.group).map(labelled),
    })
  }
  return list
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

          <!-- Stage 28 — a collapsible group, e.g. "إدارة الاجتماعات". Only
               ever reached when NOT collapsed: rail mode flattens groups. -->
          <li v-else class="nav-group">
            <button
              type="button"
              class="nav-group-toggle"
              :aria-expanded="isExpanded(entry.key)"
              @click="toggleGroup(entry.key)"
            >
              <span class="nav-group-label">{{ t(`nav.groups.${entry.key}`) }}</span>
              <AppIcon
                name="chevron-down"
                class="nav-group-chevron"
                :class="{ collapsed: !isExpanded(entry.key) }"
              />
            </button>
            <ul v-show="isExpanded(entry.key)" class="nav-group-items">
              <li v-for="screen in entry.items" :key="screen.code">
                <RouterLink :to="screen.route" class="nav-link nav-sublink">
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
  gap: 0.7rem;
  height: 4rem;
  padding: 0 1rem;
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
  font-size: 0.8rem;
}
.brand-text {
  display: flex;
  flex-direction: column;
  line-height: 1.25;
  min-width: 0;
}
.brand-text strong {
  font-size: 0.95rem;
  color: #fff;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.brand-text small {
  font-size: 0.72rem;
  opacity: 0.55;
}

/* -- Nav ------------------------------------------------------------------ */
.nav {
  flex: 1;
  padding: 0.75rem;
  overflow-y: auto;
}
ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.nav-link {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.65rem 0.75rem;
  border-radius: var(--radius-xl);
  color: rgba(255, 255, 255, 0.7);
  text-decoration: none;
  font-size: 0.9rem;
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

.state {
  padding: 0.75rem;
  font-size: 0.85rem;
  opacity: 0.75;
  margin: 0;
}
.state.error {
  color: #fecaca;
}

/* -- Stage 28: collapsible group ------------------------------------------ */
.nav-group-toggle {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.65rem 0.75rem;
  background: none;
  border: none;
  border-radius: var(--radius-xl);
  color: rgba(255, 255, 255, 0.55);
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
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
.nav-group-items {
  list-style: none;
  margin: 0.15rem 0 0.25rem;
  padding-inline-start: 0.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}
.nav-sublink {
  padding-inline-start: 1.75rem;
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
  :global(html[dir='rtl']) .sidebar.mobile {
    transform: translateX(100%);
  }
  .sidebar.mobile.open {
    transform: translateX(0);
  }
}
</style>
