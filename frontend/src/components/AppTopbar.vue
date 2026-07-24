<script setup>
/**
 * Top bar: current screen title, language toggle, and the user menu.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useScreensStore } from '../stores/screens'
import { applyLocale, SUPPORTED_LOCALES } from '../i18n'

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

async function signOut() {
  await auth.logout()
  // Drop the cached menu too, or the next user to sign in on this browser
  // would briefly see the previous user's screens.
  screensStore.reset()
  router.push({ name: 'login' })
}
</script>

<template>
  <header class="topbar">
    <h1>{{ currentTitle }}</h1>

    <div class="actions">
      <!-- Shows the language you'd switch to, not the current one. -->
      <button class="ghost" :title="t('common.language')" @click="toggleLocale">
        {{ otherLocaleLabel }}
      </button>

      <span class="user">{{ auth.user?.name }}</span>

      <button class="ghost" @click="signOut">{{ t('auth.logout') }}</button>
    </div>
  </header>
</template>

<style scoped>
.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: .75rem 1.25rem;
  background: #fff;
  border-bottom: 1px solid #e5e7eb;
  flex-shrink: 0;
}
h1 { margin: 0; font-size: 1.05rem; color: #111827; }
.actions { display: flex; align-items: center; gap: .6rem; }
.user { font-size: .875rem; color: #6b7280; }
.ghost {
  padding: .35rem .7rem;
  border: 1px solid #d1d5db;
  background: #fff;
  border-radius: 8px;
  font-size: .825rem;
  cursor: pointer;
}
.ghost:hover { background: #f9fafb; }
</style>
