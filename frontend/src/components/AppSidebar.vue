<script setup>
/**
 * Sidebar navigation.
 *
 * Entries come from the `screens` table via the API — nothing here is
 * hard-coded, so adding a screen to the database adds it to the menu.
 */
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useScreensStore } from '../stores/screens'

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
  <aside class="sidebar">
    <div class="brand">
      <span class="mark">أ س</span>
      <div class="brand-text">
        <strong>{{ t('app.name') }}</strong>
        <small>{{ t('app.subtitle') }}</small>
      </div>
    </div>

    <nav>
      <p v-if="screensStore.loading" class="state">{{ t('nav.loading') }}</p>

      <p v-else-if="screensStore.error" class="state error">
        {{ t('nav.error') }}
      </p>

      <ul v-else>
        <li v-for="item in items" :key="item.code">
          <!--
            RouterLink applies .router-link-active automatically, which is what
            highlights the current screen without any manual tracking.
          -->
          <RouterLink :to="item.route">{{ item.label }}</RouterLink>
        </li>
      </ul>
    </nav>
  </aside>
</template>

<style scoped>
.sidebar {
  width: 250px;
  flex-shrink: 0;
  background: #0f5132;
  color: #e8f0ec;
  display: flex;
  flex-direction: column;
  overflow-y: auto;
}

.brand {
  display: flex;
  align-items: center;
  gap: .6rem;
  padding: 1.1rem 1rem;
  border-bottom: 1px solid rgba(255, 255, 255, .12);
}
.mark {
  width: 38px;
  height: 38px;
  flex-shrink: 0;
  display: grid;
  place-items: center;
  border-radius: 9px;
  background: #d4af37;
  color: #0f5132;
  font-weight: 700;
  font-size: .8rem;
}
.brand-text { display: flex; flex-direction: column; line-height: 1.25; min-width: 0; }
.brand-text strong { font-size: .95rem; }
.brand-text small { font-size: .72rem; opacity: .75; }

nav { padding: .6rem 0; }
ul { list-style: none; margin: 0; padding: 0; }

a {
  display: block;
  padding: .6rem 1rem;
  color: #cfe0d7;
  text-decoration: none;
  font-size: .9rem;
  /*
    Logical property: this is the "start" edge, which is the RIGHT side in
    Arabic and the LEFT in English. Using border-inline-start means the active
    marker flips sides automatically with dir, with no RTL-specific CSS.
  */
  border-inline-start: 3px solid transparent;
}
a:hover { background: rgba(255, 255, 255, .07); color: #fff; }

/* Applied by vue-router to the entry matching the current URL. */
a.router-link-active {
  background: rgba(255, 255, 255, .12);
  color: #fff;
  border-inline-start-color: #d4af37;
  font-weight: 600;
}

.state { padding: .75rem 1rem; font-size: .85rem; opacity: .8; margin: 0; }
.state.error { color: #fecaca; }
</style>
