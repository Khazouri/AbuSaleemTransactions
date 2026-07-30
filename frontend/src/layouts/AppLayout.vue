<script setup>
/**
 * The application shell every signed-in screen renders inside.
 *
 * Layout: sidebar beside a column of [top bar, scrolling content].
 *
 * This component owns the sidebar's open/closed state and adapts it to the
 * viewport, mirroring the SCCO template's behaviour:
 *   - Desktop (>=1024px): the sidebar is always in view; toggling switches it
 *     between the full 16rem panel and a 5rem icon rail (`collapsed`).
 *   - Mobile (<1024px): the sidebar becomes an off-canvas drawer, hidden by
 *     default and slid in over a dimming overlay when opened.
 *
 * There is no RTL-specific CSS for the main flow — because <html dir> is set by
 * the i18n module, flexbox lays the sidebar out on the right in Arabic and the
 * left in English on its own.
 *
 * Router-wise this is a parent route; each screen renders into the
 * <RouterView> below as a child.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import AppSidebar from '../components/AppSidebar.vue'
import AppTopbar from '../components/AppTopbar.vue'

/** Expanded vs. closed. On desktop "closed" means the rail; on mobile, hidden. */
const isSidebarOpen = ref(true)
const isMobile = ref(false)

/** < 1024px is the drawer breakpoint (matches the template). */
function syncViewport() {
  const mobile = window.innerWidth < 1024
  isMobile.value = mobile
  // Entering mobile auto-closes the drawer so it never covers content on load.
  if (mobile) isSidebarOpen.value = false
}

function toggleSidebar() {
  isSidebarOpen.value = !isSidebarOpen.value
}

/** Rail mode is a desktop-only concept; on mobile a shown sidebar is full width. */
const railCollapsed = computed(() => !isSidebarOpen.value && !isMobile.value)

onMounted(() => {
  syncViewport()
  window.addEventListener('resize', syncViewport)
})
onBeforeUnmount(() => window.removeEventListener('resize', syncViewport))
</script>

<template>
  <div class="shell">
    <AppSidebar
      :collapsed="railCollapsed"
      :is-mobile="isMobile"
      :mobile-open="isMobile && isSidebarOpen"
    />

    <!-- Dimming overlay behind the mobile drawer; tapping it closes the menu. -->
    <div
      v-if="isMobile && isSidebarOpen"
      class="overlay"
      @click="toggleSidebar"
    />

    <div class="main">
      <AppTopbar :collapsed="railCollapsed" @toggle="toggleSidebar" />

      <!-- Only this region scrolls, so the sidebar and top bar stay put. -->
      <main class="content">
        <RouterView />
      </main>
    </div>
  </div>
</template>

<style scoped>
.shell {
  display: flex;
  height: 100vh;
  /* Children scroll internally rather than growing the page. */
  overflow: hidden;
  background: var(--color-background);
}
.main {
  flex: 1;
  display: flex;
  flex-direction: column;
  /* min-width:0 stops a wide table inside the content from forcing the whole
     shell wider than the viewport (a classic flexbox overflow trap). */
  min-width: 0;
}
.content {
  flex: 1;
  overflow-y: auto;
  padding: 1.5rem;
}
.overlay {
  position: fixed;
  inset: 0;
  z-index: 40;
  background: rgba(0, 0, 0, 0.4);
  backdrop-filter: blur(1px);
}
</style>
