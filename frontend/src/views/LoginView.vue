<script setup>
/**
 * Login screen (تسجيل الدخول).
 *
 * Stands outside AppLayout — no sidebar or top bar until you're signed in.
 * Collects credentials, hands them to the auth store, and on success sends the
 * user wherever they were originally headed.
 */
import { defineAsyncComponent, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, useRoute } from 'vue-router'
import api from '../lib/api'
import { useAuthStore } from '../stores/auth'

const { t } = useI18n()
const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

// One-click sign-in for the TestUserSeeder accounts. Whether it appears is the
// BACKEND's decision, not this build's: GET /api/dev/test-users answers 404
// unless Laravel is running with APP_ENV=local, so the same dist/ shows the
// panel against a local API and hides it against a real one.
//
// It is loaded asynchronously so the chunk is only fetched once that call has
// said yes — a production visitor never downloads it. The accounts themselves
// come from the response, so no address and no known password is ever compiled
// into the bundle.
const TestUserPicker = defineAsyncComponent(() => import('../components/TestUserPicker.vue'))

// Null until the API confirms a local environment; hides the panel outright.
const testAccounts = ref(null)

onMounted(async () => {
  try {
    testAccounts.value = (await api.get('/dev/test-users')).data
  } catch {
    // A 404 (not local) is the expected answer in production, and an
    // unreachable API already reports itself through the login attempt. Either
    // way the panel simply stays hidden — this is a convenience, never
    // something whose absence deserves an error message.
  }
})

// Form fields, bound with v-model in the template.
const email = ref('')
const password = ref('')

// Single message shown above the button; null hides the box.
const error = ref(null)

async function submit() {
  error.value = null
  try {
    await auth.login(email.value, password.value)

    // The router guard stashes the intended page in ?redirect= when it bounces
    // someone to login, so returning there completes the journey they started.
    router.push(route.query.redirect || { name: 'dashboard' })
  } catch (e) {
    // Translate the failure into something the user can act on. The three
    // cases need genuinely different responses, so they're handled separately
    // rather than shown as one generic "login failed".
    const res = e?.response

    if (res?.status === 422) {
      // Validation/credential failure. Laravel's shape is
      // { errors: { email: ['...'] } } — take the first message.
      error.value = Object.values(res.data.errors ?? {}).flat()[0] ?? 'بيانات الدخول غير صحيحة'
    } else if (res?.status === 429) {
      // Tripped the throttle:6,1 limit on the login route — waiting is the fix.
      error.value = 'محاولات كثيرة جداً. يرجى الانتظار دقيقة ثم المحاولة مجدداً.'
    } else {
      // No response at all: the API is unreachable (VM down, wrong host entry,
      // CORS). Nothing to do with the credentials, so say so.
      //
      // Naming the Homestead host is the right hint on a dev machine and noise
      // on a deployed site, where the reader has no VM to start — so the two
      // audiences get different text. Vite replaces DEV with the literal false
      // in a build, which folds this to the else branch and keeps the internal
      // hostname out of the shipped bundle.
      error.value = import.meta.env.DEV
        ? 'تعذّر الاتصال بالخادم. تأكد من تشغيل abusaleem.test'
        : 'تعذّر الاتصال بالخادم. يرجى المحاولة لاحقاً أو التواصل مع الدعم الفني.'
    }
  }
}

/**
 * Fill the form from the test-account picker and sign in.
 *
 * It fills the real fields and calls the real submit() rather than posting
 * directly, so the shortcut exercises exactly the path a typed login takes —
 * including the error handling above, which is what makes the deliberately
 * inactive test account show its refusal message instead of failing silently.
 */
function signInAs({ email: testEmail }) {
  email.value = testEmail
  password.value = testAccounts.value.password
  return submit()
}
</script>

<template>
  <div class="login-page">
    <form class="card" @submit.prevent="submit">
      <h1>{{ t('app.name') }}</h1>
      <p class="sub">{{ t('app.subtitle') }} — {{ t('auth.login') }}</p>

      <label>
        {{ t('auth.email') }}
        <!-- Email and password are Latin text on an Arabic page, so both
             inputs are forced left-to-right to read correctly. -->
        <input v-model="email" type="email" required autocomplete="username" dir="ltr" />
      </label>

      <label>
        {{ t('auth.password') }}
        <input v-model="password" type="password" required autocomplete="current-password" dir="ltr" />
      </label>

      <p v-if="error" class="error">{{ error }}</p>

      <button type="submit" :disabled="auth.loading">
        {{ auth.loading ? t('auth.loggingIn') : t('auth.login') }}
      </button>
    </form>

    <!-- Renders only when the API answered /dev/test-users, i.e. APP_ENV=local. -->
    <TestUserPicker
      v-if="testAccounts"
      :users="testAccounts.users"
      :password="testAccounts.password"
      :seed-command="testAccounts.seed_command"
      :busy="auth.loading"
      @select="signInAs"
    />
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  padding: 1.5rem 0;
  background: var(--color-background);
}
.card {
  width: min(400px, 92vw);
  background: var(--color-surface);
  padding: 2rem;
  border: 1px solid var(--color-border);
  border-radius: 14px;
  box-shadow: var(--shadow-lg);
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
h1 { margin: 0; font-size: 1.35rem; color: var(--color-brand-text); text-align: center; }
.sub { margin: 0; text-align: center; color: var(--color-muted); font-size: .9rem; }
label { display: flex; flex-direction: column; gap: .35rem; font-size: .9rem; color: var(--color-black-700); }
input {
  padding: .6rem .7rem;
  border: 1px solid var(--color-border-hover);
  border-radius: 8px;
  background: var(--color-surface);
  font-size: 1rem;
}
input:focus { outline: 2px solid var(--color-brand-text); outline-offset: 1px; }
button {
  padding: .7rem;
  border: 0;
  border-radius: 8px;
  background: var(--color-brand);
  color: var(--color-on-brand);
  font-size: 1rem;
  cursor: pointer;
}
button:disabled { opacity: .6; cursor: default; }
.error {
  margin: 0;
  padding: .6rem .7rem;
  background: var(--color-danger-bg);
  color: var(--color-danger-fg);
  border: 1px solid var(--color-danger-border);
  border-radius: 8px;
  font-size: .875rem;
}
</style>
