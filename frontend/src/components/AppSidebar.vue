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
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useScreensStore } from '../stores/screens'
import AppIcon from './AppIcon.vue'

defineProps({
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
  transactions: 'file-text',
  transaction_intake: 'file-plus',
  transaction_details: 'file-text',
  meetings: 'calendar',
  decisions: 'check-circle',
  reviewer_approval: 'check-square',
  committee_head_approval: 'check-square',
  admin_manager_approval: 'check-square',
  ministry_approval: 'check-square',
  authority_approval: 'check-square',
  final_approval: 'check-square',
  users: 'users',
  roles_permissions: 'shield',
  settings: 'settings',
  reports: 'bar-chart',
  audit_log: 'history',
  notifications: 'bell',
  templates: 'copy',
  backup: 'database',
  user_guide: 'book',
}
const iconFor = (code) => ICON_BY_CODE[code] || 'dot'

/**
 * Menu entries labelled in the active language.
 *
 * The API sends both names, so switching language relabels the menu instantly
 * with no extra request. Arabic falls back to English if a translation is
 * missing, and vice versa.
 */
const items = computed(() =>
  screensStore.navItems.map((screen) => ({
    ...screen,
    label: locale.value === 'ar'
      ? screen.name_ar || screen.name_en
      : screen.name_en || screen.name_ar,
  })),
)
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
        <li v-for="item in items" :key="item.code">
          <!--
            RouterLink applies .router-link-active automatically, which is what
            highlights the current screen without any manual tracking. In rail
            mode the label collapses away, so `title` keeps it discoverable on
            hover.
          -->
          <RouterLink
            :to="item.route"
            class="nav-link"
            :class="{ center: collapsed }"
            :title="collapsed ? item.label : null"
          >
            <AppIcon :name="iconFor(item.code)" class="nav-icon" />
            <span v-if="!collapsed" class="nav-label">{{ item.label }}</span>
          </RouterLink>
        </li>
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
