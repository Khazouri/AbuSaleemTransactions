<script setup>
// The app shell (sidebar, topbar, RTL + i18n) is built in Stage 5.
// For now the router owns the whole viewport.
// The high-risk act confirmation — every caller awaits lib/confirm.js.
import ConfirmHost from './components/ConfirmHost.vue'
</script>

<template>
  <router-view />
  <ConfirmHost />
</template>
