<script setup>
/**
 * Top bar — ported from the SCCO template's Header.
 *
 * Left (start edge): sidebar toggle + current screen title.
 * Right (end edge): language switch, a notifications bell, and a user menu
 * that drops down into Settings / Sign out.
 *
 * The toggle only emits `toggle`; the layout owns the sidebar state. Nothing
 * here is RTL-specific — start/end and flexbox mirror with <html dir>.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useScreensStore } from '../stores/screens'
import { applyLocale, SUPPORTED_LOCALES } from '../i18n'
import AppIcon from './AppIcon.vue'

defineProps({
  /** Rail state, forwarded from the layout — flips the toggle's aria-label. */
  collapsed: { type: Boolean, default: false },
})
defineEmits(['toggle'])

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const screensStore = useScreensStore()

/**
 * Title of the screen being viewed.
 *
 * Resolved by matching the current path against the screens list, so the
 * heading uses the same database-driven name as the sidebar rather than a
 * separate hard-coded title per route.
 */
const currentTitle = computed(() => {
  const screen = screensStore.screens.find((s) => s.route === route.path)
  if (!screen) return ''
  return locale.value === 'ar'
    ? screen.name_ar || screen.name_en
    : screen.name_en || screen.name_ar
})

/** The language we'd switch TO — with only two locales, it's the other one. */
const otherLocale = computed(() => (locale.value === 'ar' ? 'en' : 'ar'))
const otherLocaleLabel = computed(() => SUPPORTED_LOCALES[otherLocale.value].label)

/** Switching locale also flips the page direction — see i18n/index.js. */
function toggleLocale() {
  applyLocale(otherLocale.value)
}

/** Up to two initials from the signed-in user's name, for the avatar chip. */
const initials = computed(() => {
  const name = auth.user?.name?.trim()
  if (!name) return '؟'
  const parts = name.split(/\s+/)
  if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase()
  return name.slice(0, 2).toUpperCase()
})

// -- User dropdown ----------------------------------------------------------
const menuOpen = ref(false)
const menuRef = ref(null)

/** Close the menu when a click lands anywhere outside it. */
function onDocumentClick(event) {
  if (menuRef.value && !menuRef.value.contains(event.target)) {
    menuOpen.value = false
  }
}
onMounted(() => document.addEventListener('click', onDocumentClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick))

function goSettings() {
  menuOpen.value = false
  router.push({ name: 'settings' })
}

async function signOut() {
  menuOpen.value = false
  await auth.logout()
  // Drop the cached menu too, or the next user to sign in on this browser
  // would briefly see the previous user's screens.
  screensStore.reset()
  router.push({ name: 'login' })
}
</script>

<template>
  <header class="topbar">
    <div class="side">
      <button
        class="icon-btn"
        :aria-label="t('nav.menu')"
        @click="$emit('toggle')"
      >
        <AppIcon name="menu" />
      </button>
      <h1 class="title">{{ currentTitle }}</h1>
    </div>

    <div class="side">
      <!-- Shows the language you'd switch to, not the current one. -->
      <button class="icon-btn wide" :title="t('common.language')" @click="toggleLocale">
        <AppIcon name="globe" />
        <span class="lang">{{ otherLocaleLabel }}</span>
      </button>

      <button class="icon-btn" :aria-label="t('nav.notifications')">
        <AppIcon name="bell" />
        <span class="dot" />
      </button>

      <div ref="menuRef" class="user-menu">
        <button class="user-trigger" @click="menuOpen = !menuOpen">
          <span class="avatar">{{ initials }}</span>
          <span class="user-name">{{ auth.user?.name }}</span>
          <AppIcon name="chevron-down" :size="16" class="chev" :class="{ up: menuOpen }" />
        </button>

        <transition name="menu">
          <div v-if="menuOpen" class="dropdown">
            <div class="dropdown-head">
              <span class="avatar lg">{{ initials }}</span>
              <div class="who">
                <strong>{{ auth.user?.name }}</strong>
                <small v-if="auth.user?.email" class="ltr">{{ auth.user.email }}</small>
              </div>
            </div>

            <div class="sep" />

            <button class="dropdown-item" @click="goSettings">
              <AppIcon name="settings" :size="16" />
              <span>{{ t('header.settings') }}</span>
            </button>

            <div class="sep" />

            <button class="dropdown-item danger" @click="signOut">
              <AppIcon name="log-out" :size="16" />
              <span>{{ t('auth.logout') }}</span>
            </button>
          </div>
        </transition>
      </div>
    </div>
  </header>
</template>

<style scoped>
.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  height: 4rem;
  padding: 0 1.25rem;
  background: var(--color-surface);
  border-bottom: 1px solid var(--color-border);
  flex-shrink: 0;
}
.side {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
  color: var(--color-foreground);
}

/* -- Icon buttons --------------------------------------------------------- */
.icon-btn {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.5rem;
  border: none;
  background: transparent;
  color: var(--color-black-700);
  border-radius: var(--radius-lg);
  cursor: pointer;
  transition: background-color 0.2s ease;
}
.icon-btn:hover {
  background: var(--color-black-100);
}
.icon-btn.wide {
  padding-inline: 0.5rem 0.7rem;
}
.lang {
  font-size: 0.8rem;
  font-weight: 500;
}
/* Notification indicator, pinned to the top-end corner of the bell. */
.dot {
  position: absolute;
  top: 0.35rem;
  inset-inline-end: 0.35rem;
  width: 0.5rem;
  height: 0.5rem;
  background: var(--color-red);
  border-radius: var(--radius-full);
  border: 2px solid var(--color-surface);
}

/* -- User menu ------------------------------------------------------------ */
.user-menu {
  position: relative;
}
.user-trigger {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0.5rem;
  border: none;
  background: transparent;
  border-radius: var(--radius-lg);
  cursor: pointer;
  transition: background-color 0.2s ease;
}
.user-trigger:hover {
  background: var(--color-black-100);
}
.avatar {
  width: 2rem;
  height: 2rem;
  flex-shrink: 0;
  display: grid;
  place-items: center;
  border-radius: var(--radius-full);
  background: var(--color-primary);
  color: var(--color-on-primary);
  font-size: 0.8rem;
  font-weight: 600;
  border: 2px solid #fff;
  box-shadow: var(--shadow-sm);
}
.avatar.lg {
  width: 2.5rem;
  height: 2.5rem;
  font-size: 0.9rem;
}
.user-name {
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--color-foreground);
}
.chev {
  color: var(--color-black-400);
  transition: transform 0.2s ease;
}
.chev.up {
  transform: rotate(180deg);
}

/* Hide the name on narrow screens so the trigger stays compact. */
@media (max-width: 640px) {
  .user-name {
    display: none;
  }
}

/* -- Dropdown panel ------------------------------------------------------- */
.dropdown {
  position: absolute;
  top: calc(100% + 0.5rem);
  /* Anchor to the end edge so it opens inward in both LTR and RTL. */
  inset-inline-end: 0;
  min-width: 14rem;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-lg);
  padding: 0.4rem;
  z-index: 50;
}
.dropdown-head {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.5rem;
}
.who {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.who strong {
  font-size: 0.875rem;
  color: var(--color-foreground);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.who small {
  font-size: 0.75rem;
  color: var(--color-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sep {
  height: 1px;
  background: var(--color-border);
  margin: 0.35rem 0;
}
.dropdown-item {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  width: 100%;
  padding: 0.55rem 0.6rem;
  border: none;
  background: transparent;
  border-radius: var(--radius-lg);
  font-size: 0.875rem;
  color: var(--color-black-700);
  cursor: pointer;
  /* Icon then label read from the start edge in both directions. */
  text-align: start;
  transition: background-color 0.2s ease, color 0.2s ease;
}
.dropdown-item:hover {
  background: var(--color-black-100);
}
.dropdown-item.danger {
  color: var(--color-red);
}
.dropdown-item.danger:hover {
  background: rgba(239, 68, 68, 0.08);
}

/* Dropdown open/close animation. */
.menu-enter-active,
.menu-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.menu-enter-from,
.menu-leave-to {
  opacity: 0;
  transform: translateY(-4px) scale(0.98);
}
</style>
