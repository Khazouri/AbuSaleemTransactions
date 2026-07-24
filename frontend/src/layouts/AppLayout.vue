<script setup>
/**
 * The application shell every signed-in screen renders inside.
 *
 * Layout: sidebar beside a column of [top bar, scrolling content].
 *
 * There is no RTL-specific CSS here. Because <html dir> is set by the i18n
 * module, flexbox lays the sidebar out on the right in Arabic and the left in
 * English on its own — the same rules produce both mirrored layouts.
 *
 * Router-wise this is a parent route; each screen renders into the
 * <RouterView> below as a child.
 */
import AppSidebar from '../components/AppSidebar.vue'
import AppTopbar from '../components/AppTopbar.vue'
</script>

<template>
  <div class="shell">
    <AppSidebar />

    <div class="main">
      <AppTopbar />

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
  padding: 1.25rem;
}
</style>
