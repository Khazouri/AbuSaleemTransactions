<script setup>
/**
 * ERP single sign-on — where the ERP sends the browser back with ?code&state.
 *
 * The query is wiped from the address bar before anything else, so the code
 * never lingers in history or a copied link. takeErpReturn() then refuses any
 * answer this tab did not ask for (login CSRF) before a single request is
 * made; only then is the code handed to the backend.
 */
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { takeErpReturn } from '../lib/erpSso'
import { useAuthStore } from '../stores/auth'

const { t } = useI18n()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const error = ref(null)
const linked = ref(false)

onMounted(async () => {
  const query = { ...route.query }
  await router.replace({ name: 'erp-callback' })

  let erpReturn
  try {
    erpReturn = takeErpReturn(query)
  } catch (e) {
    error.value = t(e.message === 'erp_denied' ? 'erp.denied' : 'erp.stateMismatch')
    return
  }

  try {
    if (erpReturn.intent === 'link') {
      if (!auth.isAuthenticated) {
        error.value = t('erp.linkSignedOut')
        return
      }
      await auth.erpLink(erpReturn.code, erpReturn.verifier)
      linked.value = true
    } else {
      await auth.erpSignIn(erpReturn.code, erpReturn.verifier)
      router.replace({ name: 'dashboard' })
    }
  } catch (e) {
    const res = e?.response
    error.value = res?.status === 422
      ? Object.values(res.data.errors ?? {}).flat()[0] ?? t('erp.failed')
      : t(res?.status === 429 ? 'erp.tooMany' : 'erp.failed')
  }
})
</script>

<template>
  <div class="erp-page">
    <div class="card" role="status" aria-live="polite">
      <h1>{{ t('app.name') }}</h1>

      <template v-if="error">
        <p class="alert">{{ error }}</p>
        <RouterLink class="btn ghost" :to="auth.isAuthenticated ? { name: 'dashboard' } : { name: 'login' }">
          {{ auth.isAuthenticated ? t('erp.backToApp') : t('erp.backToLogin') }}
        </RouterLink>
      </template>

      <template v-else-if="linked">
        <p class="ok">{{ t('erp.linked') }}</p>
        <RouterLink class="btn primary" :to="{ name: 'dashboard' }">{{ t('erp.backToApp') }}</RouterLink>
      </template>

      <p v-else class="muted">{{ t('erp.verifying') }}</p>
    </div>
  </div>
</template>

<style scoped>
.erp-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--space-5) var(--space-4);
  background: var(--color-background);
}
.card {
  inline-size: min(400px, 100%);
  background: var(--color-surface);
  padding: var(--space-6);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-2xl);
  box-shadow: var(--shadow-lg);
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  text-align: center;
}
h1 { margin: 0; font-size: var(--text-xl); color: var(--color-brand-text); }
.muted { margin: 0; color: var(--color-muted); }
.ok {
  margin: 0;
  padding: var(--space-3);
  border: 1px solid var(--color-success-border);
  border-radius: var(--radius-lg);
  background: var(--color-success-bg);
  color: var(--color-success-fg);
}
</style>
