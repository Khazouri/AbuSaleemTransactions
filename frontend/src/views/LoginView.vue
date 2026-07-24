<script setup>
/**
 * Login screen (تسجيل الدخول).
 *
 * Stands outside AppLayout — no sidebar or top bar until you're signed in.
 * Collects credentials, hands them to the auth store, and on success sends the
 * user wherever they were originally headed.
 */
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const { t } = useI18n()
const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

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
      error.value = 'تعذّر الاتصال بالخادم. تأكد من تشغيل abusaleem.test'
    }
  }
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
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  display: grid;
  place-items: center;
  background: #f4f6f5;
}
.card {
  width: min(400px, 92vw);
  background: #fff;
  padding: 2rem;
  border-radius: 14px;
  box-shadow: 0 6px 24px rgba(0, 0, 0, .08);
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
h1 { margin: 0; font-size: 1.35rem; color: #0f5132; text-align: center; }
.sub { margin: 0; text-align: center; color: #6b7280; font-size: .9rem; }
label { display: flex; flex-direction: column; gap: .35rem; font-size: .9rem; color: #374151; }
input {
  padding: .6rem .7rem;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 1rem;
}
input:focus { outline: 2px solid #0f5132; outline-offset: 1px; }
button {
  padding: .7rem;
  border: 0;
  border-radius: 8px;
  background: #0f5132;
  color: #fff;
  font-size: 1rem;
  cursor: pointer;
}
button:disabled { opacity: .6; cursor: default; }
.error {
  margin: 0;
  padding: .6rem .7rem;
  background: #fef2f2;
  color: #b91c1c;
  border: 1px solid #fecaca;
  border-radius: 8px;
  font-size: .875rem;
}
</style>
