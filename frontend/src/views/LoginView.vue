<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const error = ref(null)

async function submit() {
  error.value = null
  try {
    await auth.login(email.value, password.value)
    router.push(route.query.redirect || { name: 'dashboard' })
  } catch (e) {
    const res = e?.response
    if (res?.status === 422) {
      // Laravel validation payload: { errors: { email: [msg] } }
      error.value = Object.values(res.data.errors ?? {}).flat()[0] ?? 'بيانات الدخول غير صحيحة'
    } else if (res?.status === 429) {
      error.value = 'محاولات كثيرة جداً. يرجى الانتظار دقيقة ثم المحاولة مجدداً.'
    } else {
      error.value = 'تعذّر الاتصال بالخادم. تأكد من تشغيل abusaleem.test'
    }
  }
}
</script>

<template>
  <div class="login-page" dir="rtl">
    <form class="card" @submit.prevent="submit">
      <h1>بلدية أبو سليم</h1>
      <p class="sub">نظام إدارة المعاملات — تسجيل الدخول</p>

      <label>
        البريد الإلكتروني
        <input v-model="email" type="email" required autocomplete="username" dir="ltr" />
      </label>

      <label>
        كلمة المرور
        <input v-model="password" type="password" required autocomplete="current-password" dir="ltr" />
      </label>

      <p v-if="error" class="error">{{ error }}</p>

      <button type="submit" :disabled="auth.loading">
        {{ auth.loading ? 'جارٍ الدخول…' : 'تسجيل الدخول' }}
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
  font-family: system-ui, 'Segoe UI', Tahoma, sans-serif;
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
