<script setup>
/**
 * Stand-in for screens that exist in the navigation but aren't built yet.
 *
 * Every screen in the `screens` table needs a reachable route, or clicking its
 * sidebar entry would 404. This satisfies that until the real screen arrives
 * in its own stage, and makes the menu genuinely walkable today.
 *
 * The route supplies `meta.screenCode`, which we use to show which screen the
 * user landed on.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useScreensStore } from '../stores/screens'

const { t, locale } = useI18n()
const route = useRoute()
const screensStore = useScreensStore()

/** Display name for this screen, taken from the same DB rows as the menu. */
const title = computed(() => {
  const screen = screensStore.screens.find((s) => s.code === route.meta.screenCode)
  if (!screen) return route.meta.screenCode ?? ''
  return locale.value === 'ar'
    ? screen.name_ar || screen.name_en
    : screen.name_en || screen.name_ar
})
</script>

<template>
  <section class="placeholder">
    <h2>{{ title }}</h2>
    <p class="tag">{{ t('placeholder.title') }}</p>
    <p class="body">{{ t('placeholder.body') }}</p>
    <p class="code">{{ t('placeholder.screen') }}: <code>{{ route.meta.screenCode }}</code></p>
  </section>
</template>

<style scoped>
.placeholder {
  max-width: 560px;
  margin: 2rem auto;
  padding: 2rem;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  text-align: center;
}
h2 { margin: 0 0 .75rem; font-size: 1.15rem; color: #0f5132; }
.tag {
  display: inline-block;
  margin: 0 0 1rem;
  padding: .2rem .7rem;
  background: #fef3c7;
  color: #92400e;
  border-radius: 999px;
  font-size: .78rem;
}
.body { margin: 0 0 1rem; color: #6b7280; font-size: .9rem; }
.code { margin: 0; font-size: .8rem; color: #9ca3af; }
code { direction: ltr; display: inline-block; }
</style>
