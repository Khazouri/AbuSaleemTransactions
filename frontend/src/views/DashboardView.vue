<script setup>
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function signOut() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="page" dir="rtl">
    <header>
      <div>
        <h1>لوحة التحكم</h1>
        <p class="sub">مرحباً، {{ auth.user?.name }}</p>
      </div>
      <button @click="signOut">تسجيل الخروج</button>
    </header>

    <section class="card">
      <h2>بيانات الحساب</h2>
      <dl>
        <div><dt>الاسم</dt><dd>{{ auth.user?.name }}</dd></div>
        <div><dt>البريد الإلكتروني</dt><dd dir="ltr">{{ auth.user?.email }}</dd></div>
        <div>
          <dt>الإدارة</dt>
          <dd>{{ auth.user?.department?.name_ar ?? '—' }}</dd>
        </div>
        <div>
          <dt>الأدوار</dt>
          <dd>
            <span v-for="role in auth.user?.roles ?? []" :key="role.id" class="badge">
              {{ role.code }} — {{ role.name_ar }}
            </span>
          </dd>
        </div>
      </dl>
      <p class="note">
        هذه صفحة مؤقتة — الهيكل الكامل للواجهة (القائمة الجانبية والشاشات) يُبنى في المرحلة 5.
      </p>
    </section>
  </div>
</template>

<style scoped>
.page {
  max-width: 780px;
  margin: 3rem auto;
  padding: 0 1rem;
  font-family: system-ui, 'Segoe UI', Tahoma, sans-serif;
}
header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
h1 { margin: 0; font-size: 1.4rem; color: #0f5132; }
.sub { margin: .25rem 0 0; color: #6b7280; font-size: .9rem; }
button {
  padding: .5rem .9rem;
  border: 1px solid #d1d5db;
  background: #fff;
  border-radius: 8px;
  cursor: pointer;
}
button:hover { background: #f9fafb; }
.card {
  margin-top: 1.5rem;
  padding: 1.5rem;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  background: #fff;
}
h2 { margin: 0 0 1rem; font-size: 1.05rem; }
dl { margin: 0; display: grid; gap: .75rem; }
dl > div { display: grid; grid-template-columns: 130px 1fr; gap: .5rem; align-items: start; }
dt { color: #6b7280; font-size: .875rem; }
dd { margin: 0; }
.badge {
  display: inline-block;
  padding: .2rem .55rem;
  margin-inline-end: .35rem;
  background: #ecfdf5;
  color: #065f46;
  border: 1px solid #a7f3d0;
  border-radius: 999px;
  font-size: .8rem;
}
.note { margin: 1.25rem 0 0; color: #9ca3af; font-size: .825rem; }
</style>
